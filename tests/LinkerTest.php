<?php
/**
 * Linker tests: first-occurrence keyword linking in post content.
 *
 * @package DragonInternalLinks
 */

namespace DragonInternalLinks\Tests;

use DragonInternalLinks\Linker;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-linker.php';

final class LinkerTest extends TestCase {

	private const URL = 'https://example.test/coffee-beans-guide/';

	protected function setUp(): void {
		dragoninternallinks_test_reset();
	}

	private function link( string $content, string $keyword = 'coffee beans guide', ?Linker &$linker = null ): ?string {
		$linker = new Linker();
		return $linker->insert( $content, $keyword, self::URL );
	}

	public function test_links_first_text_occurrence_only(): void {
		$out = $this->link( '<p>Read our coffee beans guide, then the coffee beans guide again.</p>' );
		$this->assertSame(
			'<p>Read our <a href="' . self::URL . '">coffee beans guide</a>, then the coffee beans guide again.</p>',
			$out
		);
	}

	public function test_anchor_text_keeps_the_post_casing(): void {
		$out = $this->link( '<p>Our Coffee Beans Guide is here.</p>' );
		$this->assertSame( '<p>Our <a href="' . self::URL . '">Coffee Beans Guide</a> is here.</p>', $out );
	}

	public function test_keyword_inside_an_attribute_is_not_linked(): void {
		$content = '<p><img alt="Our Coffee Beans Guide"> Read our coffee beans guide.</p>';
		$out     = $this->link( $content );
		$this->assertSame(
			'<p><img alt="Our Coffee Beans Guide"> Read our <a href="' . self::URL . '">coffee beans guide</a>.</p>',
			$out
		);
	}

	public function test_greater_than_inside_a_quoted_attribute_does_not_end_the_tag(): void {
		$content = '<p><img alt="5 > 3 coffee beans guide" src="x.png"> Read our coffee beans guide.</p>';
		$out     = $this->link( $content );
		$this->assertSame(
			'<p><img alt="5 > 3 coffee beans guide" src="x.png"> Read our <a href="' . self::URL . '">coffee beans guide</a>.</p>',
			$out
		);
	}

	public function test_greater_than_inside_a_single_quoted_attribute_does_not_end_the_tag(): void {
		$content = "<p><span title='a > b'>coffee beans guide</span></p>";
		$out     = $this->link( $content );
		$this->assertSame(
			"<p><span title='a > b'><a href=\"" . self::URL . '">coffee beans guide</a></span></p>',
			$out
		);
	}

	public function test_attribute_mixing_both_quote_styles_does_not_end_the_tag(): void {
		$content = '<p><span data-a="it\'s > 1" data-b=\'say "hi" > 2 coffee beans guide\'>x</span> coffee beans guide</p>';
		$out     = $this->link( $content );
		$this->assertSame(
			'<p><span data-a="it\'s > 1" data-b=\'say "hi" > 2 coffee beans guide\'>x</span> <a href="' . self::URL . '">coffee beans guide</a></p>',
			$out
		);
	}

	public function test_unquoted_href_ending_in_slash_is_still_an_open_anchor(): void {
		$content = '<p><a href=/foo/>the coffee beans guide</a> and the coffee beans guide</p>';
		$out     = $this->link( $content );
		$this->assertSame(
			'<p><a href=/foo/>the coffee beans guide</a> and the <a href="' . self::URL . '">coffee beans guide</a></p>',
			$out
		);
	}

	public function test_keyword_inside_a_block_delimiter_or_caption_is_not_linked(): void {
		// Captions are never linked (the docs promise it), so the link lands in
		// the paragraph after the image.
		$image   = '<!-- wp:image {"caption":"a coffee beans guide"} -->' . "\n"
			. '<figure class="wp-block-image"><img src="x.png" alt="coffee beans guide"/><figcaption>the coffee beans guide</figcaption></figure>' . "\n"
			. '<!-- /wp:image -->';
		$content = $image . '<!-- wp:paragraph --><p>Read the coffee beans guide.</p><!-- /wp:paragraph -->';
		$out     = $this->link( $content );
		$this->assertSame(
			$image . '<!-- wp:paragraph --><p>Read the <a href="' . self::URL . '">coffee beans guide</a>.</p><!-- /wp:paragraph -->',
			$out
		);
	}

