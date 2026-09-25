<?php
/**
 * Linker Class
 *
 * Inserts a link around the first occurrence of a keyword in post content
 * without breaking tags or blocks.
 *
 * @package DragonInternalLinks
 */

namespace DragonInternalLinks;

defined( 'ABSPATH' ) || exit;

/**
 * Block-aware keyword linking.
 *
 * Content is parsed with parse_blocks() so block delimiters (whose JSON can
 * contain the keyword in a caption or alt attribute) are never touched. Each
 * HTML chunk is then tokenised into text, tags, comments, declarations and
 * raw-text element contents, and only text is searched: text inside an open
 * <a> is skipped, and the contents of <script>, <style>, <textarea> and
 * <title> are skipped whole. The matched text is used as the anchor text so
 * the post's own casing is preserved.
 *
 * Traversal state (an open <a>, an open raw-text element) is carried across
 * sibling and nested blocks, because an element can span a block boundary.
 *
 * What is NOT carried is an unterminated comment or declaration: parse_blocks()
 * has already consumed the "-->" of every block delimiter, so a chunk boundary
 * is an artificial cut and carrying that state would suppress the rest of the
 * post over one stray "<!--". An unterminated TAG is different, because the
 * anchor's own quote and ">" would close it early and change how everything
 * after it parses, so a chunk that ends inside a tag stops the traversal and
 * nothing is linked at all.
 */
class Linker {

	/**
	 * Raw-text and escapable-raw-text elements. Their contents are character
	 * data rather than markup, so they are skipped whole: inserting anchor
	 * markup there would put literal tags into script source or a form field.
	 */
	private const RAW_TEXT_TAGS = array( 'script', 'style', 'textarea', 'title' );

	/**
	 * Inline elements whose tags do not separate words: "cat<strong>egory</strong>"
	 * reads as one word, so "cat" must not be linked inside it. Every other tag
	 * (a paragraph, a line break, an image) ends a word.
	 */
	private const INLINE_TAGS = array( 'a', 'abbr', 'b', 'bdi', 'bdo', 'cite', 'code', 'data', 'del', 'dfn', 'em', 'i', 'ins', 'kbd', 'mark', 'q', 's', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'var', 'wbr' );

	/**
	 * Shortcodes whose content is a caption. Captions are never linked, the same
	 * as a block's <figcaption>.
	 */
	private const CAPTION_SHORTCODES = array( 'caption', 'wp_caption' );

	/**
	 * A tag's attribute section, with quoted values (which may hold ">") kept
	 * whole. Used by matchable_text() only.
	 */
	private const TAG_BODY = '(?:[^>"\']|"[^"]*"|\'[^\']*\')*';

	/**
	 * Characters that end an HTML tag name.
	 */
	private const TAG_NAME_END = " \t\n\r\f/>";

	/**
	 * Attribute states of the HTML tag open state, walked to find a tag's ">".
	 */
	private const ATTR_BEFORE_NAME = 0;

	/**
	 * Reading an attribute name.
	 */
	private const ATTR_NAME = 1;

	/**
	 * After an attribute name, where "=" still starts its value.
	 */
	private const ATTR_AFTER_NAME = 2;

	/**
	 * After "=", before the value starts.
	 */
	private const ATTR_BEFORE_VALUE = 3;

	/**
	 * Inside a quoted attribute value, the one state where ">" is not a tag end.
	 */
	private const ATTR_VALUE_QUOTED = 4;

	/**
	 * Inside an unquoted attribute value.
	 */
	private const ATTR_VALUE_PLAIN = 5;

	/**
	 * Just after a quoted attribute value closed.
	 */
	private const ATTR_AFTER_VALUE = 6;

	/**
	 * After a "/" in a tag, where only ">" means anything.
	 */
	private const ATTR_SELF_CLOSING = 7;

	/**
	 * Script data states that decide where a <script> element ends.
	 */
	private const SCRIPT_DATA = 0;

	/**
	 * Script data after a "<!--", where a nested "<script" double-escapes.
	 */
	private const SCRIPT_ESCAPED = 1;

	/**
	 * Double-escaped script data, where "</script>" does not close the element.
	 */
	private const SCRIPT_DOUBLE_ESCAPED = 2;

	/**
	 * Set when PCRE gave up (invalid UTF-8 in the content or keyword, or a
	 * backtrack limit). A null result must never be written back.
	 *
	 * @var bool
	 */
	private bool $regex_failed = false;

	/**
	 * Pattern matching the opening or closing tag of any registered shortcode
	 * ('' when none is registered), built once per insert().
	 *
	 * @var string
	 */
	private string $shortcode_pattern = '';

	/**
	 * Characters that make up a word, for keyword boundaries. PCRE's \b only
	 * knows ASCII letters, so it would find "café" inside "cafés".
	 */
	private const WORD_CHARS = '\p{L}\p{N}\p{M}_';

