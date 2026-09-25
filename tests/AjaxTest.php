<?php
/**
 * Ajax handler tests: apply and dismiss suggestion outcomes.
 *
 * @package DragonInternalLinks
 */

namespace DragonInternalLinks\Tests;

use DragonInternalLinks\Ajax;
use DragonInternalLinks\Analyzer;
use DragonInternalLinks\Scanner;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-scanner.php';
require_once __DIR__ . '/../includes/class-analyzer.php';
require_once __DIR__ . '/../includes/class-linker.php';
require_once __DIR__ . '/../includes/class-ajax.php';

/**
 * Scanner whose re-scan after apply is a recorded no-op.
 */
final class AjaxTestScanner extends Scanner {
	public array $scanned = array();

	public function scan_post( int $post_id ): array {
		$this->scanned[] = $post_id;
		return array();
	}
}

/**
 * Scanner and analyzer whose batch results each test sets.
 */
final class AjaxTestBatchScanner extends Scanner {
	public array $result = array();

	public function scan_all( int $batch_size = 50, int $offset = 0 ): array {
		return $this->result;
	}
}

final class AjaxTestBatchAnalyzer extends Analyzer {
	public array $result = array();

	public function generate_all_suggestions( int $batch_size = 20, int $offset = 0 ): array {
		return $this->result;
	}
}

final class AjaxTest extends TestCase {

	private AjaxTestScanner $scanner;
	private Ajax $ajax;

	protected function setUp(): void {
		dragoninternallinks_test_reset();
		$_POST = array( 'suggestion_id' => '9' );

		$this->scanner = new AjaxTestScanner();
		$this->ajax    = new Ajax( $this->scanner, new Analyzer( $this->scanner ) );

		$GLOBALS['wpdb']->returns['get_row'] = array(
			'id'             => 9,
			'source_post_id' => 5,
			'target_post_id' => 7,
			'keyword'        => 'coffee beans guide',
		);
		$GLOBALS['wpdb']->returns['update']  = 1;

		$post                                                = new \WP_Post();
		$post->ID                                            = 5;
		$post->post_content                                  = '<!-- wp:paragraph --><p>Our Coffee Beans Guide is here.</p><!-- /wp:paragraph -->';
		$GLOBALS['dragoninternallinks_test']['posts'][5]      = $post;
		$GLOBALS['dragoninternallinks_test']['permalinks'][7] = 'https://example.test/coffee-beans-guide/';

		$target                                           = new \WP_Post();
		$target->ID                                       = 7;
		$GLOBALS['dragoninternallinks_test']['posts'][7] = $target;
	}

	protected function tearDown(): void {
		$_POST = array();
	}

	private function apply(): \DragonInternalLinks_Test_Json_Response {
		try {
			$this->ajax->handle_apply_suggestion();
		} catch ( \DragonInternalLinks_Test_Json_Response $response ) {
			return $response;
		}
		$this->fail( 'Handler did not send a JSON response.' );
	}

	private function dismiss(): \DragonInternalLinks_Test_Json_Response {
		try {
			$this->ajax->handle_dismiss_suggestion();
		} catch ( \DragonInternalLinks_Test_Json_Response $response ) {
			return $response;
		}
		$this->fail( 'Handler did not send a JSON response.' );
	}

	private function status_updates(): array {
		return $GLOBALS['wpdb']->calls_to( 'update' );
	}

	public function test_apply_links_keyword_marks_applied_and_rescans(): void {
		$response = $this->apply();

		$this->assertTrue( $response->success );

		$writes = dragoninternallinks_test_calls( 'wp_update_post' );
		$this->assertCount( 1, $writes );
		$this->assertSame(
			wp_slash( '<!-- wp:paragraph --><p>Our <a href="https://example.test/coffee-beans-guide/">Coffee Beans Guide</a> is here.</p><!-- /wp:paragraph -->' ),
			$writes[0][0]['post_content']
		);
		$this->assertTrue( $writes[0][1] );

		$updates = $this->status_updates();
		$this->assertCount( 1, $updates );
		$this->assertSame( array( 'status' => 'applied' ), $updates[0][1] );
		$this->assertSame( array( 'id' => 9 ), $updates[0][2] );
		$this->assertSame( array( 5 ), $this->scanner->scanned );
	}

	public function test_apply_reports_wp_error_before_touching_the_suggestion(): void {
		$GLOBALS['dragoninternallinks_test']['update_post'] = new \WP_Error( 'db', 'nope' );

		$response = $this->apply();

		$this->assertFalse( $response->success );
		$this->assertSame( array(), $this->status_updates() );
		$this->assertSame( array(), $this->scanner->scanned );
	}

	public function test_apply_reports_zero_result_before_touching_the_suggestion(): void {
		$GLOBALS['dragoninternallinks_test']['update_post'] = 0;

		$response = $this->apply();

		$this->assertFalse( $response->success );
		$this->assertSame( array(), $this->status_updates() );
	}

	public function test_apply_reports_failed_status_write(): void {
		$GLOBALS['wpdb']->returns['update'] = false;

		$response = $this->apply();

		$this->assertFalse( $response->success );
		$this->assertCount( 1, dragoninternallinks_test_calls( 'wp_update_post' ) );
	}

	public function test_apply_reports_missing_keyword_without_saving(): void {
		$GLOBALS['dragoninternallinks_test']['posts'][5]->post_content = '<p>nothing here</p>';

		$response = $this->apply();

		$this->assertFalse( $response->success );
		$this->assertSame( 'Could not find keyword in content.', $response->data['message'] );
		$this->assertSame( array(), dragoninternallinks_test_calls( 'wp_update_post' ) );
		$this->assertSame( array(), $this->status_updates() );
	}