	public function test_a_figcaption_alone_is_not_linked(): void {
		$this->assertNull( $this->link( '<figure><img src="x.png"/><figcaption>the coffee beans guide</figcaption></figure>' ) );
	}

	public function test_classic_caption_shortcode_content_is_not_linked(): void {
		$GLOBALS['shortcode_tags'] = array( 'caption' => '__return_empty_string' );

		$content = '[caption id="attachment_1" align="alignnone" width="300"]<img src="x.png" /> The coffee beans guide[/caption]' . "\n\n" . 'See the coffee beans guide.';
		$out     = $this->link( $content );

		$this->assertSame(
			'[caption id="attachment_1" align="alignnone" width="300"]<img src="x.png" /> The coffee beans guide[/caption]' . "\n\n" . 'See the <a href="' . self::URL . '">coffee beans guide</a>.',
			$out
		);
	}

	public function test_shortcode_attributes_are_not_linked(): void {
		$GLOBALS['shortcode_tags'] = array( 'button' => '__return_empty_string' );

		$out = $this->link( '<p>[button text="Coffee Beans Guide" url="/x"] then the coffee beans guide</p>' );

		$this->assertSame( '<p>[button text="Coffee Beans Guide" url="/x"] then the <a href="' . self::URL . '">coffee beans guide</a></p>', $out );
	}

	public function test_enclosing_shortcode_content_is_still_linked(): void {
		// Page builders wrap whole sections in shortcodes: only the tags are off limits.
		$GLOBALS['shortcode_tags'] = array( 'section' => '__return_empty_string' );

		$out = $this->link( '<p>[section bg="coffee beans guide"]Our coffee beans guide[/section]</p>' );

		$this->assertSame( '<p>[section bg="coffee beans guide"]Our <a href="' . self::URL . '">coffee beans guide</a>[/section]</p>', $out );
	}

	public function test_unregistered_bracket_text_is_ordinary_text(): void {
		$GLOBALS['shortcode_tags'] = array();

		$out = $this->link( '<p>[the coffee beans guide]</p>' );

		$this->assertSame( '<p>[the <a href="' . self::URL . '">coffee beans guide</a>]</p>', $out );
	}

	public function test_a_keyword_is_not_linked_mid_word_across_an_inline_tag(): void {
		$linker = new Linker();

		$this->assertNull( $linker->insert( '<p>cat<strong>egory</strong></p>', 'cat', self::URL ), 'the word continues after the tag' );
		$this->assertNull( $linker->insert( '<p><em>bob</em>cat</p>', 'cat', self::URL ), 'the word started before the tag' );
		$this->assertSame(
			'<p>cat<strong>egory</strong> and a <a href="' . self::URL . '">cat</a></p>',
			$linker->insert( '<p>cat<strong>egory</strong> and a cat</p>', 'cat', self::URL )
		);
	}

	public function test_inline_tags_with_space_or_block_tags_still_separate_words(): void {
		$linker = new Linker();

		$this->assertSame( '<p><strong><a href="' . self::URL . '">cat</a></strong> food</p>', $linker->insert( '<p><strong>cat</strong> food</p>', 'cat', self::URL ) );
		$this->assertSame( '<p><a href="' . self::URL . '">cat</a></p><p>egory</p>', $linker->insert( '<p>cat</p><p>egory</p>', 'cat', self::URL ) );
		$this->assertSame( '<p><a href="' . self::URL . '">cat</a><br>egory</p>', $linker->insert( '<p>cat<br>egory</p>', 'cat', self::URL ) );
		$this->assertSame( "<p>bob\n<em></em><a href=\"" . self::URL . "\">cat</a></p>", $linker->insert( "<p>bob\n<em></em>cat</p>", 'cat', self::URL ), 'a run ending in a newline does not continue the word' );
	}

