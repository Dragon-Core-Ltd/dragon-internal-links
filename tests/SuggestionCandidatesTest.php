<?php
/**
 * Suggestion candidates come from each target's distinctive terms (title
 * n-grams and its top TF-IDF terms), found as whole words in the source's
 * linkable text, not only from the target's title appearing verbatim.
 *
 * @package DragonInternalLinks
 */

namespace DragonInternalLinks\Tests;

use DragonInternalLinks\Analyzer;
use DragonInternalLinks\Linker;
use DragonInternalLinks\Scanner;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-scanner.php';
require_once __DIR__ . '/../includes/class-analyzer.php';
require_once __DIR__ . '/../includes/class-linker.php';
require_once __DIR__ . '/../includes/class-relevance.php';
require_once __DIR__ . '/../includes/class-crypto.php';
require_once __DIR__ . '/../includes/class-ai-ranker.php';

final class SuggestionCandidatesTest extends TestCase {

	/** The acceptance run's five gardening posts. */
	private const GARDEN = array(
		11 => array( 'Growing Tomatoes in Containers', 'Tomatoes grow well in containers when you give them deep pots, rich compost and full sun. Water tomatoes daily in summer and feed tomatoes weekly with a potash feed. Container tomatoes need support from canes.' ),
		12 => array( 'Composting Basics for Beginners', 'Composting turns kitchen scraps and garden waste into rich compost. Balance green and brown material, keep the compost heap moist, and turn it monthly. Good compost feeds tomatoes, roses and vegetables.' ),
		13 => array( 'Pruning Roses in Winter', 'Pruning roses in winter keeps them healthy. Cut out dead wood, open the centre and feed roses with compost in spring. Roses in containers need extra water.' ),
		14 => array( 'Watering Your Vegetable Garden', 'Watering vegetables deeply and less often builds deep roots. Morning watering reduces disease. Container vegetables such as tomatoes dry out fast and need watering daily.' ),
		15 => array( 'Orphan Guide to Garden Birds', 'Garden birds visit feeders all year. Robins, blue tits and blackbirds enjoy seeds and fat balls. Birds also eat pests in the vegetable garden.' ),
	);

	protected function setUp(): void {
		dragoninternallinks_test_reset();
		$GLOBALS['dragoninternallinks_test']['options']['dragoninternallinks_min_word_count'] = 3;
	}

	private function add_post( int $id, string $title, string $content ): \WP_Post {
		$post               = new \WP_Post();
		$post->ID           = $id;
		$post->post_title   = $title;
		$post->post_content = $content;
		$post->post_date    = '2026-01-01 00:00:00';
		$GLOBALS['dragoninternallinks_test']['posts'][ $id ]      = $post;
		$GLOBALS['dragoninternallinks_test']['permalinks'][ $id ] = 'https://example.test/p' . $id . '/';
		return $post;
	}

	private function seed_garden(): void {
		foreach ( self::GARDEN as $id => $post ) {
			$this->add_post( $id, $post[0], '<!-- wp:paragraph --><p>' . $post[1] . '</p><!-- /wp:paragraph -->' );
		}
	}

	/** Suggestions for a source, with every other post as a possible target. */
	private function suggest( int $source ): array {
		$targets = array();
		foreach ( $GLOBALS['dragoninternallinks_test']['posts'] as $id => $post ) {
			if ( $id !== $source ) {
				$targets[] = $post;
			}
		}
		$GLOBALS['dragoninternallinks_test']['get_posts'] = $targets;

		return ( new Analyzer( new Scanner() ) )->generate_suggestions_for_post( $source );
	}

	/** Target ID => keyword. */
	private function anchors( array $suggestions ): array {
		$out = array();
		foreach ( $suggestions as $s ) {
			$out[ (int) $s['target_post_id'] ] = strtolower( (string) $s['keyword'] );
		}
		return $out;
	}

	public function test_related_posts_whose_titles_never_appear_verbatim_still_get_suggestions(): void {
		$this->seed_garden();

		$total = 0;
		foreach ( array_keys( self::GARDEN ) as $source ) {
			$total += count( $this->suggest( $source ) );
		}

		$this->assertGreaterThanOrEqual( 5, $total );
	}

	public function test_a_composting_post_links_the_topics_other_posts_are_about(): void {
		$this->seed_garden();

		$anchors = $this->anchors( $this->suggest( 12 ) );

		$this->assertSame( 'tomatoes', $anchors[11] ?? null );
		$this->assertSame( 'roses', $anchors[13] ?? null );
	}

	public function test_a_title_phrase_found_in_the_source_is_used(): void {
		$this->seed_garden();

		$anchors = $this->anchors( $this->suggest( 15 ) );

		$this->assertSame( 'vegetable garden', $anchors[14] ?? null );
	}

	public function test_every_suggestion_can_be_applied_and_links_the_text_as_written(): void {
		$this->seed_garden();

		foreach ( array_keys( self::GARDEN ) as $source ) {
			$content = $GLOBALS['dragoninternallinks_test']['posts'][ $source ]->post_content;
			foreach ( $this->suggest( $source ) as $s ) {
				$linked = ( new Linker() )->insert( $content, (string) $s['keyword'], 'https://example.test/t/' );
				$this->assertNotNull( $linked, "suggested \"{$s['keyword']}\" for post {$source} cannot be applied" );
				$this->assertMatchesRegularExpression( '#<a href="https://example.test/t/">' . preg_quote( (string) $s['keyword'], '#' ) . '</a>#i', (string) $linked );
			}
		}
	}

