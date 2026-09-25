<?php
/**
 * Links from a published post to a target that is trashed, deleted or not
 * published stay in the index on every scan, so Link Health keeps reporting
 * them until the link is fixed or the target is published again.
 *
 * @package DragonInternalLinks
 */

namespace DragonInternalLinks\Tests;

use DragonInternalLinks\Scanner;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-scanner.php';

final class LinkHealthTest extends TestCase {

	private const SOURCE = 5;
	private const TARGET = 9;
	private const HREF   = 'https://example.test/old-announcement/';

	/** Candidate rows the unpublished-target lookup returns. */
	private array $candidates = array();

	/** Rows already in the index for the source post. */
	private array $prior = array();

	protected function setUp(): void {
		dragoninternallinks_test_reset();

		$source               = $this->post( self::SOURCE, 'publish', 'source' );
		$source->post_content = '<p>Read <a href="' . self::HREF . '">the old announcement</a> for background on this.</p>';
		$GLOBALS['dragoninternallinks_test']['posts'][ self::SOURCE ]      = $source;
		$GLOBALS['dragoninternallinks_test']['permalinks'][ self::SOURCE ] = 'https://example.test/source/';

		$GLOBALS['wpdb']->returns['insert']      = 1;
		$GLOBALS['wpdb']->returns['delete']      = 1;
		$GLOBALS['wpdb']->returns['query']       = 1;
		$GLOBALS['wpdb']->returns['get_results'] = function ( $sql ) {
			$sql = (string) $sql;
			if ( str_contains( $sql, '_wp_desired_post_slug' ) ) {
				return $this->candidates;
			}
			if ( str_contains( $sql, 'SELECT link_url, target_post_id' ) ) {
				return $this->prior;
			}
			return array();
		};
	}

	private function post( int $id, string $status, string $name ): \WP_Post {
		$post              = new \WP_Post();
		$post->ID          = $id;
		$post->post_status = $status;
		$post->post_type   = 'post';
		$post->post_name   = $name;
		$post->post_date   = '2026-01-01 00:00:00';
		return $post;
	}

	/** Target IDs written to the links table, in order. */
	private function stored_targets(): array {
		$out = array();
		foreach ( $GLOBALS['wpdb']->calls_to( 'insert' ) as $call ) {
			if ( 'wp_dil_links' === $call[0] ) {
				$out[] = (int) $call[1]['target_post_id'];
			}
		}
		return $out;
	}

	public function test_a_link_to_a_post_trashed_before_the_first_scan_is_indexed(): void {
		// Trashing renames the slug to "old-announcement__trashed", so the URL in
		// the content no longer resolves; the desired slug is kept in post meta.
		$GLOBALS['dragoninternallinks_test']['posts'][ self::TARGET ] = $this->post( self::TARGET, 'trash', 'old-announcement__trashed' );
		// get_permalink() is asked about the post as it would be published again.
		$GLOBALS['dragoninternallinks_test']['permalinks'][ self::TARGET ] = self::HREF;
		$this->candidates = array(
			array(
				'ID'           => (string) self::TARGET,
				'post_status'  => 'trash',
				'post_name'    => 'old-announcement__trashed',
				'desired_slug' => 'old-announcement',
			),
		);

		( new Scanner() )->scan_post( self::SOURCE );

		$this->assertSame( array( self::TARGET ), $this->stored_targets() );
	}

	public function test_the_lookup_matches_the_last_path_segment_against_trashed_and_unpublished_slugs(): void {
		( new Scanner() )->scan_post( self::SOURCE );

		$lookup = array_values(
			array_filter(
				$GLOBALS['wpdb']->calls_to( 'prepare' ),
				static fn( $call ) => str_contains( (string) $call[0], '_wp_desired_post_slug' )
			)
		);

		$this->assertCount( 1, $lookup, 'one lookup per post, not one per link' );
		$this->assertContains( 'old-announcement', array_slice( $lookup[0], 1 ) );
		$this->assertStringContainsString( "'trash'", $lookup[0][0] );
		$this->assertStringContainsString( "'draft'", $lookup[0][0] );
		$this->assertStringNotContainsString( '_wp_old_slug', $lookup[0][0], 'an old slug redirects, so it is not broken' );
	}