	public function test_first_occurrence_across_nested_blocks_in_document_order(): void {
		$content = '<!-- wp:group --><div class="wp-block-group">'
			. '<!-- wp:paragraph --><p>no match here</p><!-- /wp:paragraph -->'
			. '<!-- wp:paragraph --><p>a coffee beans guide</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:group -->'
			. '<!-- wp:paragraph --><p>another coffee beans guide</p><!-- /wp:paragraph -->';
		$out     = $this->link( $content );
		$this->assertSame(
			'<!-- wp:group --><div class="wp-block-group">'
			. '<!-- wp:paragraph --><p>no match here</p><!-- /wp:paragraph -->'
			. '<!-- wp:paragraph --><p>a <a href="' . self::URL . '">coffee beans guide</a></p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:group -->'
			. '<!-- wp:paragraph --><p>another coffee beans guide</p><!-- /wp:paragraph -->',
			$out
		);
	}

	public function test_text_already_inside_a_link_is_skipped(): void {
		$content = '<p><a href="/x" class="c">the coffee beans guide</a> and the coffee beans guide.</p>';
		$out     = $this->link( $content );
		$this->assertSame(
			'<p><a href="/x" class="c">the coffee beans guide</a> and the <a href="' . self::URL . '">coffee beans guide</a>.</p>',
			$out
		);
	}

	public function test_text_inside_script_and_style_is_skipped(): void {
		$content = '<script>var s = "coffee beans guide";</script><style>/* coffee beans guide */</style><p>coffee beans guide</p>';
		$out     = $this->link( $content );
		$this->assertSame(
			'<script>var s = "coffee beans guide";</script><style>/* coffee beans guide */</style><p><a href="' . self::URL . '">coffee beans guide</a></p>',
			$out
		);
	}

	public function test_keyword_not_found_returns_null_without_failure(): void {
		$out = $this->link( '<p>nothing relevant</p>', 'coffee beans guide', $linker );
		$this->assertNull( $out );
		$this->assertFalse( $linker->regex_failed() );
	}

	public function test_keyword_only_inside_attributes_is_not_found(): void {
		$out = $this->link( '<p><img alt="coffee beans guide"></p>', 'coffee beans guide', $linker );
		$this->assertNull( $out );
		$this->assertFalse( $linker->regex_failed() );
	}

	public function test_invalid_utf8_content_is_reported_as_failure(): void {
		$out = $this->link( "<p>caf\xE9 coffee beans guide</p>", 'coffee beans guide', $linker );
		$this->assertNull( $out );
		$this->assertTrue( $linker->regex_failed() );
	}

	public function test_invalid_utf8_keyword_is_reported_as_failure(): void {
		$out = $this->link( '<p>coffee beans guide</p>', "caf\xE9", $linker );
		$this->assertNull( $out );
		$this->assertTrue( $linker->regex_failed() );
	}

	public function test_link_url_is_escaped(): void {
		$linker = new Linker();
		$out    = $linker->insert( '<p>coffee beans guide</p>', 'coffee beans guide', 'https://example.test/?p=1&x=2' );
		$this->assertSame( '<p><a href="https://example.test/?p=1&#038;x=2">coffee beans guide</a></p>', $out );
	}

	public function test_save_slashes_content_and_lifts_kses_for_the_write(): void {
		$GLOBALS['dragoninternallinks_test']['kses_active'] = true;
		$content = '<!-- wp:paragraph {"x":"a--b"} --><p>x</p><!-- /wp:paragraph -->';

		$linker = new Linker();
		$this->assertTrue( $linker->save( 5, $content ) );

		$calls = dragoninternallinks_test_calls( 'wp_update_post' );
		$this->assertCount( 1, $calls );
		$this->assertSame( 5, $calls[0][0]['ID'] );
		$this->assertSame( wp_slash( $content ), $calls[0][0]['post_content'] );
		$this->assertTrue( $calls[0][1] );
		$this->assertSame( array( 'kses_remove_filters', 'wp_update_post', 'kses_init_filters' ), dragoninternallinks_test_call_names() );
	}

