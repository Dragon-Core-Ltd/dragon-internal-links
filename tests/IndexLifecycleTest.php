<?php
/**
 * The link index only describes published, in-scope posts: a post that is
 * unpublished, trashed, deleted or excluded leaves it, and the posts it linked
 * to are recounted.
 *
 * @package DragonInternalLinks
 */

namespace DragonInternalLinks\Tests;

use DragonInternalLinks\Scanner;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-scanner.php';

final class IndexLifecycleTest extends TestCase {

	private const SOURCE = 5;

	protected function setUp(): void {
		dragoninternallinks_test_reset();

		$GLOBALS['dragoninternallinks_test']['posts'][ self::SOURCE ] = $this->post( self::SOURCE, 'publish' );
		// The posts it links to, and the one linking in, are published posts.
		foreach ( array( 3, 7, 8 ) as $id ) {
			$GLOBALS['dragoninternallinks_test']['posts'][ $id ] = $this->post( $id, 'publish' );
		}

		// The source post links out to 7 and 8; 3 links in to the source.
		$GLOBALS['wpdb']->returns['get_col'] = static function ( $sql ) {
			if ( str_contains( (string) $sql, 'SELECT DISTINCT target_post_id' ) ) {
				return array( '7', '8' );
			}
			if ( str_contains( (string) $sql, 'SELECT DISTINCT source_post_id' ) ) {
				return array( '3' );
			}
			return array();
		};
	}

	private function post( int $id, string $status, string $type = 'post' ): \WP_Post {
		$post               = new \WP_Post();
		$post->ID           = $id;
		$post->post_status  = $status;
		$post->post_type    = $type;
		$post->post_content = '<p><a href="/hello/">hello</a></p>';
		$post->post_date    = '2026-01-01 00:00:00';
		return $post;
	}

	/** Post IDs whose stats row was rewritten, in order. */
	private function recounted(): array {
		return array_map(
			static fn( $call ) => (int) $call[1]['post_id'],
			$GLOBALS['wpdb']->calls_to( 'replace' )
		);
	}

	/** Source IDs whose outbound link rows were deleted. */
	private function cleared(): array {
		$out = array();
		foreach ( $GLOBALS['wpdb']->calls_to( 'delete' ) as $call ) {
			if ( 'wp_dil_links' === $call[0] && isset( $call[1]['source_post_id'] ) ) {
				$out[] = (int) $call[1]['source_post_id'];
			}
		}
		return $out;
	}

	public function test_unpublishing_a_post_removes_its_links_and_recounts_what_it_linked_to(): void {
		$scanner = new Scanner();

		$scanner->on_status_change( 'draft', 'publish', $this->post( self::SOURCE, 'draft' ) );

		$this->assertSame( array( self::SOURCE ), $this->cleared() );
		$this->assertSame( array( 7, 8 ), $this->recounted(), 'the targets lose an inbound link, so their counts are rewritten' );
	}

	public function test_making_a_post_private_removes_its_links(): void {
		( new Scanner() )->on_status_change( 'private', 'publish', $this->post( self::SOURCE, 'private' ) );

		$this->assertSame( array( self::SOURCE ), $this->cleared() );
	}

	public function test_an_update_that_stays_published_leaves_the_index_to_the_save_handler(): void {
		( new Scanner() )->on_status_change( 'publish', 'publish', $this->post( self::SOURCE, 'publish' ) );

		$this->assertSame( array(), $this->cleared() );
	}

	public function test_trashing_a_post_recounts_the_posts_it_linked_to(): void {
		( new Scanner() )->on_post_trash( self::SOURCE );

		$this->assertSame( array( self::SOURCE ), $this->cleared() );
		$this->assertContains( 7, $this->recounted() );
		$this->assertContains( 8, $this->recounted() );
	}

