<?php
/**
 * AJAX handlers: each one checks its own nonce and capability before it reads
 * the request or changes anything, and reads its numbers as whole numbers.
 *
 * @package DragonInternalLinks
 */

namespace DragonInternalLinks\Tests;

use DragonInternalLinks\Ajax;
use DragonInternalLinks\Analyzer;
use DragonInternalLinks\Scanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-scanner.php';
require_once __DIR__ . '/../includes/class-analyzer.php';
require_once __DIR__ . '/../includes/class-linker.php';
require_once __DIR__ . '/../includes/class-ajax.php';

/**
 * Scanner that records what it was asked to do.
 */
final class WporgScanAjaxScanner extends Scanner {
	public array $asked = array();

	public function scan_all( int $batch_size = 50, int $offset = 0 ): array {
		$this->asked[] = array( 'scan_all', $batch_size, $offset );
		return array(
			'scanned'  => 2,
			'total'    => 2,
			'offset'   => $offset + 2,
			'complete' => true,
			'failed'   => 0,
			'pruned'   => true,
		);
	}

	public function scan_post( int $post_id ): array {
		$this->asked[] = array( 'scan_post', $post_id );
		return array( array( 'url' => 'https://example.test/a/' ), array( 'url' => 'https://example.test/b/' ) );
	}

	public function scan_failed(): bool {
		return false;
	}
}

/**
 * Analyzer that records what it was asked to do.
 */
final class WporgScanAjaxAnalyzer extends Analyzer {
	public array $asked = array();

	public function generate_all_suggestions( int $batch_size = 20, int $offset = 0 ): array {
		$this->asked[] = array( 'generate', $batch_size, $offset );
		return array(
			'generated' => 3,
			'failed'    => 0,
			'stale'     => false,
			'offset'    => $offset + 20,
			'total'     => 20,
			'done'      => true,
		);
	}

	public function update_suggestion_status( int $suggestion_id, string $status ): bool {
		$this->asked[] = array( 'status', $suggestion_id, $status );
		return true;
	}
}

final class WporgScanAjaxTest extends TestCase {

	private const NONCE_ACTION = 'dragoninternallinks_admin_nonce';

	private WporgScanAjaxScanner $scanner;
	private WporgScanAjaxAnalyzer $analyzer;
	private Ajax $ajax;

	protected function setUp(): void {
		dragoninternallinks_test_reset();
		$_POST    = array();
		$_REQUEST = array();

		$this->scanner  = new WporgScanAjaxScanner();
		$this->analyzer = new WporgScanAjaxAnalyzer( $this->scanner );
		$this->ajax     = new Ajax( $this->scanner, $this->analyzer );

		$GLOBALS['wpdb']->returns['get_row'] = array(
			'id'             => 9,
			'source_post_id' => 5,
			'target_post_id' => 7,
			'keyword'        => 'coffee beans guide',
			'status'         => 'pending',
		);

		$post               = new \WP_Post();
		$post->ID           = 5;
		$post->post_content = '<!-- wp:paragraph --><p>Our Coffee Beans Guide is here.</p><!-- /wp:paragraph -->';
		$target             = new \WP_Post();
		$target->ID         = 7;

		$GLOBALS['dragoninternallinks_test']['posts'][5]      = $post;
		$GLOBALS['dragoninternallinks_test']['posts'][7]      = $target;
		$GLOBALS['dragoninternallinks_test']['permalinks'][7] = 'https://example.test/coffee-beans-guide/';
	}

	protected function tearDown(): void {
		$_POST    = array();
		$_REQUEST = array();
	}

	/**
	 * Call a handler with the given fields, as admin-ajax.php would: the body
	 * is in $_POST and in $_REQUEST.
	 *
	 * @return \DragonInternalLinks_Test_Json_Response|\DragonInternalLinks_Test_Die
	 */
	private function call( string $handler, array $fields ) {
		$_POST    = $fields;
		$_REQUEST = $fields;

		try {
			$this->ajax->$handler();
		} catch ( \DragonInternalLinks_Test_Json_Response | \DragonInternalLinks_Test_Die $end ) {
			return $end;
		}

		$this->fail( 'Handler did not end the request.' );
	}

	private function assert_nothing_happened(): void {
		$this->assertSame( array(), $this->scanner->asked, 'the scanner is not called' );
		$this->assertSame( array(), $this->analyzer->asked, 'the analyzer is not called' );
		$this->assertSame( array(), $GLOBALS['wpdb']->calls, 'the database is not touched' );
		$this->assertSame( array(), $GLOBALS['dragoninternallinks_test']['options'], 'no option is written' );
		$this->assertSame( array(), dragoninternallinks_test_calls( 'wp_update_post' ), 'no post is written' );
	}