	public function test_save_reports_wp_error_and_zero_as_failure(): void {
		$linker = new Linker();

		$GLOBALS['dragoninternallinks_test']['update_post'] = new \WP_Error( 'x', 'nope' );
		$this->assertFalse( $linker->save( 5, '<p>x</p>' ) );

		$GLOBALS['dragoninternallinks_test']['update_post'] = 0;
		$this->assertFalse( $linker->save( 5, '<p>x</p>' ) );
	}
	public function test_keyword_inside_an_html_comment_is_not_linked(): void {
		$content = '<!-- note > coffee beans guide --><p>coffee beans guide</p>';
		$out     = $this->link( $content );
		$this->assertSame(
			'<!-- note > coffee beans guide --><p><a href="' . self::URL . '">coffee beans guide</a></p>',
			$out
		);
	}

	public function test_comment_only_keyword_is_not_found(): void {
		$out = $this->link( '<!-- a > coffee beans guide -->', 'coffee beans guide', $linker );
		$this->assertNull( $out );
		$this->assertFalse( $linker->regex_failed() );
	}

	public function test_keyword_inside_a_textarea_is_not_linked(): void {
		$content = '<textarea>coffee beans guide</textarea><p>coffee beans guide</p>';
		$out     = $this->link( $content );
		$this->assertSame(
			'<textarea>coffee beans guide</textarea><p><a href="' . self::URL . '">coffee beans guide</a></p>',
			$out
		);
	}

	public function test_keyword_inside_a_title_element_is_not_linked(): void {
		$content = '<title>coffee beans guide</title><p>coffee beans guide</p>';
		$out     = $this->link( $content );
		$this->assertSame(
			'<title>coffee beans guide</title><p><a href="' . self::URL . '">coffee beans guide</a></p>',
			$out
		);
	}

	public function test_keyword_inside_a_title_attribute_is_still_not_linked(): void {
		$content = '<p><span title="Our coffee beans guide">x</span> the coffee beans guide</p>';
		$out     = $this->link( $content );
		$this->assertSame(
			'<p><span title="Our coffee beans guide">x</span> the <a href="' . self::URL . '">coffee beans guide</a></p>',
			$out
		);
	}

	public function test_literal_less_than_inside_script_does_not_desynchronise_the_scan(): void {
		$content = '<script>if (x < 3) { }</script><p>coffee beans guide</p>';
		$out     = $this->link( $content );
		$this->assertSame(
			'<script>if (x < 3) { }</script><p><a href="' . self::URL . '">coffee beans guide</a></p>',
			$out
		);
	}

	public function test_literal_less_than_in_text_is_text_not_a_tag(): void {
		$content = '<p>5 < 3 and coffee beans guide</p>';
		$out     = $this->link( $content );
		$this->assertSame(
			'<p>5 < 3 and <a href="' . self::URL . '">coffee beans guide</a></p>',
			$out
		);
	}

	public function test_markup_inside_script_does_not_open_an_anchor(): void {
		$content = '<script>var s = "<a href=x>";</script><p>coffee beans guide</p>';
		$out     = $this->link( $content );
		$this->assertSame(
			'<script>var s = "<a href=x>";</script><p><a href="' . self::URL . '">coffee beans guide</a></p>',
			$out
		);
	}

	public function test_keyword_inside_a_cdata_declaration_is_not_linked(): void {
		$content = '<![CDATA[coffee beans guide]]><p>coffee beans guide</p>';
		$out     = $this->link( $content );
		$this->assertSame(
			'<![CDATA[coffee beans guide]]><p><a href="' . self::URL . '">coffee beans guide</a></p>',
			$out
		);
	}

	public function test_doctype_declaration_does_not_desynchronise_the_scan(): void {
		$content = '<!DOCTYPE html><p>coffee beans guide</p>';
		$out     = $this->link( $content );
		$this->assertSame(
			'<!DOCTYPE html><p><a href="' . self::URL . '">coffee beans guide</a></p>',
			$out
		);
	}

	public function test_unterminated_comment_stops_at_the_chunk_it_is_in(): void {
		$content = '<!-- wp:paragraph --><p>oops <!-- unterminated</p><!-- /wp:paragraph -->'
			. '<!-- wp:paragraph --><p>coffee beans guide</p><!-- /wp:paragraph -->';
		$out     = $this->link( $content );
		$this->assertSame(
			'<!-- wp:paragraph --><p>oops <!-- unterminated</p><!-- /wp:paragraph -->'
			. '<!-- wp:paragraph --><p><a href="' . self::URL . '">coffee beans guide</a></p><!-- /wp:paragraph -->',
			$out
		);
	}