	public function test_one_anchor_is_never_offered_for_two_targets(): void {
		$this->seed_garden();

		foreach ( array_keys( self::GARDEN ) as $source ) {
			$keywords = array_map( static fn( $s ) => strtolower( (string) $s['keyword'] ), $this->suggest( $source ) );
			$this->assertSame( array_values( array_unique( $keywords ) ), $keywords );
		}

		// Two pages whose only candidate in the source is "compost".
		dragoninternallinks_test_reset();
		$this->add_post( 1, 'Source', '<p>Compost feeds everything. Rich soil too.</p>' );
		$this->add_post( 2, 'Mulch Tips', '<p>Mulch with compost. Compost keeps soil rich.</p>' );
		$this->add_post( 3, 'Bed Prep', '<p>Dig compost in. Compost makes soil rich.</p>' );
		$this->add_post( 4, 'Ponds', '<p>Ponds attract frogs.</p>' );
		$this->add_post( 5, 'Paths', '<p>Gravel paths drain well.</p>' );

		$this->assertCount( 1, $this->suggest( 1 ) );
	}

	public function test_a_word_every_post_uses_is_not_a_candidate_on_its_own(): void {
		// "gravel" is shared too, so only how common "garden" is stops it.
		$this->add_post( 1, 'Source', '<p>Every garden needs a plan for the garden. Gravel matters.</p>' );
		$this->add_post( 2, 'Garden Paths', '<p>A garden path in the garden. Gravel paths drain well.</p>' );
		$this->add_post( 3, 'Garden Sheds', '<p>A garden shed for the garden. Timber sheds need treating.</p>' );
		$this->add_post( 4, 'Garden Ponds', '<p>A garden pond in the garden. Ponds attract frogs.</p>' );

		$this->assertSame( array(), $this->suggest( 1 ) );
	}

	public function test_text_inside_a_heading_or_an_existing_link_is_not_suggested(): void {
		// "sun" is shared too, so only where the word sits stops the suggestion.
		$this->add_post( 1, 'Source', '<h2>Tomatoes</h2><p>See <a href="https://example.test/x/">tomatoes</a> elsewhere. Give them sun.</p>' );
		$this->add_post( 2, 'Growing Tomatoes', '<p>Tomatoes like sun. Tomatoes need water.</p>' );
		$this->add_post( 3, 'Pruning Roses', '<p>Roses like pruning.</p>' );

		$this->assertSame( array(), $this->suggest( 1 ) );
	}

	public function test_apply_skips_text_inside_a_heading(): void {
		$content = '<h2>Growing tomatoes</h2><p>Our tomatoes grew well.</p>';

		$this->assertSame(
			'<h2>Growing tomatoes</h2><p>Our <a href="https://example.test/t/">tomatoes</a> grew well.</p>',
			( new Linker() )->insert( $content, 'tomatoes', 'https://example.test/t/' )
		);
		$this->assertNull( ( new Linker() )->insert( '<h3 class="x">Tomatoes</h3><hr><header>x</header>', 'tomatoes', 'https://example.test/t/' ) );
		$this->assertStringNotContainsString( 'Tomatoes', Linker::matchable_text( '<h3 class="x">Tomatoes</h3><hr><p>kept</p>' ) );
		$this->assertStringContainsString( 'kept', Linker::matchable_text( '<h3 class="x">Tomatoes</h3><hr><p>kept</p>' ) );
	}

	public function test_a_content_word_used_once_is_not_a_candidate(): void {
		// Every word Sheds shares with the source appears once in its content.
		$this->add_post( 1, 'Source', '<p>Potash and compost.</p>' );
		$this->add_post( 2, 'Sheds', '<p>Potash is sold near the compost bins.</p>' );
		$this->add_post( 3, 'Paths', '<p>Gravel paths drain well.</p>' );
		$this->add_post( 4, 'Ponds', '<p>Ponds attract frogs.</p>' );

		$this->assertSame( array(), $this->suggest( 1 ) );

		// Used twice, the same word qualifies.
		$this->add_post( 2, 'Sheds', '<p>Potash is sold near the compost bins. Store potash dry.</p>' );
		$this->assertSame( array( 2 => 'potash' ), $this->anchors( $this->suggest( 1 ) ) );
	}

	public function test_a_single_word_needs_another_shared_term(): void {
		// Only "dead" links these two posts: one is about roses, one about URLs.
		$this->add_post( 1, 'Pruning', '<p>Cut out dead wood when pruning roses.</p>' );
		$this->add_post( 2, 'Link Checks', '<p>A dead link, another dead link, a dead domain.</p>' );
		$this->add_post( 3, 'Ponds', '<p>Ponds attract frogs.</p>' );
		$this->add_post( 4, 'Paths', '<p>Gravel paths drain well.</p>' );

		$this->assertSame( array(), $this->suggest( 1 ) );
	}

	public function test_the_dry_run_inserts_per_post_are_capped(): void {
		// Many targets, each with a distinctive word the source mentions and one
		// more term it shares with the source.
		$words = array();
		for ( $i = 0; $i < 91; $i++ ) {
			$words[] = 'zq' . str_repeat( chr( 97 + intdiv( $i, 26 ) ), 2 ) . chr( 97 + ( $i % 26 ) ) . 'word';
		}
		for ( $i = 0; $i < 90; $i++ ) {
			$this->add_post( 100 + $i, ucfirst( $words[ $i ] ), '<p>' . $words[ $i ] . ' ' . $words[ $i ] . ' ' . $words[ $i + 1 ] . ' notes.</p>' );
		}
		$this->add_post( 1, 'Source', '<p>' . implode( ' ', $words ) . '</p>' );

		$this->suggest( 1 );

		$this->assertSame( Analyzer::MAX_PROBES_PER_POST, count( dragoninternallinks_test_calls( 'parse_blocks' ) ) );
	}
}
