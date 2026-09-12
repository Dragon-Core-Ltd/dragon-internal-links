<?php
/**
 * Analyzer tests: suggestion storage results and keyword extraction.
 *
 * @package DragonInternalLinks
 */

namespace DragonInternalLinks\Tests;

use DragonInternalLinks\Analyzer;
use DragonInternalLinks\Scanner;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-scanner.php';
require_once __DIR__ . '/../includes/class-analyzer.php';

/**
 * Analyzer with canned per-post suggestions.
 */
final class AnalyzerTestCanned extends Analyzer {
	public array $canned = array();

	public function generate_suggestions_for_post( int $post_id ): array {
		return $this->canned[ $post_id ] ?? array();
	}
}

final class AnalyzerTest extends TestCase {

	protected function setUp(): void {
		dragoninternallinks_test_reset();
	}

	private function suggestion( int $target ): array {
		return array(
			'source_post_id' => 1,
			'target_post_id' => $target,
			'keyword'        => 'k',
			'context'        => 'c',
			'relevance'      => 1.0,
		);
	}

	private function call_private( object $object, string $method, array $args ) {
		$ref = new \ReflectionMethod( $object, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $object, $args );
	}

	public function test_store_suggestion_reports_insert_result(): void {
		$analyzer = new Analyzer( new Scanner() );

		$GLOBALS['wpdb']->returns['insert'] = 1;
		$this->assertTrue( $this->call_private( $analyzer, 'store_suggestion', array( $this->suggestion( 2 ) ) ) );

		$GLOBALS['wpdb']->returns['insert'] = false;
		$this->assertFalse( $this->call_private( $analyzer, 'store_suggestion', array( $this->suggestion( 2 ) ) ) );
	}

	public function test_generate_all_counts_only_stored_suggestions(): void {
		$analyzer         = new AnalyzerTestCanned( new Scanner() );
		$analyzer->canned = array( 1 => array( $this->suggestion( 2 ), $this->suggestion( 3 ) ) );

		$GLOBALS['dragoninternallinks_test']['query_posts'] = array( 1 );
		$GLOBALS['dragoninternallinks_test']['query_found'] = 1;
		$GLOBALS['wpdb']->returns['insert']                 = array( 'queue' => array( false, 1 ) );

		$result = $analyzer->generate_all_suggestions( 20, 0 );

		$this->assertSame( 1, $result['generated'] );
		$this->assertTrue( $result['done'] );
	}

	public function test_update_suggestion_status_reports_update_result(): void {
		$analyzer = new Analyzer( new Scanner() );

		$GLOBALS['wpdb']->returns['update'] = 1;
		$this->assertTrue( $analyzer->update_suggestion_status( 9, 'applied' ) );

		$GLOBALS['wpdb']->returns['update'] = false;
		$this->assertFalse( $analyzer->update_suggestion_status( 9, 'applied' ) );
	}

	public function test_zero_rows_is_success_only_when_the_row_already_holds_that_status(): void {
		$analyzer = new Analyzer( new Scanner() );

		// MySQL reports 0 changed rows when the value is already what was asked
		// for, which is a genuine success.
		$GLOBALS['wpdb']->returns['update']  = 0;
		$GLOBALS['wpdb']->returns['get_var'] = 'applied';

		$this->assertTrue( $analyzer->update_suggestion_status( 9, 'applied' ) );
	}

	public function test_dismissing_a_suggestion_that_no_longer_exists_is_not_a_success(): void {
		$analyzer = new Analyzer( new Scanner() );

		// 0 changed rows also happens when the row was deleted, and telling the
		// user it was dismissed would be untrue.
		$GLOBALS['wpdb']->returns['update']  = 0;
		$GLOBALS['wpdb']->returns['get_var'] = null;

		$this->assertFalse( $analyzer->update_suggestion_status( 9, 'dismissed' ) );
	}

	public function test_zero_rows_with_a_different_stored_status_is_not_a_success(): void {
		$analyzer = new Analyzer( new Scanner() );

		$GLOBALS['wpdb']->returns['update']  = 0;
		$GLOBALS['wpdb']->returns['get_var'] = 'pending';

		$this->assertFalse( $analyzer->update_suggestion_status( 9, 'dismissed' ) );
	}

	public function test_extract_keywords_falls_back_to_raw_title_on_invalid_utf8(): void {
		$analyzer = new Analyzer( new Scanner() );
		$title    = "Caf\xE9 coffee beans guide";

		$keywords = $this->call_private( $analyzer, 'extract_keywords', array( $title, 3 ) );

		$this->assertContains( $title, $keywords );
	}

	public function test_extract_keywords_caps_long_titles_at_a_word_boundary(): void {
		$analyzer = new Analyzer( new Scanner() );
		$words    = array();
		for ( $i = 0; $i < 60; $i++ ) {
			$words[] = 'word' . str_pad( (string) $i, 2, '0', STR_PAD_LEFT );
		}
		$title = implode( ' ', $words );
		$this->assertGreaterThan( 255, strlen( $title ) );

		$keywords = $this->call_private( $analyzer, 'extract_keywords', array( $title, 3 ) );

		$full = $keywords[0];
		$this->assertLessThanOrEqual( 255, mb_strlen( $full ) );
		$this->assertStringStartsWith( $full . ' ', $title );
		$this->assertSame( implode( ' ', array_slice( $words, 0, 36 ) ), $full );
	}

	public function test_generate_all_reports_suggestions_it_could_not_store(): void {
		// Pagination advances and the run completes either way, so a silent
		// failure looks like "analyzed every post, found nothing".
		$analyzer         = new AnalyzerTestCanned( new Scanner() );
		$analyzer->canned = array( 1 => array( $this->suggestion( 2 ), $this->suggestion( 3 ) ) );

		$GLOBALS['dragoninternallinks_test']['query_posts'] = array( 1 );
		$GLOBALS['dragoninternallinks_test']['query_found'] = 1;
		$GLOBALS['wpdb']->returns['insert']                 = false;

		$result = $analyzer->generate_all_suggestions( 20, 0 );

		$this->assertSame( 0, $result['generated'] );
		$this->assertSame( 2, $result['failed'], 'Both inserts were refused.' );
	}

	public function test_generate_all_reports_a_failed_clear_of_old_pending_rows(): void {
		// If the old pending rows cannot be cleared, the new pass is mixed in
		// with a previous one and the totals on screen are not a fresh result.
		$analyzer         = new AnalyzerTestCanned( new Scanner() );
		$analyzer->canned = array();

		$GLOBALS['dragoninternallinks_test']['query_posts'] = array( 1 );
		$GLOBALS['dragoninternallinks_test']['query_found'] = 1;
		$GLOBALS['wpdb']->returns['delete']                 = false;

		$result = $analyzer->generate_all_suggestions( 20, 0 );

		$this->assertTrue( $result['stale'], 'The caller is told the list was not cleared.' );
	}

	public function test_a_clean_run_reports_no_failures(): void {
		$analyzer         = new AnalyzerTestCanned( new Scanner() );
		$analyzer->canned = array( 1 => array( $this->suggestion( 2 ) ) );

		$GLOBALS['dragoninternallinks_test']['query_posts'] = array( 1 );
		$GLOBALS['dragoninternallinks_test']['query_found'] = 1;
		$GLOBALS['wpdb']->returns['insert']                 = 1;
		$GLOBALS['wpdb']->returns['delete']                 = 1;

		$result = $analyzer->generate_all_suggestions( 20, 0 );

		$this->assertSame( 1, $result['generated'] );
		$this->assertSame( 0, $result['failed'] );
		$this->assertFalse( $result['stale'] );
	}
}