	public function test_anchor_spanning_blocks_does_not_get_a_nested_link(): void {
		$content = '<a href="/old"><!-- wp:paragraph --><p>coffee beans guide</p><!-- /wp:paragraph --></a>';
		$out     = $this->link( $content, 'coffee beans guide', $linker );
		$this->assertNull( $out );
		$this->assertFalse( $linker->regex_failed() );
	}

	public function test_anchor_spanning_blocks_still_allows_a_later_occurrence(): void {
		$content = '<a href="/old"><!-- wp:paragraph --><p>coffee beans guide</p><!-- /wp:paragraph --></a>'
			. '<!-- wp:paragraph --><p>another coffee beans guide</p><!-- /wp:paragraph -->';
		$out     = $this->link( $content );
		$this->assertSame(
			'<a href="/old"><!-- wp:paragraph --><p>coffee beans guide</p><!-- /wp:paragraph --></a>'
			. '<!-- wp:paragraph --><p>another <a href="' . self::URL . '">coffee beans guide</a></p><!-- /wp:paragraph -->',
			$out
		);
	}

	public function test_anchor_spanning_into_an_inner_block_does_not_get_a_nested_link(): void {
		$content = '<!-- wp:group --><div class="wp-block-group"><a href="/old">'
			. '<!-- wp:paragraph --><p>coffee beans guide</p><!-- /wp:paragraph -->'
			. '</a></div><!-- /wp:group -->';
		$out     = $this->link( $content, 'coffee beans guide', $linker );
		$this->assertNull( $out );
		$this->assertFalse( $linker->regex_failed() );
	}

	public function test_unclosed_anchor_suppresses_linking_in_later_blocks(): void {
		$content = '<!-- wp:paragraph --><p><a href="/x">open</p><!-- /wp:paragraph -->'
			. '<!-- wp:paragraph --><p>the coffee beans guide</p><!-- /wp:paragraph -->';
		$out     = $this->link( $content, 'coffee beans guide', $linker );
		$this->assertNull( $out );
		$this->assertFalse( $linker->regex_failed() );
	}

	public function test_stray_closing_anchor_does_not_re_enable_linking_inside_a_real_anchor(): void {
		$content = '<p></a>one</p><p><a href="/x">the coffee beans guide</a> and the coffee beans guide.</p>';
		$out     = $this->link( $content );
		$this->assertSame(
			'<p></a>one</p><p><a href="/x">the coffee beans guide</a> and the <a href="' . self::URL . '">coffee beans guide</a>.</p>',
			$out
		);
	}
	public function test_nested_script_tag_in_an_escaped_script_does_not_end_the_raw_text(): void {
		// In HTML script data, "<!--" opens an escaped run and a nested
		// "<script" inside it opens a double-escaped run, where "</script>"
		// only ends the double escape and leaves the element open.
		$content = '<script><!-- document.write("<script></script>coffee beans guide"); // --></script>'
			. '<p>coffee beans guide</p>';
		$out     = $this->link( $content );
		$this->assertSame(
			'<script><!-- document.write("<script></script>coffee beans guide"); // --></script>'
			. '<p><a href="' . self::URL . '">coffee beans guide</a></p>',
			$out
		);
	}

	public function test_legacy_commented_script_still_ends_at_its_close_tag(): void {
		$content = '<script><!-- var s = "coffee beans guide"; // --></script><p>coffee beans guide</p>';
		$out     = $this->link( $content );
		$this->assertSame(
			'<script><!-- var s = "coffee beans guide"; // --></script><p><a href="' . self::URL . '">coffee beans guide</a></p>',
			$out
		);
	}
	public function test_abruptly_closed_empty_comment_does_not_swallow_the_rest(): void {
		// "<!-->" is a complete comment, so the <script> after it really opens.
		$content = '<!--><script>coffee beans guide</script><p>coffee beans guide</p>';
		$out     = $this->link( $content );
		$this->assertSame(
			'<!--><script>coffee beans guide</script><p><a href="' . self::URL . '">coffee beans guide</a></p>',
			$out
		);
	}