	public function test_deleting_a_post_removes_its_links_and_its_stats_row(): void {
		( new Scanner() )->on_post_delete( self::SOURCE );

		$this->assertSame( array( self::SOURCE ), $this->cleared() );
		$this->assertSame( array( 7, 8 ), $this->recounted() );
		$this->assertContains( array( 'wp_dil_stats', array( 'post_id' => self::SOURCE ), array( '%d' ) ), $GLOBALS['wpdb']->calls_to( 'delete' ) );
	}

	public function test_scanning_a_post_in_an_excluded_category_removes_it_from_the_index(): void {
		$GLOBALS['dragoninternallinks_test']['options']['dragoninternallinks_exclude_categories'] = array( 12 );
		$GLOBALS['dragoninternallinks_test']['terms'][ self::SOURCE ]['category']                 = array( 12 );
		$GLOBALS['dragoninternallinks_test']['url_to_postid']['https://example.test/hello/']      = 7;

		$links = ( new Scanner() )->scan_post( self::SOURCE );

		$this->assertSame( array(), $links );
		$this->assertSame( array( self::SOURCE ), $this->cleared() );
		$this->assertSame( array(), $GLOBALS['wpdb']->calls_to( 'insert' ) );
	}

	public function test_scanning_an_unpublished_post_removes_it_from_the_index(): void {
		$GLOBALS['dragoninternallinks_test']['posts'][ self::SOURCE ] = $this->post( self::SOURCE, 'pending' );

		( new Scanner() )->scan_post( self::SOURCE );

		$this->assertSame( array( self::SOURCE ), $this->cleared() );
	}

	public function test_scanning_a_post_nobody_indexes_touches_nothing(): void {
		$GLOBALS['dragoninternallinks_test']['posts'][9] = $this->post( 9, 'publish', 'nav_menu_item' );
		$GLOBALS['wpdb']->returns['get_col']              = array();

		( new Scanner() )->scan_post( 9 );

		$this->assertSame( array(), $this->recounted(), 'no stats row is created for a post type that is not scanned' );
	}

	public function test_full_scan_query_skips_excluded_categories(): void {
		$GLOBALS['dragoninternallinks_test']['options']['dragoninternallinks_exclude_categories'] = array( 12, 14 );

		( new Scanner() )->scan_all( 50, 0 );

		$args = dragoninternallinks_test_calls( 'WP_Query' )[0][0];
		$this->assertSame( array( 12, 14 ), $args['category__not_in'] );
	}

	public function test_first_batch_of_a_full_scan_prunes_rows_left_by_posts_no_longer_indexed(): void {
		$GLOBALS['dragoninternallinks_test']['options']['dragoninternallinks_exclude_categories'] = array( 12 );

		( new Scanner() )->scan_all( 50, 0 );

		$prune = $this->prune_queries();
		$this->assertCount( 1, $prune, 'rows written before these hooks existed are removed once per full scan' );
		$this->assertStringContainsString( "post_status <> 'publish'", $prune[0][0] );
		$this->assertStringContainsString( 'post_type NOT IN', $prune[0][0] );
		$this->assertStringContainsString( 'term_taxonomy', $prune[0][0] );
		$this->assertContains( 12, $prune[0][1] );
	}

	public function test_later_batches_do_not_prune_again(): void {
		( new Scanner() )->scan_all( 50, 50 );

		$this->assertSame( array(), $this->prune_queries() );
	}

	public function test_stats_are_recalculated_for_posts_whose_last_link_was_removed(): void {
		( new Scanner() )->recalculate_all_stats();

		$prepared = $GLOBALS['wpdb']->calls_to( 'prepare' );
		$union    = array_values( array_filter( $prepared, static fn( $call ) => str_contains( $call[0], 'UNION' ) ) );
		$this->assertContains( 'wp_dil_stats', $union[0], 'a post only in the stats table would otherwise keep its old count' );
	}