	public function test_a_link_to_a_draft_is_indexed_even_when_url_to_postid_cannot_see_it(): void {
		$GLOBALS['dragoninternallinks_test']['posts'][ self::TARGET ]      = $this->post( self::TARGET, 'draft', 'old-announcement' );
		$GLOBALS['dragoninternallinks_test']['permalinks'][ self::TARGET ] = self::HREF;
		$this->candidates = array(
			array(
				'ID'           => (string) self::TARGET,
				'post_status'  => 'draft',
				'post_name'    => 'old-announcement',
				'desired_slug' => null,
			),
		);

		( new Scanner() )->scan_post( self::SOURCE );

		$this->assertSame( array( self::TARGET ), $this->stored_targets() );
	}

	public function test_a_candidate_whose_permalink_is_a_different_url_is_not_used(): void {
		// A trashed post named "old-announcement" lives at a dated URL; the link
		// points somewhere else with the same last segment.
		$GLOBALS['dragoninternallinks_test']['posts'][ self::TARGET ]      = $this->post( self::TARGET, 'trash', 'old-announcement__trashed' );
		$GLOBALS['dragoninternallinks_test']['permalinks'][ self::TARGET ] = 'https://example.test/2020/01/old-announcement/';
		$this->candidates = array(
			array(
				'ID'           => (string) self::TARGET,
				'post_status'  => 'trash',
				'post_name'    => 'old-announcement__trashed',
				'desired_slug' => 'old-announcement',
			),
		);

		( new Scanner() )->scan_post( self::SOURCE );

		$this->assertSame( array(), $this->stored_targets() );
	}

	public function test_a_link_to_a_deleted_post_keeps_its_indexed_target_on_rescan(): void {
		// Indexed while the target existed; the target has since been deleted.
		$this->prior = array(
			array(
				'link_url'       => self::HREF,
				'target_post_id' => (string) self::TARGET,
			),
		);

		( new Scanner() )->scan_post( self::SOURCE );

		$this->assertSame( array( self::TARGET ), $this->stored_targets() );
	}

	public function test_a_link_to_a_post_trashed_after_indexing_keeps_its_target_on_rescan(): void {
		$GLOBALS['dragoninternallinks_test']['posts'][ self::TARGET ] = $this->post( self::TARGET, 'trash', 'old-announcement__trashed' );
		$this->prior = array(
			array(
				'link_url'       => self::HREF,
				'target_post_id' => (string) self::TARGET,
			),
		);

		( new Scanner() )->scan_post( self::SOURCE );

		$this->assertSame( array( self::TARGET ), $this->stored_targets() );
	}

	public function test_an_indexed_target_that_is_still_published_is_not_carried_forward(): void {
		// The URL no longer resolves, but the post it pointed at is live: its slug
		// changed and core redirects the old one, so the link is not broken.
		$GLOBALS['dragoninternallinks_test']['posts'][ self::TARGET ] = $this->post( self::TARGET, 'publish', 'renamed' );
		$this->prior = array(
			array(
				'link_url'       => self::HREF,
				'target_post_id' => (string) self::TARGET,
			),
		);

		( new Scanner() )->scan_post( self::SOURCE );

		$this->assertSame( array(), $this->stored_targets() );
	}

	public function test_a_url_that_resolves_wins_over_the_indexed_target(): void {
		// Restored and published again: the URL resolves to the post itself.
		$GLOBALS['dragoninternallinks_test']['posts'][ self::TARGET ]          = $this->post( self::TARGET, 'publish', 'old-announcement' );
		$GLOBALS['dragoninternallinks_test']['url_to_postid'][ self::HREF ] = self::TARGET;
		$this->prior = array(
			array(
				'link_url'       => self::HREF,
				'target_post_id' => '44',
			),
		);

		( new Scanner() )->scan_post( self::SOURCE );

		$this->assertSame( array( self::TARGET ), $this->stored_targets() );
		$this->assertSame(
			array(),
			array_filter(
				$GLOBALS['wpdb']->calls_to( 'prepare' ),
				static fn( $call ) => str_contains( (string) $call[0], '_wp_desired_post_slug' )
			),
			'no lookup runs when every link resolves'
		);
	}

	public function test_an_unresolved_internal_url_with_no_known_target_is_not_indexed(): void {
		// A category archive, a custom route or the home page: never a post.
		( new Scanner() )->scan_post( self::SOURCE );

		$this->assertSame( array(), $this->stored_targets() );
	}
}
