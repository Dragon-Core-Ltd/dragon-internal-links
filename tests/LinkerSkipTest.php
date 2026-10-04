<?php
/**
 * Text that must never receive a link: code samples (pre, code, kbd, samp,
 * var) and bare URLs or email addresses, including an embed's URL. The
 * suggestion pre-check (matchable_text) agrees with insert().
 *
 * @package DragonInternalLinks
 */

namespace DragonInternalLinks\Tests;

use DragonInternalLinks\Linker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-linker.php';

final class LinkerSkipTest extends TestCase {

	private const URL = 'https://example.test/target/';

	protected function setUp(): void {
		dragoninternallinks_test_reset();
	}

	private function link( string $content, string $keyword ): ?string {
		return ( new Linker() )->insert( $content, $keyword, self::URL );
	}

	private function a( string $text ): string {
		return '<a href="' . self::URL . '">' . $text . '</a>';
	}

	public static function code_elements(): array {
		return array(
			'code block'  => array( '<!-- wp:code --><pre class="wp-block-code"><code>wp cron event run --due-now</code></pre><!-- /wp:code -->' ),
			'classic pre with code' => array( '<pre><code>wp cron event run</code></pre>' ),
			'code block class only' => array( '<pre class="wp-block-code">wp cron event run</pre>' ),
			'inline code' => array( '<p>Type <code>cron event</code> here.</p>' ),
			'kbd'         => array( '<p>Press <kbd>cron event</kbd> now.</p>' ),
			'samp'        => array( '<p>It prints <samp>cron event done</samp>.</p>' ),
			'var'         => array( '<p>Set <var>cron event</var> first.</p>' ),
			'nested'      => array( '<pre><code><span>cron event</span></code></pre>' ),
		);
	}

	#[DataProvider( 'code_elements' )]
	public function test_code_samples_are_never_linked( string $code ): void {
		$this->assertNull( $this->link( $code, 'cron event' ) );
		$this->assertStringNotContainsString( 'cron event', Linker::matchable_text( $code ) );
	}

	#[DataProvider( 'code_elements' )]
	public function test_prose_after_a_code_sample_is_linked( string $code ): void {
		$content = $code . '<p>Read about the cron event schedule.</p>';

		$this->assertSame(
			$code . '<p>Read about the ' . $this->a( 'cron event' ) . ' schedule.</p>',
			$this->link( $content, 'cron event' )
		);
	}

	public static function prose_pre_blocks(): array {
		return array(
			'verse block'        => array( '<!-- wp:verse -->' . "\n" . '<pre class="wp-block-verse">The morning ', " espresso\nwakes the town</pre>\n<!-- /wp:verse -->" ),
			'preformatted block' => array( '<!-- wp:preformatted -->' . "\n" . '<pre class="wp-block-preformatted">Our ', ' espresso menu</pre>' . "\n<!-- /wp:preformatted -->" ),
			'classic pre'        => array( '<pre>Our ', ' espresso menu</pre>' ),
		);
	}

	#[DataProvider( 'prose_pre_blocks' )]
	public function test_verse_and_preformatted_blocks_are_linked_as_before( string $before, string $after ): void {
		$content = $before . 'house' . $after;

		$this->assertSame(
			$before . 'house' . str_replace( ' espresso', ' ' . $this->a( 'espresso' ), $after ),
			$this->link( $content, 'espresso' )
		);
		$this->assertStringContainsString( 'espresso', Linker::matchable_text( $content ) );
	}

	public function test_prose_in_elements_named_like_code_elements_is_still_linked(): void {
		$this->assertSame(
			'<p><variable>' . $this->a( 'cron event' ) . '</variable></p>',
			$this->link( '<p><variable>cron event</variable></p>', 'cron event' )
		);
	}

	public static function url_tokens(): array {
		return array(
			'embed block wrapper'   => array( "<!-- wp:embed {\"url\":\"https://example.test/espresso-guide/\"} -->\n<figure class=\"wp-block-embed\"><div class=\"wp-block-embed__wrapper\">\nhttps://example.test/espresso-guide/\n</div></figure>\n<!-- /wp:embed -->" ),
			'classic auto-embed'    => array( "<p>Intro.</p>\nhttps://www.youtube.com/watch?v=espresso\n" ),
			'embed shortcode'       => array( '[embed]https://example.test/espresso-guide/[/embed]' ),
			'url in a sentence'     => array( '<p>See https://example.test/espresso-guide/ for more.</p>' ),
			'email address'         => array( '<p>Write to espresso@example.test today.</p>' ),
			'email domain part'     => array( '<p>Write to barista@espresso.example today.</p>' ),
		);
	}

	#[DataProvider( 'url_tokens' )]
	public function test_a_word_inside_a_url_or_email_is_never_linked( string $content ): void {
		$GLOBALS['shortcode_tags'] = array( 'embed' => '__return_false' );

		$this->assertNull( $this->link( $content, 'espresso' ) );
		$this->assertSame( 0, preg_match( Linker::keyword_pattern( 'espresso' ), Linker::matchable_text( $content ) ) );
	}

	public function test_the_word_in_prose_after_a_url_is_linked(): void {
		$content = '<p>See https://example.test/espresso-guide/ and our espresso tips.</p>';

		$this->assertSame(
			'<p>See https://example.test/espresso-guide/ and our ' . $this->a( 'espresso' ) . ' tips.</p>',
			$this->link( $content, 'espresso' )
		);
	}

	public static function space_after_token(): array {
		return array(
			'nbsp entity after a url'     => array( '<p>See https://example.test/&nbsp;', ' tips</p>' ),
			'nbsp entity after an email'  => array( '<p>Mail info@example.test&nbsp;', ' tips</p>' ),
			'numeric nbsp after a url'    => array( '<p>See https://example.test/x&#160;', ' tips</p>' ),
			'raw no-break space after url' => array( "<p>See https://example.test/x\u{00A0}", ' tips</p>' ),
			'raw no-break space before an email' => array( "<p>Questions:\u{00A0}help@example.test. Our ", ' tips</p>' ),
		);
	}

	#[DataProvider( 'space_after_token' )]
	public function test_a_no_break_space_ends_a_url_or_email( string $before, string $after ): void {
		$this->assertSame(
			$before . $this->a( 'espresso' ) . $after,
			$this->link( $before . 'espresso' . $after, 'espresso' )
		);
		$text = Linker::matchable_text( $before . 'espresso' . $after );
		$this->assertTrue( mb_check_encoding( $text, 'UTF-8' ), bin2hex( $text ) );
		$this->assertSame( 1, preg_match( Linker::keyword_pattern( 'espresso' ), $text ) );
	}

	public function test_multi_word_keywords_and_ordinary_punctuation_are_unchanged(): void {
		$this->assertSame(
			'<p>Our ' . $this->a( 'espresso' ) . '-based drinks, e.g. lattes.</p>',
			$this->link( '<p>Our espresso-based drinks, e.g. lattes.</p>', 'espresso' )
		);
		$this->assertSame(
			'<p>Price: 5 @ ' . $this->a( 'espresso' ) . ' bar.</p>',
			$this->link( '<p>Price: 5 @ espresso bar.</p>', 'espresso' )
		);
	}
}