	public function test_abruptly_closed_dash_comment_does_not_swallow_the_rest(): void {
		$content = '<!---><script>coffee beans guide</script><p>coffee beans guide</p>';
		$out     = $this->link( $content );
		$this->assertSame(
			'<!---><script>coffee beans guide</script><p><a href="' . self::URL . '">coffee beans guide</a></p>',
			$out
		);
	}

	public function test_comment_closed_with_a_bang_ends_the_comment(): void {
		$content = '<!-- coffee beans guide --!><script>coffee beans guide</script><p>coffee beans guide</p>';
		$out     = $this->link( $content );
		$this->assertSame(
			'<!-- coffee beans guide --!><script>coffee beans guide</script><p><a href="' . self::URL . '">coffee beans guide</a></p>',
			$out
		);
	}

	public function test_bang_after_the_comment_opener_does_not_close_it(): void {
		// "<!--!>" is not an abrupt close, so this comment runs to its "-->".
		$content = '<!--!> coffee beans guide --><p>coffee beans guide</p>';
		$out     = $this->link( $content );
		$this->assertSame(
			'<!--!> coffee beans guide --><p><a href="' . self::URL . '">coffee beans guide</a></p>',
			$out
		);
	}
	public function test_quote_in_an_attribute_name_does_not_open_a_value(): void {
		// The value closes at its own quote, so "y"" after it is an attribute
		// name: the quote there is part of the name and opens nothing.
		$content = '<p title="x" y">coffee beans guide</p>';
		$out     = $this->link( $content );
		$this->assertSame(
			'<p title="x" y"><a href="' . self::URL . '">coffee beans guide</a></p>',
			$out
		);
	}

	public function test_odd_quote_count_does_not_shift_later_attribute_values(): void {
		// Pairing quotes off against each other would end the <p> inside the
		// span's title value and link the keyword into that attribute.
		$content = '<p title="a" b"><span title="x > coffee beans guide">y</span> the coffee beans guide</p>';
		$out     = $this->link( $content );
		$this->assertSame(
			'<p title="a" b"><span title="x > coffee beans guide">y</span> the <a href="' . self::URL . '">coffee beans guide</a></p>',
			$out
		);
	}

	public function test_unclosed_attribute_value_consumes_the_rest_of_the_chunk(): void {
		$content = '<p title="never closed><p>coffee beans guide</p>';
		$out     = $this->link( $content, 'coffee beans guide', $linker );
		$this->assertNull( $out );
		$this->assertFalse( $linker->regex_failed() );
	}
	public function test_chunk_ending_inside_an_unterminated_tag_stops_linking(): void {
		// A block delimiter inside an attribute value cuts the chunk mid-tag. A
		// browser never saw the delimiter, so to it the whole rest of the post
		// is still inside that attribute and nothing here is text.
		$content = '<p title="never closed<!-- wp:quote /--><p>coffee beans guide</p>';
		$out     = $this->link( $content, 'coffee beans guide', $linker );
		$this->assertNull( $out );
		$this->assertFalse( $linker->regex_failed() );
	}

	public function test_unterminated_tag_stops_linking_in_later_blocks(): void {
		$content = '<!-- wp:paragraph --><p title="never closed<!-- /wp:paragraph -->'
			. '<!-- wp:paragraph --><p>coffee beans guide</p><!-- /wp:paragraph -->';
		$out     = $this->link( $content, 'coffee beans guide', $linker );
		$this->assertNull( $out );
		$this->assertFalse( $linker->regex_failed() );
	}
	public function test_unclosed_style_keeps_later_blocks_out_of_reach(): void {
		// An unclosed raw-text element swallows the rest of the document, so the
		// keyword in the next block is stylesheet source, not text.
		$content = '<!-- wp:paragraph --><p><style>.x{color:red}</p><!-- /wp:paragraph -->'
			. '<!-- wp:paragraph --><p>coffee beans guide</p><!-- /wp:paragraph -->';
		$out     = $this->link( $content, 'coffee beans guide', $linker );
		$this->assertNull( $out );
		$this->assertFalse( $linker->regex_failed() );
	}

