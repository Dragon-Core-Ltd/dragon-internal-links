<?php
/**
 * Admin tests: the suggestions Context column keyword highlight.
 *
 * @package DragonInternalLinks
 */

namespace DragonInternalLinks\Tests;

use DragonInternalLinks\Admin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-admin.php';

final class AdminTest extends TestCase {

	public function test_highlight_marks_keyword_in_escaped_context(): void {
		$this->assertSame(
			'see the <mark>Coffee Beans Guide</mark> &amp; more',
			Admin::highlight_keyword( 'see the Coffee Beans Guide & more', 'coffee beans guide' )
		);
	}

	public function test_highlight_keeps_the_context_readable_when_a_byte_is_invalid(): void {
		// esc_html() returns '' for invalid UTF-8, so the whole Context column
		// would go blank. The bad byte is dropped and the rest is still shown and
		// still highlighted.
		$this->assertSame(
			'caf <mark>coffee beans guide</mark> &amp; more',
			Admin::highlight_keyword( "caf\xE9 coffee beans guide & more", 'coffee beans guide' )
		);
	}

	public function test_highlight_falls_back_to_escaped_context_on_invalid_utf8_keyword(): void {
		$this->assertSame(
			'a &lt;b&gt; c',
			Admin::highlight_keyword( 'a <b> c', "caf\xE9" )
		);
	}
}