	public function test_apply_does_not_link_inside_attributes(): void {
		$GLOBALS['dragoninternallinks_test']['posts'][5]->post_content = '<p><img alt="coffee beans guide"></p>';

		$response = $this->apply();

		$this->assertFalse( $response->success );
		$this->assertSame( array(), dragoninternallinks_test_calls( 'wp_update_post' ) );
	}

	public function test_apply_reports_invalid_utf8_without_saving(): void {
		$GLOBALS['dragoninternallinks_test']['posts'][5]->post_content = "<p>caf\xE9 coffee beans guide</p>";

		$response = $this->apply();

		$this->assertFalse( $response->success );
		$this->assertSame( array(), dragoninternallinks_test_calls( 'wp_update_post' ) );
		$this->assertSame( array(), $this->status_updates() );
	}

	public function test_dismiss_reports_failed_write(): void {
		$GLOBALS['wpdb']->returns['update'] = false;

		$response = $this->dismiss();

		$this->assertFalse( $response->success );
	}

	public function test_dismiss_succeeds_when_written(): void {
		$response = $this->dismiss();

		$this->assertTrue( $response->success );
		$this->assertSame( array( 'status' => 'dismissed' ), $this->status_updates()[0][1] );
	}

	public function test_completion_message_warns_when_stale_links_could_not_be_removed(): void {
		$message = new \ReflectionMethod( Ajax::class, 'scan_message' );
		$message->setAccessible( true );

		$result = array(
			'complete' => true,
			'total'    => 3,
			'offset'   => 3,
			'pruned'   => false,
		);

		$this->assertStringContainsString( 'could not be removed', $message->invoke( null, $result, 0 ) );

		$result['pruned'] = true;
		$this->assertStringNotContainsString( 'could not be removed', $message->invoke( null, $result, 0 ) );
	}

	/**
	 * @return iterable<string,array{0:callable}>
	 */
	public static function stale_targets(): iterable {
		yield 'drafted' => array( static fn( \WP_Post $post ) => $post->post_status = 'draft' );
		yield 'trashed' => array( static fn( \WP_Post $post ) => $post->post_status = 'trash' );
		yield 'excluded' => array(
			static function ( \WP_Post $post ) {
				$GLOBALS['dragoninternallinks_test']['options']['dragoninternallinks_exclude_categories'] = array( 4 );
				$GLOBALS['dragoninternallinks_test']['terms'][ $post->ID ]['category']                  = array( 4 );
			},
		);
		yield 'type no longer scanned' => array( static fn( \WP_Post $post ) => $post->post_type = 'product' );
	}

	public function test_apply_refuses_a_source_that_is_no_longer_published(): void {
		$GLOBALS['dragoninternallinks_test']['posts'][5]->post_status = 'draft';

		$this->assertFalse( $this->apply()->success );
		$this->assertSame( array(), dragoninternallinks_test_calls( 'wp_update_post' ) );
	}

	/**
	 * @dataProvider stale_targets
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'stale_targets' )]
	public function test_apply_refuses_a_target_that_has_left_the_scan( callable $change ): void {
		$change( $GLOBALS['dragoninternallinks_test']['posts'][7] );

		$response = $this->apply();

		$this->assertFalse( $response->success );
		$this->assertSame( array(), dragoninternallinks_test_calls( 'wp_update_post' ) );
		$this->assertSame( array(), $this->status_updates() );
	}

	private function send( callable $handler ): \DragonInternalLinks_Test_Json_Response {
		try {
			$handler();
		} catch ( \DragonInternalLinks_Test_Json_Response $response ) {
			return $response;
		}
		$this->fail( 'Handler did not send a JSON response.' );
	}

	public function test_scan_failures_add_up_across_batches_and_hold_the_page(): void {
		$scanner         = new AjaxTestBatchScanner();
		$scanner->result = array(
			'scanned'  => 50,
			'total'    => 100,
			'offset'   => 100,
			'complete' => true,
			'failed'   => 2,
			'pruned'   => true,
		);
		$ajax            = new Ajax( $scanner, new Analyzer( $scanner ) );

		$_POST = array(
			'offset' => '50',
			'failed' => '3',
		);
		$response = $this->send( array( $ajax, 'handle_scan_all' ) );

		$this->assertTrue( $response->success );
		$this->assertSame( 5, $response->data['failed'] );
		$this->assertStringContainsString( '5 posts could not be indexed', $response->data['message'] );
		$this->assertTrue( $response->data['warning'] );

		$_POST            = array( 'offset' => '0' );
		$scanner->result['failed'] = 0;
		$response = $this->send( array( $ajax, 'handle_scan_all' ) );
		$this->assertSame( 0, $response->data['failed'] );
		$this->assertFalse( $response->data['warning'] );

		$scanner->result['pruned'] = false;
		$response = $this->send( array( $ajax, 'handle_scan_all' ) );
		$this->assertTrue( $response->data['warning'] );
	}

	public function test_suggestion_failures_add_up_across_batches_and_hold_the_page(): void {
		$scanner          = new Scanner();
		$analyzer         = new AjaxTestBatchAnalyzer( $scanner );
		$analyzer->result = array(
			'generated' => 4,
			'failed'    => 1,
			'stale'     => false,
			'offset'    => 40,
			'total'     => 40,
			'done'      => true,
		);
		$ajax             = new Ajax( $scanner, $analyzer );

		$_POST = array(
			'offset' => '20',
			'failed' => '2',
			'stale'  => '1',
		);
		$response = $this->send( array( $ajax, 'handle_generate_suggestions' ) );

		$this->assertSame( 3, $response->data['failed'] );
		$this->assertStringContainsString( '3 suggestions could not be saved', $response->data['message'] );
		$this->assertTrue( $response->data['stale'] );
		$this->assertTrue( $response->data['warning'] );
	}
}