	public function test_a_link_url_longer_than_its_column_is_shortened_instead_of_failing_the_post(): void {
		$long = '/hello/?' . str_repeat( 'q', 600 );
		$GLOBALS['dragoninternallinks_test']['posts'][ self::SOURCE ]->post_content = '<p><a href="' . $long . '">hello</a></p>';
		$GLOBALS['dragoninternallinks_test']['permalinks'][ self::SOURCE ]          = 'https://example.test/source/';
		$GLOBALS['dragoninternallinks_test']['url_to_postid']['https://example.test/hello/?' . str_repeat( 'q', 600 )] = 7;
		$GLOBALS['wpdb']->returns['insert'] = 1;
		$GLOBALS['wpdb']->returns['delete'] = 1;
		$GLOBALS['wpdb']->returns['query']  = 1;

		$scanner = new Scanner();
		$scanner->scan_post( self::SOURCE );

		$insert = $GLOBALS['wpdb']->calls_to( 'insert' );
		$this->assertCount( 1, $insert );
		$this->assertSame( 500, mb_strlen( $insert[0][1]['link_url'] ) );
		$this->assertFalse( $scanner->scan_failed() );
	}

	/** DELETE statements aimed at the links table, with their prepare args. */
	private function prune_queries(): array {
		$out = array();
		foreach ( $GLOBALS['wpdb']->calls_to( 'prepare' ) as $call ) {
			if ( str_starts_with( ltrim( $call[0] ), 'DELETE' ) ) {
				$out[] = array( $call[0], array_slice( $call, 1 ) );
			}
		}
		return $out;
	}

	public function test_a_failed_prune_is_reported_for_the_whole_pass(): void {
		$GLOBALS['wpdb']->returns['query'] = false;
		$scanner                           = new Scanner();

		$this->assertFalse( $scanner->scan_all( 50, 0 )['pruned'], 'a pass that could not remove stale rows is not a clean rebuild' );

		$GLOBALS['wpdb']->returns['query'] = 1;
		$this->assertFalse( $scanner->scan_all( 50, 50 )['pruned'], 'the last batch, which reports completion, still says so' );

		$this->assertTrue( $scanner->scan_all( 50, 0 )['pruned'], 'a new pass that prunes cleanly clears the flag' );
	}

	public function test_auto_scan_runs_once_terms_are_saved(): void {
		// The block editor saves through REST: save_post fires before the
		// categories are written, wp_after_insert_post after.
		new Scanner();

		$hooks = array_column( dragoninternallinks_test_calls( 'add_action' ), 1, 0 );
		$this->assertArrayHasKey( 'wp_after_insert_post', $hooks );
		$this->assertSame( 'on_post_save', $hooks['wp_after_insert_post'][1] );
		$this->assertArrayNotHasKey( 'save_post', $hooks );
	}

	public function test_saving_a_post_type_that_is_not_scanned_writes_no_stats(): void {
		$GLOBALS['dragoninternallinks_test']['posts'][9] = $this->post( 9, 'publish', 'nav_menu_item' );

		( new Scanner() )->on_post_save( 9, $GLOBALS['dragoninternallinks_test']['posts'][9] );

		$this->assertSame( array(), $this->recounted() );
	}

	public function test_stats_are_not_kept_for_posts_outside_the_scan(): void {
		$GLOBALS['dragoninternallinks_test']['posts'][9]  = $this->post( 9, 'draft' );
		$GLOBALS['dragoninternallinks_test']['posts'][10] = $this->post( 10, 'publish', 'attachment' );
		$scanner = new Scanner();

		$scanner->update_post_stats( 9 );
		$scanner->update_post_stats( 10 );
		$scanner->update_post_stats( 404 );

		$this->assertSame( array(), $this->recounted(), 'no row is written for a draft, an unscanned type or a missing post' );
		$deleted = array_column( array_filter( $GLOBALS['wpdb']->calls_to( 'delete' ), static fn( $call ) => 'wp_dil_stats' === $call[0] ), 1 );
		$this->assertSame( array( array( 'post_id' => 9 ), array( 'post_id' => 10 ), array( 'post_id' => 404 ) ), $deleted, 'an old row is removed' );
	}
}