	/**
	 * Every handler with the fields admin.js posts to it.
	 */
	public static function handlers(): array {
		return array(
			'scan all'             => array(
				'handle_scan_all',
				array(
					'offset' => '0',
					'failed' => '0',
				),
			),
			'scan post'            => array( 'handle_scan_post', array( 'post_id' => '5' ) ),
			'generate suggestions' => array(
				'handle_generate_suggestions',
				array(
					'offset' => '0',
					'failed' => '0',
					'stale'  => '0',
				),
			),
			'dismiss suggestion'   => array( 'handle_dismiss_suggestion', array( 'suggestion_id' => '9' ) ),
			'apply suggestion'     => array( 'handle_apply_suggestion', array( 'suggestion_id' => '9' ) ),
		);
	}

	#[DataProvider( 'handlers' )]
	public function test_a_request_without_a_nonce_is_refused_before_anything_happens( string $handler, array $fields ): void {
		$end = $this->call( $handler, $fields );

		$this->assertInstanceOf( \DragonInternalLinks_Test_Die::class, $end );
		$this->assertSame( '-1', $end->getMessage() );
		$this->assertSame( 403, $end->status );
		$this->assert_nothing_happened();
	}

	#[DataProvider( 'handlers' )]
	public function test_a_request_with_a_wrong_nonce_is_refused_before_anything_happens( string $handler, array $fields ): void {
		$end = $this->call( $handler, $fields + array( 'nonce' => '0badc0ffee' ) );

		$this->assertInstanceOf( \DragonInternalLinks_Test_Die::class, $end );
		$this->assertSame( 403, $end->status );
		$this->assertSame(
			array( array( '0badc0ffee', self::NONCE_ACTION ) ),
			dragoninternallinks_test_calls( 'wp_verify_nonce' )
		);
		$this->assert_nothing_happened();
	}

	#[DataProvider( 'handlers' )]
	public function test_a_nonce_sent_as_an_array_or_in_another_field_is_refused( string $handler, array $fields ): void {
		$end = $this->call( $handler, $fields + array( 'nonce' => array( 'valid' ) ) );
		$this->assertInstanceOf( \DragonInternalLinks_Test_Die::class, $end );

		$end = $this->call( $handler, $fields + array( 'security' => 'valid' ) );
		$this->assertInstanceOf( \DragonInternalLinks_Test_Die::class, $end );

		$this->assert_nothing_happened();
	}

	#[DataProvider( 'handlers' )]
	public function test_a_user_without_manage_options_is_refused_before_anything_happens( string $handler, array $fields ): void {
		$GLOBALS['dragoninternallinks_test']['can'] = false;

		$end = $this->call( $handler, $fields + array( 'nonce' => 'valid' ) );

		$this->assertInstanceOf( \DragonInternalLinks_Test_Json_Response::class, $end );
		$this->assertFalse( $end->success );
		$this->assertSame( array( 'message' => 'Permission denied.' ), $end->data );
		$this->assert_nothing_happened();
	}

	#[DataProvider( 'handlers' )]
	public function test_a_valid_request_checks_this_plugins_nonce_action_and_succeeds( string $handler, array $fields ): void {
		$GLOBALS['wpdb']->returns['update'] = 1;

		$end = $this->call( $handler, $fields + array( 'nonce' => 'valid' ) );

		$this->assertInstanceOf( \DragonInternalLinks_Test_Json_Response::class, $end );
		$this->assertTrue( $end->success );
		$this->assertSame( array( 'valid', self::NONCE_ACTION ), dragoninternallinks_test_calls( 'wp_verify_nonce' )[0] );
	}

	// -- what each handler does with a valid request ---------------------------

	public function test_scan_all_scans_the_batch_at_the_posted_offset(): void {
		$end = $this->call(
			'handle_scan_all',
			array(
				'nonce'  => 'valid',
				'offset' => '50',
				'failed' => '3',
			)
		);

		$this->assertSame( array( array( 'scan_all', 50, 50 ) ), $this->scanner->asked );
		$this->assertSame( 52, $end->data['offset'] );
		$this->assertSame( 3, $end->data['failed'] );
		$this->assertTrue( $end->data['complete'] );
		$this->assertSame( 2, get_option( 'dragoninternallinks_last_scan_count' ) );
	}

	public function test_scan_post_scans_the_posted_post_and_counts_its_links(): void {
		$end = $this->call(
			'handle_scan_post',
			array(
				'nonce'   => 'valid',
				'post_id' => '5',
			)
		);

		$this->assertSame( array( array( 'scan_post', 5 ) ), $this->scanner->asked );
		$this->assertSame( 2, $end->data['links_found'] );
		$this->assertSame( 'Found 2 internal links.', $end->data['message'] );
	}

	public function test_scan_post_without_a_post_id_is_an_error(): void {
		foreach ( array( array(), array( 'post_id' => '0' ), array( 'post_id' => 'abc' ), array( 'post_id' => '' ) ) as $fields ) {
			$end = $this->call( 'handle_scan_post', $fields + array( 'nonce' => 'valid' ) );

			$this->assertFalse( $end->success );
			$this->assertSame( 'Invalid post ID.', $end->data['message'] );
		}
		$this->assertSame( array(), $this->scanner->asked );
	}