	/**
	 * Case-insensitive pattern that matches a keyword only as whole words.
	 *
	 * A boundary is required only on a side where the keyword itself starts or
	 * ends with a word character (as \b behaves), so "C++" still matches before
	 * other text. On the left an "&" also blocks the match, so a keyword never
	 * matches inside an entity such as "&amp;". Shared by the analyzer, which
	 * decides what to suggest, so it only suggests what this class can link.
	 *
	 * @param string $keyword Keyword (valid UTF-8).
	 * @return string
	 */
	public static function keyword_pattern( string $keyword ): string {
		$is_word = static fn( string $char ): bool => 1 === preg_match( '/^[' . self::WORD_CHARS . ']$/u', $char );

		$left  = $is_word( mb_substr( $keyword, 0, 1 ) ) ? '(?<![' . self::WORD_CHARS . '&])' : '';
		$right = $is_word( mb_substr( $keyword, -1 ) ) ? '(?![' . self::WORD_CHARS . '])' : '';

		return '/' . $left . self::keyword_body( $keyword ) . $right . '/iu';
	}

	/**
	 * Characters that HTML spells more than one way, each with every spelling
	 * it may have in post content, so a keyword taken from a title matches
	 * "&amp;" for "&" and a curly or encoded apostrophe for "'".
	 */
	private const CHAR_SPELLINGS = array(
		'&' => '(?:&amp;|&#0*38;|&#x0*26;|&)',
		"'" => "(?:'|\u{2019}|\u{2018}|&#0*39;|&#x0*27;|&apos;|&#0*8217;|&#x0*2019;|&rsquo;|&#0*8216;|&#x0*2018;|&lsquo;)",
	);

	/**
	 * The keyword as a pattern body: literal text, with "&" and apostrophes
	 * matching any of their HTML spellings.
	 *
	 * @param string $keyword Keyword (valid UTF-8).
	 * @return string
	 */
	private static function keyword_body( string $keyword ): string {
		$chars = preg_split( '//u', $keyword, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $chars ) ) {
			return preg_quote( $keyword, '/' );
		}

		$body = '';
		foreach ( $chars as $char ) {
			if ( "\u{2019}" === $char || "\u{2018}" === $char ) {
				$char = "'";
			}
			$body .= self::CHAR_SPELLINGS[ $char ] ?? preg_quote( $char, '/' );
		}

