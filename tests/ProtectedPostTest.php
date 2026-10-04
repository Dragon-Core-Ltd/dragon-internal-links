<?php
/**
 * Password-protected posts are left out of suggestions as the post linked
 * from and as the page linked to, so their text is never sent to the AI
 * provider or stored as a suggestion's context.
 *
 * @package DragonInternalLinks
 */

namespace DragonInternalLinks\Tests;

use DragonInternalLinks\Analyzer;
use DragonInternalLinks\Crypto;
use DragonInternalLinks\Scanner;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-scanner.php';
require_once __DIR__ . '/../includes/class-analyzer.php';
require_once __DIR__ . '/../includes/class-linker.php';
require_once __DIR__ . '/../includes/class-relevance.php';
require_once __DIR__ . '/../includes/class-crypto.php';
require_once __DIR__ . '/../includes/class-ai-ranker.php';

final class ProtectedPostTest extends TestCase {

	private const POSTS = array(
		11 => array( 'Growing Tomatoes in Containers', 'Tomatoes grow well in containers when you give them deep pots, rich compost and full sun. Water tomatoes daily and feed tomatoes weekly. Read composting basics for beginners first.' ),
		12 => array( 'Composting Basics for Beginners', 'Composting turns kitchen scraps into rich compost. CONFIDENTIAL-BODY keep the compost heap moist. Good compost feeds tomatoes in containers.' ),
		13 => array( 'Pruning Roses in Winter', 'Pruning roses keeps them healthy. Feed roses with compost. Growing tomatoes in containers is easier than roses.' ),
	);

	protected function setUp(): void {
		dragoninternallinks_test_reset();

		$options = &$GLOBALS['dragoninternallinks_test']['options'];

		$options['dragoninternallinks_min_word_count'] = 3;
		$options['dragoninternallinks_ai_enabled']     = true;
		$options['dragoninternallinks_ai_provider']    = 'openai';
		$options['dragoninternallinks_ai_api_key']     = Crypto::encrypt( 'sk-test' );

		$GLOBALS['dragoninternallinks_test']['http'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"choices":[{"message":{"content":"{\"1\":80,\"2\":70}"}}]}',
		);

		foreach ( self::POSTS as $id => $post ) {
			$object               = new \WP_Post();
			$object->ID           = $id;
			$object->post_title   = $post[0];
			$object->post_content = '<!-- wp:paragraph --><p>' . $post[1] . '</p><!-- /wp:paragraph -->';
			$object->post_date    = '2026-01-01 00:00:00';

			$GLOBALS['dragoninternallinks_test']['posts'][ $id ]      = $object;
			$GLOBALS['dragoninternallinks_test']['permalinks'][ $id ] = 'https://example.test/p' . $id . '/';
		}
	}

	private function protect( int $id ): void {
		$GLOBALS['dragoninternallinks_test']['posts'][ $id ]->post_password = 'secret';
	}

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

	private function sent_prompts(): string {
		$out = '';
		foreach ( dragoninternallinks_test_calls( 'wp_safe_remote_post' ) as $call ) {
			$out .= (string) $call[1]['body'];
		}
		return $out;
	}

	public function test_without_a_password_the_posts_are_suggested_and_ranked(): void {
		$suggestions = $this->suggest( 11 );

		$this->assertContains( 12, array_map( 'intval', array_column( $suggestions, 'target_post_id' ) ) );
		$this->assertStringContainsString( 'CONFIDENTIAL-BODY', $this->sent_prompts(), 'the fixture reaches the provider when unprotected' );
	}

	public function test_a_protected_source_gets_no_suggestions_and_nothing_is_sent(): void {
		$this->protect( 12 );

		$this->assertSame( array(), $this->suggest( 12 ) );
		$this->assertSame( array(), dragoninternallinks_test_calls( 'wp_safe_remote_post' ) );
	}

	public function test_a_protected_target_is_never_suggested_or_sent(): void {
		$this->protect( 12 );

		$suggestions = $this->suggest( 11 );

		$this->assertNotContains( 12, array_map( 'intval', array_column( $suggestions, 'target_post_id' ) ) );
		$this->assertStringNotContainsString( 'CONFIDENTIAL-BODY', $this->sent_prompts() );
		$this->assertStringNotContainsString( 'Composting Basics', $this->sent_prompts() );

		$args = dragoninternallinks_test_calls( 'get_posts' )[0][0];
		$this->assertFalse( $args['has_password'] );
	}

	public function test_a_full_generation_pass_leaves_protected_posts_out(): void {
		$GLOBALS['dragoninternallinks_test']['query_posts'] = array( 12 );
		$GLOBALS['dragoninternallinks_test']['query_found'] = 1;
		$GLOBALS['wpdb']->returns['delete']                 = 1;
		$this->protect( 12 );

		$result = ( new Analyzer( new Scanner() ) )->generate_all_suggestions( 20, 0 );

		$this->assertSame( 0, $result['generated'] );
		$this->assertFalse( dragoninternallinks_test_calls( 'WP_Query' )[0][0]['has_password'] );
		$this->assertSame( array(), $GLOBALS['wpdb']->calls_to( 'insert' ) );
		$this->assertSame( array(), dragoninternallinks_test_calls( 'wp_safe_remote_post' ) );
	}
}