	public function test_self_closed_script_still_opens_a_raw_text_run(): void {
		// HTML ignores the self-closing slash on <script>, so this element is
		// open and everything after it is script source.
		$content = '<!-- wp:paragraph --><p><script src="x.js"/></p><!-- /wp:paragraph -->'
			. '<!-- wp:paragraph --><p>coffee beans guide</p><!-- /wp:paragraph -->';
		$out     = $this->link( $content, 'coffee beans guide', $linker );
		$this->assertNull( $out );
		$this->assertFalse( $linker->regex_failed() );
	}

	public function test_raw_text_closing_in_a_later_block_is_still_skipped(): void {
		// The <style> spans the block boundary and closes in the later block, so
		// its contents are skipped and the text after it is linked.
		$content = '<!-- wp:paragraph --><p><style>.x{color:red}<!-- /wp:paragraph -->'
			. '<!-- wp:paragraph -->coffee beans guide</style><p>the coffee beans guide</p><!-- /wp:paragraph -->';
		$out     = $this->link( $content );
		$this->assertSame(
			'<!-- wp:paragraph --><p><style>.x{color:red}<!-- /wp:paragraph -->'
			. '<!-- wp:paragraph -->coffee beans guide</style><p>the <a href="' . self::URL . '">coffee beans guide</a></p><!-- /wp:paragraph -->',
			$out
		);
	}

	public function test_keyword_inside_a_longer_word_is_not_linked(): void {
		$out = $this->link( '<p>Every category, then the cat.</p>', 'cat' );
		$this->assertSame( '<p>Every category, then the <a href="' . self::URL . '">cat</a>.</p>', $out );
	}

	public function test_keyword_that_only_appears_inside_words_is_not_found(): void {
		$linker = null;
		$this->assertNull( $this->link( '<p>Concatenate the catalogue.</p>', 'cat', $linker ) );
		$this->assertFalse( $linker->regex_failed() );
	}

	public function test_word_boundaries_are_unicode_aware(): void {
		// \b is ASCII-only, so "café" inside "cafés" would pass it.
		$out = $this->link( '<p>Les cafés et le café.</p>', 'café' );
		$this->assertSame( '<p>Les cafés et le <a href="' . self::URL . '">café</a>.</p>', $out );
	}

	public function test_keyword_is_never_linked_inside_an_entity(): void {
		$out = $this->link( '<p>Tom &amp; Jerry use amp tools.</p>', 'amp' );
		$this->assertSame( '<p>Tom &amp; Jerry use <a href="' . self::URL . '">amp</a> tools.</p>', $out );
	}

	public function test_keyword_at_the_edges_of_the_text_is_linked(): void {
		$this->assertSame( '<p><a href="' . self::URL . '">cat</a></p>', $this->link( '<p>cat</p>', 'cat' ) );
		$this->assertSame( '<p>(<a href="' . self::URL . '">cat</a>)</p>', $this->link( '<p>(cat)</p>', 'cat' ) );
	}

	public function test_keyword_ending_in_punctuation_needs_no_boundary_after_it(): void {
		$out = $this->link( '<p>Learn C++today.</p>', 'C++' );
		$this->assertSame( '<p>Learn <a href="' . self::URL . '">C++</a>today.</p>', $out );
	}

	public function test_keyword_with_ampersand_or_apostrophe_matches_its_html_spelling(): void {
		$this->assertSame(
			'<p>Compare <a href="' . self::URL . '">Salt &amp; Pepper Grinders</a> here.</p>',
			$this->link( '<p>Compare Salt &amp; Pepper Grinders here.</p>', 'Salt & Pepper Grinders' )
		);
		$this->assertSame(
			'<p>Our <a href="' . self::URL . '">Beginner&#8217;s Guide</a> helps.</p>',
			$this->link( '<p>Our Beginner&#8217;s Guide helps.</p>', "Beginner's Guide" )
		);
		$this->assertSame(
			'<p>Our <a href="' . self::URL . '">Beginner’s Guide</a> helps.</p>',
			$this->link( '<p>Our Beginner’s Guide helps.</p>', "Beginner's Guide" )
		);
		// An entity is still never matched from its middle.
		$this->assertNull( $this->link( '<p>Tom &amp; Jerry</p>', 'amp' ) );
	}
}
