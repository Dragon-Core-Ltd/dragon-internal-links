<?php
/**
 * Analyzer tests: suggestion storage results and keyword extraction.
 *
 * @package DragonInternalLinks
 */

namespace DragonInternalLinks\Tests;

use DragonInternalLinks\Analyzer;
use DragonInternalLinks\Scanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-scanner.php';
require_once __DIR__ . '/../includes/class-analyzer.php';
require_once __DIR__ . '/../includes/class-linker.php';

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

	private function source_post( int $id, string $content ): \WP_Post {
		$post               = new \WP_Post();
		$post->ID           = $id;
		$post->post_status  = 'publish';
		$post->post_content = $content;
		$post->post_title   = 'Source';
		$GLOBALS['dragoninternallinks_test']['posts'][ $id ] = $post;
		return $post;
	}

	public function test_dismissed_targets_are_not_suggested_again(): void {
		$this->source_post( 1, '<p>text</p>' );
		$GLOBALS['wpdb']->returns['get_col'] = static function ( $sql ) {
			return str_contains( (string) $sql, "status = 'dismissed'" ) ? array( '9' ) : array();
		};

		( new Analyzer( new Scanner() ) )->generate_suggestions_for_post( 1 );

		$args = dragoninternallinks_test_calls( 'get_posts' )[0][0];
		$this->assertContains( 9, $args['exclude'], 'a dismissal must survive the daily regeneration' );
		$this->assertContains( 1, $args['exclude'] );
	}

	public function test_a_source_in_an_excluded_category_gets_no_suggestions(): void {
		$this->source_post( 1, '<p>text</p>' );
		$GLOBALS['dragoninternallinks_test']['options']['dragoninternallinks_exclude_categories'] = array( 4 );
		$GLOBALS['dragoninternallinks_test']['terms'][1]['category']                              = array( 4 );

		$this->assertSame( array(), ( new Analyzer( new Scanner() ) )->generate_suggestions_for_post( 1 ) );
		$this->assertSame( array(), dragoninternallinks_test_calls( 'get_posts' ) );
	}

	public function test_targets_in_an_excluded_category_are_not_suggested(): void {
		$this->source_post( 1, '<p>text</p>' );
		$GLOBALS['dragoninternallinks_test']['options']['dragoninternallinks_exclude_categories'] = array( 4 );

		( new Analyzer( new Scanner() ) )->generate_suggestions_for_post( 1 );

		$this->assertSame( array( 4 ), dragoninternallinks_test_calls( 'get_posts' )[0][0]['category__not_in'] );
	}

	public function test_suggestion_pass_skips_excluded_categories(): void {
		$GLOBALS['dragoninternallinks_test']['options']['dragoninternallinks_exclude_categories'] = array( 4 );

		( new AnalyzerTestCanned( new Scanner() ) )->generate_all_suggestions( 20, 0 );

		$this->assertSame( array( 4 ), dragoninternallinks_test_calls( 'WP_Query' )[0][0]['category__not_in'] );
	}

	public function test_short_phrase_respects_the_minimum_keyword_words_setting(): void {
		$analyzer = new Analyzer( new Scanner() );

		// Two meaningful words, below a minimum of four.
		$this->assertSame( array(), $this->call_private( $analyzer, 'extract_keywords', array( 'The Coffee Beans', 4 ) ) );
		// A minimum of one allows a single-word phrase.
		$this->assertSame( array( 'Espresso' ), $this->call_private( $analyzer, 'extract_keywords', array( 'Espresso', 1 ) ) );
		// The short phrase is a run of adjacent words, never words joined across a stop word.
		$this->assertSame(
			array( 'Guide to Coffee Beans' ),
			$this->call_private( $analyzer, 'extract_keywords', array( 'Guide to Coffee Beans', 3 ) )
		);
		$this->assertSame(
			array( 'Best Coffee Grinders for Beginners', 'Best Coffee Grinders' ),
			$this->call_private( $analyzer, 'extract_keywords', array( 'Best Coffee Grinders for Beginners', 3 ) )
		);
	}

	/**
	 * @return iterable<string,array{0:string,1:string}>
	 */
	public static function punctuated_titles(): iterable {
		yield 'apostrophe' => array( "Beginner's Guide to Espresso Machines", "<p>Read our Beginner's Guide to Espresso Machines before buying.</p>" );
		yield 'curly apostrophe in content' => array( "Beginner's Guide to Espresso Machines", '<p>Read our Beginner’s Guide to Espresso Machines before buying.</p>' );
		yield 'apostrophe entity in content' => array( "Beginner's Guide to Espresso Machines", '<p>Read our Beginner&#8217;s Guide to Espresso Machines before buying.</p>' );
		yield 'hyphen' => array( 'Wi-Fi Setup for Smart Homes', '<p>See Wi-Fi Setup for Smart Homes for details.</p>' );
		yield 'colon' => array( 'Espresso: The Complete Guide', '<p>Our Espresso: The Complete Guide covers it.</p>' );
		yield 'ampersand' => array( 'Salt & Pepper Grinders', '<p>Compare Salt &amp; Pepper Grinders here.</p>' );
		yield 'encoded ampersand in title' => array( 'Salt &amp; Pepper Grinders', '<p>Compare Salt &amp; Pepper Grinders here.</p>' );
		yield 'trailing question mark' => array( 'What Is Cold Brew Coffee?', '<p>Wondering what is cold brew coffee and why it tastes sweet.</p>' );
	}

	#[DataProvider( 'punctuated_titles' )]
	public function test_titles_with_punctuation_produce_a_suggestion( string $title, string $content ): void {
		$analyzer = new Analyzer( new Scanner() );
		$found    = null;

		foreach ( $this->call_private( $analyzer, 'extract_keywords', array( $title, 3 ) ) as $keyword ) {
			$found = $found ?? $this->call_private( $analyzer, 'find_keyword_context', array( $content, $keyword ) );
		}

		$this->assertNotNull( $found );
	}

	public function test_a_keyword_inside_a_longer_word_is_not_a_match(): void {
		$analyzer = new Analyzer( new Scanner() );

		$this->assertNull( $this->call_private( $analyzer, 'find_keyword_context', array( '<p>Browse every category here.</p>', 'cat' ) ) );
		$this->assertNull( $this->call_private( $analyzer, 'find_keyword_context', array( '<p>Tom &amp; Jerry</p>', 'amp' ) ) );
		$this->assertNull( $this->call_private( $analyzer, 'find_keyword_context', array( '<p>Les cafés sont ouverts.</p>', 'café' ) ) );
		$content = '<p>Browse every category. ' . str_repeat( 'filler ', 50 ) . 'Then feed the cat daily and more words follow here.</p>';
		$this->assertStringContainsString(
			'feed the cat',
			(string) $this->call_private( $analyzer, 'find_keyword_context', array( $content, 'Cat' ) ),
			'the context is taken around the whole-word match, not the first substring'
		);
	}

	public function test_nothing_the_linker_cannot_link_is_suggested(): void {
		$analyzer = new Analyzer( new Scanner() );
		$context  = fn( string $content, string $keyword ) => $this->call_private( $analyzer, 'find_keyword_context', array( $content, $keyword ) );

		$GLOBALS['shortcode_tags'] = array(
			'button'  => '__return_empty_string',
			'caption' => '__return_empty_string',
		);

		$this->assertNull( $context( '<p>[button text="Coffee Grinders"]</p>', 'Coffee Grinders' ), 'shortcode attribute' );
		$this->assertNull( $context( '<figure><img src="x.png"/><figcaption>Coffee Grinders</figcaption></figure>', 'Coffee Grinders' ), 'block caption' );
		$this->assertNull( $context( '[caption id="a"]<img src="x.png" /> Coffee Grinders[/caption]', 'Coffee Grinders' ), 'classic caption' );
		$this->assertNull( $context( '<p>cat<strong>egory</strong></p>', 'cat' ), 'a word continued across an inline tag' );
		$this->assertNotNull( $context( '<p>cat</p><p>egory</p>', 'cat' ), 'a block tag ends the word' );
		$this->assertStringNotContainsString( 'b">', (string) $context( '<p>the <strong title="a > b">cat</strong> food</p>', 'cat' ), 'a quoted ">" does not end the tag' );
		$this->assertNotNull( $context( '<p>the <strong title="a > b">cat</strong> food</p>', 'cat' ) );
	}

	public function test_a_keyword_the_linker_would_have_to_split_across_tags_is_not_suggested(): void {
		$analyzer = new Analyzer( new Scanner() );
		$context  = fn( string $content, string $keyword ) => $this->call_private( $analyzer, 'find_keyword_context', array( $content, $keyword ) );

		$this->assertNull( $context( '<p>Best coffee <em>grinders</em> for home.</p>', 'Best coffee grinders' ), 'an inline tag inside the phrase' );
		$this->assertNull( $context( '<p>Best coffee<br>grinders for home.</p>', 'Best coffee grinders' ), 'a line break inside the phrase' );
		$this->assertNull( $context( '<p>Best <a href="/x">coffee grinders</a> for home.</p>', 'coffee grinders' ), 'already inside a link' );
	}

	public function test_context_is_taken_around_the_occurrence_the_linker_links(): void {
		$analyzer = new Analyzer( new Scanner() );
		$content  = '<p>Best coffee <em>grinders</em> first. ' . str_repeat( 'filler ', 40 ) . 'Later the best coffee grinders appear plainly.</p>';

		$context = (string) $this->call_private( $analyzer, 'find_keyword_context', array( $content, 'Best coffee grinders' ) );

		$this->assertStringContainsString( 'Later the best coffee grinders appear', $context );
		$this->assertStringNotContainsString( "\u{E000}", $context );
		$this->assertStringNotContainsString( "\u{E001}", $context );
	}

	public function test_every_suggested_keyword_can_be_applied(): void {
		$analyzer = new Analyzer( new Scanner() );
		$linker   = new \DragonInternalLinks\Linker();
		$fixtures = array(
			array( '<p>Best coffee <em>grinders</em>, and best coffee grinders.</p>', 'Best coffee grinders' ),
			array( '<p>the <strong title="a > b">cat</strong> food</p>', 'cat' ),
			array( '<p>cat</p><p>egory</p>', 'cat' ),
			array( '<!-- wp:paragraph --><p>Grind <b>fresh</b> beans daily.</p><!-- /wp:paragraph -->', 'fresh beans' ),
			array( '<!-- wp:paragraph --><p>Grind fresh beans daily.</p><!-- /wp:paragraph -->', 'fresh beans' ),
		);

		foreach ( $fixtures as $fixture ) {
			$context = $this->call_private( $analyzer, 'find_keyword_context', $fixture );
			if ( null !== $context ) {
				$this->assertNotNull( $linker->insert( $fixture[0], $fixture[1], 'https://example.test/t/' ), 'suggested but cannot be applied: ' . $fixture[0] );
			}
		}
	}

	public function test_pending_suggestions_leave_out_sources_that_left_the_scan(): void {
		$GLOBALS['dragoninternallinks_test']['options']['dragoninternallinks_exclude_categories'] = array( 4 );
		$analyzer = new Analyzer( new Scanner() );

		$analyzer->get_suggestions( 50 );
		$analyzer->get_summary();

		$list  = $this->prepared( 'ORDER BY s.relevance_score' )[0];
		$count = $this->prepared( 'JOIN wp_posts p ON s.target_post_id = p.ID' );

		$this->assertStringContainsString( 'sp.post_type IN', $list[0] );
		$this->assertStringContainsString( 'tr.object_id = sp.ID', $list[0], 'a source in an excluded category is left out' );
		$this->assertSame( array( 'wp_dil_suggestions', 'post', 'page', 4, 'post', 'page', 4, 50 ), array_slice( $list, 1 ) );

		$filter = substr( $list[0], strpos( $list[0], 'WHERE' ), strpos( $list[0], 'ORDER BY' ) - strpos( $list[0], 'WHERE' ) );
		$this->assertCount( 2, $count );
		$this->assertStringContainsString( trim( $filter ), $count[1][0], 'the dashboard count uses the same filter' );
		$this->assertSame( array_slice( $list, 1, -1 ), array_slice( $count[1], 1 ) );
	}

	/** SQL handed to prepare() that contains a fragment. */
	private function prepared( string $fragment ): array {
		return array_values(
			array_filter(
				$GLOBALS['wpdb']->calls_to( 'prepare' ),
				static fn( $call ) => str_contains( $call[0], $fragment )
			)
		);
	}

	public function test_dashboard_orphan_count_uses_the_same_filter_as_the_orphan_list(): void {
		$GLOBALS['dragoninternallinks_test']['options']['dragoninternallinks_exclude_categories'] = array( 4 );
		$analyzer = new Analyzer( new Scanner() );

		$analyzer->get_orphan_posts( 50 );
		$analyzer->get_summary();

		$list  = $this->prepared( 'ORDER BY s.orphan_score' )[0];
		$count = $this->prepared( 'COUNT(*) FROM %i s' )[0];

		$filter = substr( $list[0], strpos( $list[0], 'WHERE' ), strpos( $list[0], 'ORDER BY' ) - strpos( $list[0], 'WHERE' ) );
		$this->assertStringContainsString( "p.post_status = 'publish'", $filter );
		$this->assertStringContainsString( 'term_taxonomy', $filter, 'excluded categories are left out of the list' );
		$this->assertStringContainsString( trim( $filter ), $count[0], 'the count must not include posts the list filters out' );
		$this->assertSame( array_slice( $list, 1, -1 ), array_slice( $count, 1 ) );
	}

	public function test_summary_counts_only_posts_the_reports_cover(): void {
		$GLOBALS['dragoninternallinks_test']['options']['dragoninternallinks_exclude_categories'] = array( 4 );
		$analyzer = new Analyzer( new Scanner() );

		$analyzer->get_summary();

		$totals = $this->prepared( 'AVG(s.inbound_count)' );
		$this->assertCount( 1, $totals );
		$this->assertStringContainsString( "p.post_status = 'publish'", $totals[0][0] );
		$this->assertStringContainsString( 'p.post_type IN', $totals[0][0] );
		$this->assertStringContainsString( 'term_taxonomy', $totals[0][0] );
		$unscoped = array_filter(
			$GLOBALS['wpdb']->calls_to( 'prepare' ),
			static fn( $call ) => str_contains( $call[0], 'FROM %i' ) && ! str_contains( $call[0], 'JOIN' ) && in_array( 'wp_dil_stats', $call, true )
		);
		$this->assertSame( array(), $unscoped, 'no stats figure is read from the whole table' );
	}

	public function test_pending_suggestions_leave_out_targets_that_left_the_scan(): void {
		$analyzer = new Analyzer( new Scanner() );

		$analyzer->get_suggestions( 50 );
		$analyzer->get_summary();

		$list  = $this->prepared( 'ORDER BY s.relevance_score' );
		$count = $this->prepared( 'JOIN wp_posts p ON s.target_post_id = p.ID' );
		$this->assertCount( 2, $count, 'the list and the dashboard count use the same filter' );
		$this->assertStringContainsString( "p.post_status = 'publish'", $list[0][0] );
		$this->assertStringContainsString( 'p.post_type IN', $list[0][0] );
	}

	public function test_orphan_reports_with_no_post_types_do_not_run_invalid_sql(): void {
		$GLOBALS['dragoninternallinks_test']['options']['dragoninternallinks_post_types'] = array();
		$analyzer = new Analyzer( new Scanner() );

		$this->assertSame( array(), $analyzer->get_orphan_posts( 50 ) );
		$this->assertSame( 0, $analyzer->get_summary()['orphan_posts'] );
		$this->assertSame( array(), $this->prepared( 'IN ()' ) );
	}

	public function test_low_outbound_list_leaves_out_excluded_categories(): void {
		// Excluded posts have no indexed links, so they would all look like
		// posts with too few outbound links.
		$GLOBALS['dragoninternallinks_test']['options']['dragoninternallinks_exclude_categories'] = array( 4 );

		( new Analyzer( new Scanner() ) )->get_low_outbound_posts( 50, 2 );

		$call = $this->prepared( 's.outbound_count <= %d' )[0];
		$this->assertStringContainsString( 'NOT EXISTS', $call[0] );
		$this->assertSame( array( 'wp_dil_stats', 2, 'post', 'page', 4, 50 ), array_slice( $call, 1 ) );
	}
}