		return $body;
	}

	/**
	 * Whether a keyword starts and ends with a word character, i.e. which sides
	 * of it need a word boundary (see keyword_pattern()).
	 *
	 * @param string $keyword Keyword (valid UTF-8).
	 * @return array{0:bool,1:bool} Left, right.
	 */
	private static function keyword_edges( string $keyword ): array {
		return array(
			self::is_word_char( mb_substr( $keyword, 0, 1 ) ),
			self::is_word_char( mb_substr( $keyword, -1 ) ),
		);
	}

	/**
	 * Whether a single character is a word character.
	 *
	 * @param string $char One character (UTF-8).
	 * @return bool
	 */
	private static function is_word_char( string $char ): bool {
		return '' !== $char && 1 === preg_match( '/^[' . self::WORD_CHARS . ']$/u', $char );
	}

	/**
	 * Pattern for the opening or closing tag of a registered shortcode, as the
	 * tag part of get_shortcode_regex() matches it. Its attribute text is never
	 * linked: a link there would break the shortcode.
	 *
	 * @return string '' when no shortcode is registered (an empty alternation
	 *                would match every "[").
	 */
	public static function shortcode_tag_pattern(): string {
		global $shortcode_tags;

		if ( empty( $shortcode_tags ) || ! is_array( $shortcode_tags ) ) {
			return '';
		}

		$names = array_map(
			static fn( $name ): string => preg_quote( (string) $name, '/' ),
			array_keys( $shortcode_tags )
		);

		return '/\[(\/?)(' . implode( '|', $names ) . ')(?![\w-])[^\]]*\]/';
	}

	/**
	 * The post's text as a reader sees it, for the analyzer's suggestion
	 * context and a quick first check. Every occurrence insert() can link is in
	 * this text, but not every occurrence here can be linked (a phrase split by
	 * an inline tag or a line break reads as one here), so only insert() decides
	 * whether a keyword can be applied.
	 *
	 * Captions (<figcaption> and the caption shortcode), shortcode tags,
	 * scripts, styles and comments are left out, inline tags join the text on
	 * either side (so "cat<strong>egory</strong>" reads "category"), and every
	 * other tag separates it.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public static function matchable_text( string $content ): string {
		$steps = array(
			'@<(script|style)\b[^>]*?>.*?</\1>@si' => ' ',
			'@<!--.*?-->@s'                        => ' ',
			'@<figcaption\b.*?</figcaption>@si'    => ' ',
			'/\[(' . implode( '|', self::CAPTION_SHORTCODES ) . ')(?![\w-])[^\]]*\].*?\[\/\1\]/si' => ' ',
		);

		$shortcodes = self::shortcode_tag_pattern();
		if ( '' !== $shortcodes ) {
			$steps[ $shortcodes ] = ' ';
		}

		$steps[ '@</?(?:' . implode( '|', self::INLINE_TAGS ) . ')(?![\w-])' . self::TAG_BODY . '>@i' ] = '';
		$steps[ '@</?[a-zA-Z]' . self::TAG_BODY . '>@' ] = ' ';

		$text = $content;
		foreach ( $steps as $pattern => $replacement ) {
			$next = preg_replace( $pattern, $replacement, $text );
			if ( ! is_string( $next ) ) {
				return wp_strip_all_tags( $content );
			}
			$text = $next;
		}

		$text = wp_strip_all_tags( $text );
		$text = preg_replace( '/[ \t\r\n]+/', ' ', $text );

		return is_string( $text ) ? trim( $text ) : '';
	}

	/**
	 * Whether the last insert() failed because a regex operation did.
	 *
	 * @return bool
	 */
	public function regex_failed(): bool {
		return $this->regex_failed;
	}

	/**
	 * Link the first text occurrence of a keyword.
	 *
	 * @param string $content Post content (block or classic HTML).
	 * @param string $keyword Keyword to link (matched case-insensitively).
	 * @param string $url     Link target.
	 * @return string|null New content, or null if the keyword was not found
	 *                     or a regex operation failed (see regex_failed()).
	 */
	public function insert( string $content, string $keyword, string $url ): ?string {
		$this->regex_failed = false;

		if ( '' === trim( $keyword ) ) {
			return null;
		}

		// A keyword that is not valid UTF-8 cannot be compiled into a /u pattern.
		if ( 1 !== preg_match( '//u', $keyword ) ) {
			$this->regex_failed = true;
			return null;
		}

		$done                    = false;
		$state                   = $this->new_state();
		$blocks                  = parse_blocks( $content );
		$this->shortcode_pattern = self::shortcode_tag_pattern();

		foreach ( $blocks as $i => $block ) {
			$blocks[ $i ] = $this->walk_block( $block, $keyword, $url, $done, $state );
			if ( $done || $this->regex_failed ) {
				break;
			}
		}

		if ( $this->regex_failed || ! $done ) {
			return null;
		}

		return serialize_blocks( $blocks );
	}

	/**
	 * Persist new content through the post API (keeps revisions intact).
	 *
	 * The post API expects slashed input and unslashes it before writing;
	 * serialize_blocks() output carries JSON escapes (\u002d, \/) that would
	 * otherwise lose their backslashes and invalidate every block.
	 *
	 * The content was already filtered when the post was originally saved and
	 * the only change is one link whose URL passed esc_url(), so kses is lifted
	 * for this one write: otherwise a save by a user without unfiltered_html
	 * (multisite administrators, or DISALLOW_UNFILTERED_HTML sites) would
	 * silently strip iframes, embeds and scripts from every other block in the
	 * post.
	 *
	 * @param int    $post_id Post id.
	 * @param string $content New content.
	 * @return bool True if the post was written.
	 */
	public function save( int $post_id, string $content ): bool {
		$kses_active = (bool) has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $kses_active ) {
			kses_remove_filters();
		}
		try {
			$result = wp_update_post(
				wp_slash(
					array(
						'ID'           => $post_id,
						'post_content' => $content,
					)
				),
				true
			);
		} finally {
			if ( $kses_active ) {
				kses_init_filters();
			}
		}
		return ! is_wp_error( $result ) && 0 !== (int) $result;
	}

	/**
	 * Fresh traversal state.
	 *
	 * "anchor" is the depth of open <a> elements (text is only linkable at
	 * depth 0), "raw" is the name of the open raw-text element, if any,
	 * "broken" is set once a chunk has ended inside a tag, after which nothing
	 * is linkable, "caption" is the depth of open captions (<figcaption> or the
	 * caption shortcode), whose text is never linked, and "glue" is whether the
	 * text so far ends in a word character with only inline tags since, so the
	 * next text run continues that word.
	 *
	 * @return array{anchor:int,raw:string,broken:bool,caption:int,glue:bool}
	 */
	private function new_state(): array {
		return array(
			'anchor'  => 0,
			'raw'     => '',
			'broken'  => false,
			'caption' => 0,
			'glue'    => false,
		);
	}

	/**
	 * Walk one block in document order: its HTML chunks and inner blocks in
	 * the order they appear. innerContent is what serialize_blocks() writes;
	 * innerHTML mirrors the block's own chunks and is updated to match when
	 * the link lands in this block.
	 *
	 * @param array<string,mixed> $block   Parsed block.
	 * @param string              $keyword Keyword.
	 * @param string              $url     Link target.
	 * @param bool                $done    Set once the link has been inserted (by reference).
	 * @param array<string,mixed> $state   Traversal state from new_state(), shared with the sibling and parent blocks (by reference).
	 * @return array<string,mixed>
	 */
	private function walk_block( array $block, string $keyword, string $url, bool &$done, array &$state ): array {
		if ( empty( $block['innerContent'] ) || ! is_array( $block['innerContent'] ) ) {
			return $block;
		}

		$index       = 0;
		$own_hit     = false;
		$entry_state = $state;

		foreach ( $block['innerContent'] as $i => $chunk ) {
			if ( is_string( $chunk ) ) {
				$block['innerContent'][ $i ] = $this->link_in_html( $chunk, $keyword, $url, $done, $state );
				if ( $done ) {
					$own_hit = true;
				}
			} else {
				if ( isset( $block['innerBlocks'][ $index ] ) && is_array( $block['innerBlocks'][ $index ] ) ) {
					$block['innerBlocks'][ $index ] = $this->walk_block( $block['innerBlocks'][ $index ], $keyword, $url, $done, $state );
				}
				++$index;
			}

			if ( $done || $this->regex_failed ) {
				break;
			}
		}

		if ( $own_hit && isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
			$this->mirror_inner_html( $block, $keyword, $url, $entry_state );
		}

		return $block;
	}

	/**
	 * Re-run the scan over the block's own innerHTML so it matches the chunks
	 * in innerContent.
	 *
	 * innerHTML is not what serialize_block() emits, so this pass must not be
	 * able to change the outcome already recorded in innerContent: it starts
	 * from the state the block was entered with, and a PCRE failure here is
	 * discarded rather than vetoing a replacement that already landed.
	 *
	 * @param array<string,mixed> $block       Parsed block (by reference).
	 * @param string              $keyword     Keyword.
	 * @param string              $url         Link target.
	 * @param array<string,mixed> $entry_state Traversal state on entry to the block.
	 */
	private function mirror_inner_html( array &$block, string $keyword, string $url, array $entry_state ): void {
		$mirrored = false;
		$failed   = $this->regex_failed;
		$html     = $this->link_in_html( (string) $block['innerHTML'], $keyword, $url, $mirrored, $entry_state );

		$this->regex_failed = $failed;

		if ( $mirrored ) {
			$block['innerHTML'] = $html;
		}
	}

	/**
	 * Link the first keyword occurrence that lies in a text node of an HTML
	 * chunk.
	 *
	 * The chunk is tokenised rather than split on tags, so a ">" inside a
	 * comment, a "<" that is ordinary text, and markup-shaped character data
	 * inside a raw-text element cannot desynchronise the scan. The replacement
	 * is spliced into the original string at the offset it was found, so a
	 * chunk with no replacement comes back byte for byte.
	 *
	 * @param string              $html    HTML chunk.
	 * @param string              $keyword Keyword.
	 * @param string              $url     Link target.
	 * @param bool                $done    Set true when a replacement was made (by reference).
	 * @param array<string,mixed> $state   Traversal state (by reference).
	 * @return string
	 */
	private function link_in_html( string $html, string $keyword, string $url, bool &$done, array &$state ): string {
		$length     = strlen( $html );
		$pattern    = self::keyword_pattern( $keyword );
		$edges      = self::keyword_edges( $keyword );
		$offset     = 0;
		$text_start = 0;

		while ( $offset < $length ) {
			if ( '' !== $state['raw'] ) {
				$close = $this->raw_text_close( $html, $offset, $state['raw'] );
				if ( null === $close ) {
					// The element is still open at the end of the chunk: the
					// rest is character data, and it carries on into the next.
					return $html;
				}
				// The state is cleared before the offset moves, so a close tag
				// sitting at the current offset cannot loop.
				$state['raw']  = '';
				$state['glue'] = false;
				$offset        = $close;
				$text_start    = $close;
				continue;
			}

			$next_lt = $this->str_find( $html, '<', $offset );
			if ( null === $next_lt ) {
				break;
			}

			$token = $this->markup_token( $html, $next_lt );
			if ( null === $token ) {
				// A "<" that does not start markup is ordinary text, so the
				// text run continues through it.
				$offset = $next_lt + 1;
				continue;
			}

			$linked = $this->link_in_text( $html, $text_start, $next_lt, $state, $pattern, $edges, $url, $done );
			if ( null !== $linked ) {
				return $linked;
			}
			if ( $this->regex_failed ) {
				return $html;
			}

			$this->apply_token( $token, $state );
			$offset     = $token['end'];
			$text_start = $token['end'];
		}

		$linked = $this->link_in_text( $html, $text_start, $length, $state, $pattern, $edges, $url, $done );

		return null === $linked ? $html : $linked;
	}

	/**
	 * Replace the first linkable keyword occurrence inside one text run.
	 *
	 * A match is linkable when it lies outside shortcode tags and captions, and
	 * does not continue a word across an inline tag on either side (the
	 * pattern's own boundaries only see this run). The run also updates the
	 * caption and glue state for the runs after it.
	 *
	 * @param string              $html    Whole chunk.
	 * @param int                 $start   Start offset of the text run.
	 * @param int                 $end     End offset of the text run, exclusive.
	 * @param array<string,mixed> $state   Traversal state (by reference).
	 * @param string              $pattern Compiled keyword pattern.
	 * @param array{0:bool,1:bool} $edges  Whether the keyword starts / ends with a word character.
	 * @param string              $url     Link target.
	 * @param bool                $done    Set true when a replacement was made (by reference).
	 * @return string|null The chunk with the link spliced in, or null when this
	 *                     run held no match or PCRE failed (see regex_failed()).
	 */
	private function link_in_text( string $html, int $start, int $end, array &$state, string $pattern, array $edges, string $url, bool &$done ): ?string {
		if ( $end <= $start ) {
			return null;
		}

		$text      = substr( $html, $start, $end - $start );
		$glue_in   = $state['glue'];
		$linkable  = $this->linkable_spans( $text, $state );
		$ends_word = 1 === preg_match( '/[' . self::WORD_CHARS . ']\z/u', $text );

		$state['glue'] = $ends_word;

		if ( null === $linkable ) {
			return null;
		}

		if ( array() === $linkable || $state['anchor'] > 0 || $state['broken'] ) {
			return null;
		}

		if ( false === preg_match_all( $pattern, $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			$this->regex_failed = true;
			return null;
		}

		foreach ( $matches[0] as $match ) {
			$from = (int) $match[1];
			$to   = $from + strlen( $match[0] );

			if ( ! self::within_spans( $from, $to, $linkable ) ) {
				continue;
			}

			// The word carries on from the text before an inline tag.
			if ( 0 === $from && $edges[0] && $glue_in ) {
				continue;
			}

			// The word carries on into the text after an inline tag.
			if ( strlen( $text ) === $to && $edges[1] && $this->word_follows( $html, $end ) ) {
				continue;
			}

			$done = true;

			return substr( $html, 0, $start + $from )
				. '<a href="' . esc_url( $url ) . '">' . $match[0] . '</a>'
				. substr( $html, $start + $to );
		}

		return null;
	}

	/**
	 * The parts of a text run that may be linked: outside shortcode tags and
	 * outside captions. Updates the caption depth as caption shortcodes open
	 * and close.
	 *
	 * @param string              $text  Text run.
	 * @param array<string,mixed> $state Traversal state (by reference).
	 * @return array<int,array{0:int,1:int}>|null [from, to) offsets, or null when PCRE failed.
	 */
	private function linkable_spans( string $text, array &$state ): ?array {
		$tags = array();

		if ( '' !== $this->shortcode_pattern ) {
			if ( false === preg_match_all( $this->shortcode_pattern, $text, $found, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
				$this->regex_failed = true;
				return null;
			}
			$tags = $found;
		}

		$spans  = array();
		$cursor = 0;

		foreach ( $tags as $tag ) {
			$at = (int) $tag[0][1];
			if ( 0 === $state['caption'] && $at > $cursor ) {
				$spans[] = array( $cursor, $at );
			}

			if ( in_array( strtolower( $tag[2][0] ), self::CAPTION_SHORTCODES, true ) ) {
				if ( '/' === $tag[1][0] ) {
					$state['caption'] = max( 0, $state['caption'] - 1 );
				} else {
					++$state['caption'];
				}
			}

			$cursor = $at + strlen( $tag[0][0] );
		}

		if ( 0 === $state['caption'] && strlen( $text ) > $cursor ) {
			$spans[] = array( $cursor, strlen( $text ) );
		}

		return $spans;
	}

	/**
	 * Whether [from, to) lies inside one of the spans.
	 *
	 * @param int                           $from  Start offset.
	 * @param int                           $to    End offset, exclusive.
	 * @param array<int,array{0:int,1:int}> $spans Spans.
	 * @return bool
	 */
	private static function within_spans( int $from, int $to, array $spans ): bool {
		foreach ( $spans as $span ) {
			if ( $from >= $span[0] && $to <= $span[1] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the text that follows an offset, past any inline tags and
	 * comments, starts with a word character.
	 *
	 * @param string $html Chunk.
	 * @param int    $at   Offset just past a text run.
	 * @return bool
	 */
	private function word_follows( string $html, int $at ): bool {
		$length = strlen( $html );

		while ( $at < $length && '<' === $html[ $at ] ) {
			$token = $this->markup_token( $html, $at );
			if ( null === $token ) {
				// A "<" that is ordinary text, not a word character.
				return false;
			}
			if ( 'other' !== $token['kind'] && ! ( 'tag' === $token['kind'] && in_array( $token['tag'], self::INLINE_TAGS, true ) ) ) {
				return false;
			}
			$at = $token['end'];
		}

		if ( $at >= $length ) {
			return false;
		}

		return 1 === preg_match( '/^[' . self::WORD_CHARS . ']/u', substr( $html, $at, 4 ) );
	}

	/**
	 * Read the markup token that starts at a "<".
	 *
	 * @param string $html Chunk.
	 * @param int    $pos  Offset of the "<".
	 * @return array{kind:string,end:int,tag:string,closing:bool}|null Null when
	 *         the "<" does not start markup and belongs to the text run.
	 */
	private function markup_token( string $html, int $pos ): ?array {
		$length = strlen( $html );
		$next   = $pos + 1 < $length ? $html[ $pos + 1 ] : '';

		if ( '!' === $next ) {
			// A comment ends at "-->", not at the first ">".
			if ( '<!--' === substr( $html, $pos, 4 ) ) {
				return $this->comment_token( $html, $pos );
			}
			// <!DOCTYPE ...>, <![CDATA[ ... ]]> and other bogus comments end at
			// the first ">", as the HTML bogus comment state does.
			return $this->bounded_token( $html, $this->str_find( $html, '>', $pos + 2 ), 1 );
		}

		if ( '?' === $next ) {
			return $this->bounded_token( $html, $this->str_find( $html, '>', $pos + 2 ), 1 );
		}

		$closing = '/' === $next;
		$name_at = $closing ? $pos + 2 : $pos + 1;
		$initial = $name_at < $length ? $html[ $name_at ] : '';

		if ( ! ctype_alpha( $initial ) ) {
			// "</" not followed by a letter is a bogus comment; any other "<"
			// is ordinary text.
			if ( $closing ) {
				return $this->bounded_token( $html, $this->str_find( $html, '>', $name_at ), 1 );
			}
			return null;
		}

		$name_length = strcspn( $html, self::TAG_NAME_END, $name_at );
		$end         = $this->tag_end( $html, $name_at + $name_length );

		if ( null === $end ) {
			// The tag is not closed in this chunk: consume the rest of it. Its
			// name cannot be trusted, and nothing after it is linkable either.
			return array(
				'kind'    => 'unterminated-tag',
				'end'     => $length,
				'tag'     => '',
				'closing' => false,
			);
		}

		return array(
			'kind'    => 'tag',
			'end'     => $end,
			'tag'     => strtolower( substr( $html, $name_at, $name_length ) ),
			'closing' => $closing,
		);
	}

	/**
	 * Offset just past the ">" that ends a tag whose name has already been read.
	 *
	 * This walks the HTML attribute states rather than pairing quotes off
	 * against each other, because the two disagree on malformed markup: once a
	 * quoted value has closed, a further quote belongs to an attribute NAME and
	 * opens nothing. Pairing would shift every later quote by one and end the
	 * tag at a ">" that really sits inside a subsequent attribute value, which
	 * puts the keyword after it inside that value.
	 *
	 * @param string $html Chunk.
	 * @param int    $from Offset just past the tag name.
	 * @return int|null Null when the tag is not closed in this chunk.
	 */
	private function tag_end( string $html, int $from ): ?int {
		$length = strlen( $html );
		$at     = max( 0, min( $from, $length ) );
		$state  = self::ATTR_BEFORE_NAME;
		$quote  = '';

		while ( $at < $length ) {
			$char  = $html[ $at ];
			$space = in_array( $char, array( ' ', "\t", "\n", "\r", "\f" ), true );
			$was   = array( $state, $at );

			// Outside a quoted value, ">" ends the tag from every state.
			if ( '>' === $char && self::ATTR_VALUE_QUOTED !== $state ) {
				return $at + 1;
			}

			if ( self::ATTR_VALUE_QUOTED === $state ) {
				if ( $char === $quote ) {
					$state = self::ATTR_AFTER_VALUE;
				}
				++$at;
			} elseif ( self::ATTR_BEFORE_NAME === $state ) {
				if ( $space ) {
					++$at;
				} elseif ( '/' === $char ) {
					$state = self::ATTR_SELF_CLOSING;
					++$at;
				} else {
					$state = self::ATTR_NAME;
					++$at;
				}
			} elseif ( self::ATTR_NAME === $state ) {
				if ( $space ) {
					$state = self::ATTR_AFTER_NAME;
					++$at;
				} elseif ( '=' === $char ) {
					$state = self::ATTR_BEFORE_VALUE;
					++$at;
				} elseif ( '/' === $char ) {
					$state = self::ATTR_AFTER_NAME;
				} else {
					++$at;
				}
			} elseif ( self::ATTR_AFTER_NAME === $state ) {
				if ( $space ) {
					++$at;
				} elseif ( '/' === $char ) {
					$state = self::ATTR_SELF_CLOSING;
					++$at;
				} elseif ( '=' === $char ) {
					$state = self::ATTR_BEFORE_VALUE;
					++$at;
				} else {
					$state = self::ATTR_NAME;
				}
			} elseif ( self::ATTR_BEFORE_VALUE === $state ) {
				if ( $space ) {
					++$at;
				} elseif ( '"' === $char || "'" === $char ) {
					$quote = $char;
					$state = self::ATTR_VALUE_QUOTED;
					++$at;
				} else {
					$state = self::ATTR_VALUE_PLAIN;
				}
			} elseif ( self::ATTR_VALUE_PLAIN === $state ) {
				if ( $space ) {
					$state = self::ATTR_BEFORE_NAME;
				}
				++$at;
			} elseif ( self::ATTR_AFTER_VALUE === $state ) {
				if ( $space ) {
					$state = self::ATTR_BEFORE_NAME;
					++$at;
				} elseif ( '/' === $char ) {
					$state = self::ATTR_SELF_CLOSING;
					++$at;
				} else {
					$state = self::ATTR_BEFORE_NAME;
				}
			} else {
				$state = self::ATTR_BEFORE_NAME;
			}

			if ( array( $state, $at ) === $was ) {
				// Unreachable: every branch advances or changes state. The guard
				// keeps a later edit from looping on malformed markup.
				return null;
			}
		}

		return null;
	}

	/**
	 * Read the comment token that starts at "<!--".
	 *
	 * A comment ends at "-->", not at the first ">". It can also end at "--!>",
	 * and "<!-->" and "<!--->" are complete comments in their own right, so
	 * searching only for "-->" would swallow everything up to the next comment
	 * and hide the elements in between.
	 *
	 * @param string $html Chunk.
	 * @param int    $pos  Offset of the "<".
	 * @return array{kind:string,end:int,tag:string,closing:bool}
	 */
	private function comment_token( string $html, int $pos ): array {
		$length = strlen( $html );
		$body   = $pos + 4;

		if ( $body < $length && '>' === $html[ $body ] ) {
			return $this->bounded_token( $html, $body, 1 );
		}

		if ( $body + 1 < $length && '-' === $html[ $body ] && '>' === $html[ $body + 1 ] ) {
			return $this->bounded_token( $html, $body, 2 );
		}

		$dashes = $this->str_find( $html, '-->', $body );
		$bang   = $this->str_find( $html, '--!>', $body );

		if ( null === $dashes || ( null !== $bang && $bang < $dashes ) ) {
			return $this->bounded_token( $html, $bang, 4 );
		}

		return $this->bounded_token( $html, $dashes, 3 );
	}

	/**
	 * Token that runs to a literal terminator, or to the end of the chunk when
	 * the terminator is missing. An unterminated comment or declaration is
	 * confined to its own chunk: parse_blocks() has already consumed the "-->"
	 * of every block delimiter, so each chunk boundary is an artificial cut and
	 * carrying the state would suppress the rest of the post.
	 *
	 * @param string   $html      Chunk.
	 * @param int|null $close     Offset of the terminator, or null if absent.
	 * @param int      $close_len Length of the terminator.
	 * @return array{kind:string,end:int,tag:string,closing:bool}
	 */
	private function bounded_token( string $html, ?int $close, int $close_len ): array {
		return array(
			'kind'    => 'other',
			'end'     => null === $close ? strlen( $html ) : $close + $close_len,
			'tag'     => '',
			'closing' => false,
		);
	}

	/**
	 * Apply a tag token's effect on the traversal state.
	 *
	 * @param array<string,mixed> $token Token from markup_token().
	 * @param array<string,mixed> $state Traversal state (by reference).
	 */
	private function apply_token( array $token, array &$state ): void {
		if ( 'unterminated-tag' === $token['kind'] ) {
			// A tag left open at a chunk boundary means a block delimiter sits
			// inside it: a browser never saw that delimiter, so to it the rest
			// of the post is still inside this tag and none of it is text.
			// Linking there would close the tag early and change how everything
			// after it parses, so the traversal stops instead.
			$state['broken'] = true;
			return;
		}

		if ( 'tag' !== $token['kind'] ) {
			return;
		}

		$tag = $token['tag'];

		// Only inline formatting keeps a word going across a tag.
		if ( ! in_array( $tag, self::INLINE_TAGS, true ) ) {
			$state['glue'] = false;
		}

		if ( $token['closing'] ) {
			// Clamped at zero so a stray "</a>" cannot make later text inside a
			// real anchor look linkable.
			if ( 'a' === $tag && $state['anchor'] > 0 ) {
				--$state['anchor'];
			}
			if ( 'figcaption' === $tag && $state['caption'] > 0 ) {
				--$state['caption'];
			}
			return;
		}

		if ( 'figcaption' === $tag ) {
			++$state['caption'];
			return;
		}

		// Every opening tag counts: HTML has no self-closing <a/>, and an
		// unquoted href ending in "/" would look like one.
		if ( 'a' === $tag ) {
			++$state['anchor'];
			return;
		}

		if ( in_array( $tag, self::RAW_TEXT_TAGS, true ) ) {
			$state['raw'] = $tag;
		}
	}

	/**
	 * Offset of the "<" that closes an open raw-text element.
	 *
	 * @param string $html Chunk.
	 * @param int    $from Offset to search from.
	 * @param string $tag  Name of the open raw-text element.
	 * @return int|null Null when the element is not closed in this chunk.
	 */
	private function raw_text_close( string $html, int $from, string $tag ): ?int {
		if ( 'script' === $tag ) {
			return $this->script_close( $html, $from );
		}

		$needle = '</' . $tag;
		$length = strlen( $html );
		$at     = max( 0, min( $from, $length ) );

		while ( $at <= $length ) {
			$found = stripos( $html, $needle, $at );
			if ( false === $found ) {
				return null;
			}

			$after = $found + strlen( $needle );
			$next  = $after < $length ? $html[ $after ] : '';

			// The name has to end at a tag-name boundary, so "</scriptx" stays
			// character data.
			if ( '' === $next || in_array( $next, array( '>', '/', ' ', "\t", "\n", "\r", "\f" ), true ) ) {
				return $found;
			}

			$at = $found + 1;
		}

		return null;
	}

	/**
	 * Offset of the "<" that closes an open <script>, following the HTML script
	 * data states.
	 *
	 * Inside script data "<!--" opens an escaped run, and a nested "<script" in
	 * that run opens a double-escaped run where "</script>" only ends the double
	 * escape and leaves the element open. Taking the first "</script>" as the
	 * close would put anchor markup into the middle of script source, which is
	 * what document.write("<script></script>...") in a commented-out script
	 * produces.
	 *
	 * @param string $html Chunk.
	 * @param int    $from Offset to search from.
	 * @return int|null Null when the element is not closed in this chunk.
	 */
	private function script_close( string $html, int $from ): ?int {
		$length = strlen( $html );
		$at     = max( 0, min( $from, $length ) );
		$escape = self::SCRIPT_DATA;

		while ( $at < $length ) {
			if ( self::SCRIPT_DATA !== $escape && '-->' === substr( $html, $at, 3 ) ) {
				$escape = self::SCRIPT_DATA;
				$at    += 3;
				continue;
			}

			if ( '<' === $html[ $at ] ) {
				if ( $this->is_script_tag_at( $html, $at, true ) ) {
					if ( self::SCRIPT_DOUBLE_ESCAPED === $escape ) {
						$escape = self::SCRIPT_ESCAPED;
						$at    += 8;
						continue;
					}
					return $at;
				}

				if ( self::SCRIPT_DATA === $escape && '<!--' === substr( $html, $at, 4 ) ) {
					$escape = self::SCRIPT_ESCAPED;
					$at    += 4;
					continue;
				}

				if ( self::SCRIPT_ESCAPED === $escape && $this->is_script_tag_at( $html, $at, false ) ) {
					$escape = self::SCRIPT_DOUBLE_ESCAPED;
					$at    += 7;
					continue;
				}
			}

			++$at;
		}

		return null;
	}

	/**
	 * Whether a "script" tag name, opening or closing, starts at this offset and
	 * ends at a tag-name boundary.
	 *
	 * @param string $html    Chunk.
	 * @param int    $at      Offset of the "<".
	 * @param bool   $closing Look for "</script" rather than "<script".
	 * @return bool
	 */
	private function is_script_tag_at( string $html, int $at, bool $closing ): bool {
		$needle = $closing ? '</script' : '<script';

		if ( strtolower( substr( $html, $at, strlen( $needle ) ) ) !== $needle ) {
			return false;
		}

		$after = $at + strlen( $needle );
		$next  = $after < strlen( $html ) ? $html[ $after ] : '';

		return '' === $next || in_array( $next, array( '>', '/', ' ', "\t", "\n", "\r", "\f" ), true );
	}

	/**
	 * strpos() with the offset clamped into the string and false normalised to
	 * null, so no offset arithmetic can raise a ValueError while rewriting a
	 * post.
	 *
	 * @param string $haystack Subject.
	 * @param string $needle   Search string.
	 * @param int    $from     Offset to search from.
	 * @return int|null
	 */
	private function str_find( string $haystack, string $needle, int $from ): ?int {
		$at = strpos( $haystack, $needle, max( 0, min( $from, strlen( $haystack ) ) ) );

		return false === $at ? null : $at;
	}
}