	public function test_generate_suggestions_runs_the_batch_at_the_posted_offset(): void {
		$end = $this->call(
			'handle_generate_suggestions',
			array(
				'nonce'  => 'valid',
				'offset' => '40',
				'failed' => '2',
				'stale'  => '1',
			)
		);

		$this->assertSame( array( array( 'generate', 20, 40 ) ), $this->analyzer->asked );
		$this->assertSame( 60, $end->data['offset'] );
		$this->assertSame( 2, $end->data['failed'] );
		$this->assertTrue( $end->data['stale'] );
		$this->assertTrue( $end->data['done'] );
	}

	/**
	 * The stale flag as admin.js sends it (1 or 0), absent, and empty.
	 */
	public static function stale_flags(): array {
		return array(
			'one'     => array( array( 'stale' => '1' ), true ),
			'zero'    => array( array( 'stale' => '0' ), false ),
			'empty'   => array( array( 'stale' => '' ), false ),
			'missing' => array( array(), false ),
			'true'    => array( array( 'stale' => 'true' ), true ),
		);
	}

	#[DataProvider( 'stale_flags' )]
	public function test_the_stale_flag_carries_over_between_batches( array $fields, bool $expected ): void {
		$end = $this->call( 'handle_generate_suggestions', $fields + array( 'nonce' => 'valid' ) );

		$this->assertSame( $expected, $end->data['stale'] );
	}

	/**
	 * Numbers as the page sends them, and the forms a whole-number read gives.
	 */
	public static function numbers(): array {
		return array(
			'digits'           => array( '9', 9 ),
			'an integer'       => array( 9, 9 ),
			'leading space'    => array( ' 9', 9 ),
			'text after'       => array( '9abc', 9 ),
			'negative'         => array( '-9', 9 ),
			'decimal'          => array( '9.7', 9 ),
		);
	}

	#[DataProvider( 'numbers' )]
	public function test_dismiss_reads_the_suggestion_id_as_a_whole_number( $posted, int $expected ): void {
		$end = $this->call(
			'handle_dismiss_suggestion',
			array(
				'nonce'         => 'valid',
				'suggestion_id' => $posted,
			)
		);

		$this->assertTrue( $end->success );
		$this->assertSame( array( array( 'status', $expected, 'dismissed' ) ), $this->analyzer->asked );
	}

	public function test_dismiss_and_apply_without_a_suggestion_id_are_errors(): void {
		foreach ( array( 'handle_dismiss_suggestion', 'handle_apply_suggestion' ) as $handler ) {
			foreach ( array( array(), array( 'suggestion_id' => '0' ), array( 'suggestion_id' => 'abc' ) ) as $fields ) {
				$end = $this->call( $handler, $fields + array( 'nonce' => 'valid' ) );

				$this->assertFalse( $end->success );
				$this->assertSame( 'Invalid suggestion ID.', $end->data['message'] );
			}
		}
		$this->assertSame( array(), $this->analyzer->asked );
		$this->assertSame( array(), $GLOBALS['wpdb']->calls );
	}

	public function test_apply_adds_the_link_to_the_post_and_marks_the_suggestion_applied(): void {
		$end = $this->call(
			'handle_apply_suggestion',
			array(
				'nonce'         => 'valid',
				'suggestion_id' => '9',
			)
		);

		$this->assertTrue( $end->success );
		$this->assertSame( 'Link added successfully!', $end->data['message'] );

		$writes = dragoninternallinks_test_calls( 'wp_update_post' );
		$this->assertCount( 1, $writes );
		$this->assertSame( 5, $writes[0][0]['ID'] );
		$this->assertSame(
			wp_slash( '<!-- wp:paragraph --><p>Our <a href="https://example.test/coffee-beans-guide/">Coffee Beans Guide</a> is here.</p><!-- /wp:paragraph -->' ),
			$writes[0][0]['post_content']
		);
		$this->assertSame( array( array( 'status', 9, 'applied' ) ), $this->analyzer->asked );
		$this->assertSame( array( array( 'scan_post', 5 ) ), $this->scanner->asked );
	}

	public function test_apply_needs_edit_rights_on_the_post_as_well(): void {
		$GLOBALS['dragoninternallinks_test']['can'] = static fn( string $capability ): bool => 'edit_post' !== $capability;

		$end = $this->call(
			'handle_apply_suggestion',
			array(
				'nonce'         => 'valid',
				'suggestion_id' => '9',
			)
		);

		$this->assertFalse( $end->success );
		$this->assertSame( 'Permission denied.', $end->data['message'] );
		$this->assertSame( array(), dragoninternallinks_test_calls( 'wp_update_post' ) );
		$this->assertSame( array(), $this->analyzer->asked );
	}
}
