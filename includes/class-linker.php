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

		$done   = false;
		$state  = $this->new_state();
		$blocks = parse_blocks( $content );

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
	 * depth 0), "raw" is the name of the open raw-text element, if any, and
	 * "broken" is set once a chunk has ended inside a tag, after which nothing
	 * is linkable.
	 *
	 * @return array{anchor:int,raw:string,broken:bool}
	 */
	private function new_state(): array {
		return array(
			'anchor' => 0,
			'raw'    => '',
			'broken' => false,
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
		$pattern    = '/' . preg_quote( $keyword, '/' ) . '/iu';
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
				$state['raw'] = '';
				$offset       = $close;
				$text_start   = $close;
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

			$linked = $this->link_in_text( $html, $text_start, $next_lt, $state, $pattern, $url, $done );
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

		$linked = $this->link_in_text( $html, $text_start, $length, $state, $pattern, $url, $done );

		return null === $linked ? $html : $linked;
	}

	/**
	 * Replace the first keyword occurrence inside one text run.
	 *
	 * @param string              $html    Whole chunk.
	 * @param int                 $start   Start offset of the text run.
	 * @param int                 $end     End offset of the text run, exclusive.
	 * @param array<string,mixed> $state   Traversal state.
	 * @param string              $pattern Compiled keyword pattern.
	 * @param string              $url     Link target.
	 * @param bool                $done    Set true when a replacement was made (by reference).
	 * @return string|null The chunk with the link spliced in, or null when this
	 *                     run held no match or PCRE failed (see regex_failed()).
	 */
	private function link_in_text( string $html, int $start, int $end, array $state, string $pattern, string $url, bool &$done ): ?string {
		if ( $end <= $start || $state['anchor'] > 0 || $state['broken'] ) {
			return null;
		}

		$replaced = preg_replace_callback(
			$pattern,
			static function ( array $matches ) use ( $url ): string {
				return '<a href="' . esc_url( $url ) . '">' . $matches[0] . '</a>';
			},
			substr( $html, $start, $end - $start ),
			1,
			$count
		);

		if ( ! is_string( $replaced ) ) {
			$this->regex_failed = true;
			return null;
		}

		if ( 0 === $count ) {
			return null;
		}

		$done = true;

		return substr( $html, 0, $start ) . $replaced . substr( $html, $end );
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

		if ( $token['closing'] ) {
			// Clamped at zero so a stray "</a>" cannot make later text inside a
			// real anchor look linkable.
			if ( 'a' === $tag && $state['anchor'] > 0 ) {
				--$state['anchor'];
			}
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
