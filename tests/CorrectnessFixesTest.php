<?php
/**
 * Link counts follow every change to a post's links, self links are not
 * inbound links, suggestion context stays valid UTF-8, Apply only acts on
 * pending suggestions, the Orphan Posts headings show real totals, the
 * schema version is stamped only over real tables, tokens fold case in any
 * script, and uninstall removes only this plugin's legacy options.
 *
 * @package DragonInternalLinks
 */

namespace DragonInternalLinks\Tests;

use DragonInternalLinks\Admin;
use DragonInternalLinks\Ajax;
use DragonInternalLinks\Analyzer;
use DragonInternalLinks\Plugin;
use DragonInternalLinks\Relevance;
use DragonInternalLinks\Scanner;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-crypto.php';
require_once __DIR__ . '/../includes/class-ai-ranker.php';
require_once __DIR__ . '/../includes/class-scanner.php';
require_once __DIR__ . '/../includes/class-analyzer.php';
require_once __DIR__ . '/../includes/class-relevance.php';
require_once __DIR__ . '/../includes/class-linker.php';
require_once __DIR__ . '/../includes/class-scheduler.php';
require_once __DIR__ . '/../includes/class-admin.php';
require_once __DIR__ . '/../includes/class-ajax.php';
require_once __DIR__ . '/../includes/class-plugin.php';

defined( 'DRAGONINTERNALLINKS_VERSION' ) || define( 'DRAGONINTERNALLINKS_VERSION', '1.1.10' );
defined( 'DRAGONINTERNALLINKS_PLUGIN_BASENAME' ) || define( 'DRAGONINTERNALLINKS_PLUGIN_BASENAME', 'dragon-internal-links/dragon-internal-links.php' );
defined( 'WP_UNINSTALL_PLUGIN' ) || define( 'WP_UNINSTALL_PLUGIN', 'dragon-internal-links/dragon-internal-links.php' );

/**
 * Analyzer whose orphan and low-outbound lists and totals each test sets.
 */
final class CorrectnessFixesAnalyzer extends Analyzer {
	public array $orphans      = array();
	public int $orphan_total   = 0;
	public array $low          = array();
	public int $low_total      = 0;

	public function get_orphan_posts( int $limit = 50 ): array {
		return array_slice( $this->orphans, 0, $limit );
	}

	public function count_orphans(): int {
		return $this->orphan_total;
	}

	public function get_low_outbound_posts( int $limit = 50, int $max_outbound = 2 ): array {
		return array_slice( $this->low, 0, $limit );
	}

	public function count_low_outbound( int $max_outbound = 2 ): int {
		return $this->low_total;
	}
}

final class CorrectnessFixesTest extends TestCase {

	protected function setUp(): void {
		dragoninternallinks_test_reset();
		$_POST    = array();
		$_REQUEST = array();
	}

	protected function tearDown(): void {
		$_POST    = array();
		$_REQUEST = array();
	}

	private function post( int $id, string $content = '' ): \WP_Post {
		$post               = new \WP_Post();
		$post->ID           = $id;
		$post->post_title   = 'Post ' . $id;
		$post->post_content = $content;
		$post->post_date    = '2026-01-01 00:00:00';

		$GLOBALS['dragoninternallinks_test']['posts'][ $id ]      = $post;
		$GLOBALS['dragoninternallinks_test']['permalinks'][ $id ] = 'https://example.test/p' . $id . '/';
		$GLOBALS['dragoninternallinks_test']['url_to_postid'][ 'https://example.test/p' . $id . '/' ] = $id;

		return $post;
	}

	/**
	 * Post IDs whose stats row was rewritten, in order.
	 */
	private function recounted(): array {
		return array_map( static fn( $call ) => (int) $call[1]['post_id'], $GLOBALS['wpdb']->calls_to( 'replace' ) );
	}

	// -- C1: the posts a save links to, or stopped linking to, are recounted ---

	public function test_saving_a_post_recounts_the_posts_it_now_links_to_and_no_longer_links_to(): void {
		$this->post( 2 );
		$this->post( 3 );
		$this->post( 4 );
		$source = $this->post( 1, '<p>See <a href="https://example.test/p2/">two</a> and <a href="https://example.test/p4/">four</a>.</p>' );

		$wpdb                         = $GLOBALS['wpdb'];
		$wpdb->returns['query']       = 1;
		$wpdb->returns['delete']      = 1;
		$wpdb->returns['insert']      = 1;
		$wpdb->returns['get_col']     = array();
		$wpdb->returns['get_results'] = array(
			array(
				'link_url'       => 'https://example.test/p3/',
				'target_post_id' => 3,
			),
			array(
				'link_url'       => 'https://example.test/p4/',
				'target_post_id' => 4,
			),
		);

		( new Scanner() )->on_post_save( 1, $source );

		$recounted = $this->recounted();
		$this->assertContains( 2, $recounted, 'a newly linked post leaves the orphan list at once' );
		$this->assertContains( 3, $recounted, 'a post that lost its link is recounted at once' );
		$this->assertContains( 4, $recounted );
		$this->assertContains( 1, $recounted );
	}

	public function test_a_failed_index_write_recounts_nothing(): void {
		$this->post( 2 );
		$source = $this->post( 1, '<p>See <a href="https://example.test/p2/">two</a>.</p>' );

		$GLOBALS['wpdb']->returns['query']       = 1;
		$GLOBALS['wpdb']->returns['delete']      = 1;
		$GLOBALS['wpdb']->returns['insert']      = false;
		$GLOBALS['wpdb']->returns['get_results'] = array();

		$scanner = new Scanner();
		$scanner->scan_post( 1 );

		$this->assertTrue( $scanner->scan_failed() );
		$this->assertSame( array(), $this->recounted() );
		unset( $source );
	}

	// -- C7: a link from a post to itself is not an inbound link ---------------

	public function test_the_inbound_count_leaves_out_a_posts_links_to_itself(): void {
		$this->post( 5 );

		( new Scanner() )->update_post_stats( 5 );

		$inbound = null;
		foreach ( $GLOBALS['wpdb']->calls_to( 'prepare' ) as $args ) {
			if ( str_contains( (string) $args[0], 'WHERE target_post_id = %d' ) ) {
				$inbound = $args;
			}
		}

		$this->assertNotNull( $inbound );
		$this->assertStringContainsString( 'source_post_id <> %d', (string) $inbound[0] );
		$this->assertSame( array( 'wp_dil_links', 5, 5 ), array_slice( $inbound, 1 ) );
	}

	// -- C4: the context is cut on characters, never inside one ----------------

	public static function multibyte_texts(): array {
		return array(
			'Russian' => array(
				'<p>' . str_repeat( 'Использование высококачественного оборудования обеспечивает превосходный результат. ', 4 ) . 'Мы любим PAD холодный кофе каждый день. ' . str_repeat( 'Приготовление напитка требует терпения, внимательности, последовательности. ', 5 ) . '</p>',
				'холодный кофе',
			),
			'German'  => array(
				'<p>' . str_repeat( 'Die Geschäftsführerversammlung beschließt Qualitätsverbesserungsmaßnahmen für Kaffeeröstereien. ', 4 ) . 'Wir lieben PAD kalten Kaffee jeden Tag. ' . str_repeat( 'Die Zubereitungsmöglichkeiten erfordern Geduld und größtmögliche Sorgfältigkeitsüberprüfungen. ', 5 ) . '</p>',
				'kalten Kaffee',
			),
			'Greek'   => array(
				'<p>' . str_repeat( 'Αλληλοεπικαλυπτόμενες διαδικασίες προετοιμασίας απαιτούν υπομονή. ', 5 ) . 'Μας αρέσει PAD κρύος καφές κάθε μέρα. ' . str_repeat( 'Η παρασκευή απαιτεί υπομονή, προσοχή, συνέπεια και ακρίβεια. ', 5 ) . '</p>',
				'κρύος καφές',
			),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'multibyte_texts' )]
	public function test_the_suggestion_context_is_always_valid_utf8( string $content, string $keyword ): void {
		$analyzer = new Analyzer( new Scanner() );
		$context  = new \ReflectionMethod( $analyzer, 'find_keyword_context' );
		$context->setAccessible( true );

		$found = 0;
		for ( $pad = 0; $pad < 60; $pad++ ) {
			$text = str_replace( 'PAD', str_repeat( 'a', $pad % 12 ) . ( $pad >= 12 ? ' ' . str_repeat( 'b', intdiv( $pad, 12 ) ) : '' ), $content );
			$out  = $context->invoke( $analyzer, $text, $keyword );
			if ( null === $out ) {
				continue;
			}
			++$found;
			$this->assertTrue( mb_check_encoding( $out, 'UTF-8' ), 'pad ' . $pad . ': ' . bin2hex( substr( $out, 0, 6 ) ) . '...' . bin2hex( substr( $out, -6 ) ) );
			$this->assertStringContainsString( $keyword, $out );
		}

		$this->assertSame( 60, $found );
	}

	// -- C9: Apply only acts on a pending suggestion ---------------------------

	public function test_apply_refuses_a_suggestion_that_is_no_longer_pending(): void {
		$this->post( 5, '<p>Our coffee beans guide is here.</p>' );
		$this->post( 7 );

		foreach ( array( 'applied', 'dismissed' ) as $status ) {
			dragoninternallinks_test_reset();
			$this->post( 5, '<p>Our coffee beans guide is here.</p>' );
			$this->post( 7 );
			$GLOBALS['wpdb']->returns['get_row'] = array(
				'id'             => 9,
				'source_post_id' => 5,
				'target_post_id' => 7,
				'keyword'        => 'coffee beans guide',
				'status'         => $status,
			);
			$_POST    = array( 'suggestion_id' => '9' );
			$_REQUEST = array( 'nonce' => 'valid' );

			$scanner = new Scanner();
			try {
				( new Ajax( $scanner, new Analyzer( $scanner ) ) )->handle_apply_suggestion();
				$this->fail( 'no response' );
			} catch ( \DragonInternalLinks_Test_Json_Response $response ) {
				$this->assertFalse( $response->success, $status );
				$this->assertStringContainsString( 'already been applied or dismissed', $response->data['message'] );
			}

			$this->assertSame( array(), dragoninternallinks_test_calls( 'wp_update_post' ), $status );
			$this->assertSame( array(), $GLOBALS['wpdb']->calls_to( 'update' ), $status );
		}
	}

	// -- C10: the Orphan Posts headings show the real totals -------------------

	private function orphan_rows( int $count ): array {
		$rows = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$rows[] = array(
				'post_id'        => $i,
				'post_title'     => 'Orphan ' . $i,
				'post_type'      => 'post',
				'post_date'      => '2026-01-01 00:00:00',
				'outbound_count' => 1,
				'inbound_count'  => 0,
				'orphan_score'   => 1.0,
			);
		}
		return $rows;
	}

	private function render_orphans( CorrectnessFixesAnalyzer $analyzer ): string {
		$admin = new Admin( new Scanner(), $analyzer );
		ob_start();
		$admin->render_orphans_page();
		return (string) ob_get_clean();
	}

	public function test_the_orphan_headings_show_the_totals_and_how_many_are_listed(): void {
		$analyzer               = new CorrectnessFixesAnalyzer( new Scanner() );
		$analyzer->orphans      = $this->orphan_rows( 100 );
		$analyzer->orphan_total = 500;
		$analyzer->low          = $this->orphan_rows( 50 );
		$analyzer->low_total    = 73;

		$html = $this->render_orphans( $analyzer );

		$this->assertStringContainsString( '<span class="dil-count">(500)</span>', $html );
		$this->assertStringContainsString( 'Showing the first 100 of 500 posts.', $html );
		$this->assertStringContainsString( '<span class="dil-count">(73)</span>', $html );
		$this->assertStringContainsString( 'Showing the first 25 of 73 posts.', $html );
	}

	public function test_no_note_when_every_post_is_listed(): void {
		$analyzer               = new CorrectnessFixesAnalyzer( new Scanner() );
		$analyzer->orphans      = $this->orphan_rows( 3 );
		$analyzer->orphan_total = 3;
		$analyzer->low          = $this->orphan_rows( 2 );
		$analyzer->low_total    = 2;

		$html = $this->render_orphans( $analyzer );

		$this->assertStringContainsString( '<span class="dil-count">(3)</span>', $html );
		$this->assertStringContainsString( '<span class="dil-count">(2)</span>', $html );
		$this->assertStringNotContainsString( 'Showing the first', $html );
	}

	public function test_the_low_outbound_count_uses_the_list_filter(): void {
		$GLOBALS['dragoninternallinks_test']['options']['dragoninternallinks_post_types'] = array( 'post' );
		$GLOBALS['wpdb']->returns['get_var'] = '42';

		$this->assertSame( 42, ( new Analyzer( new Scanner() ) )->count_low_outbound() );

		$query = '';
		foreach ( $GLOBALS['wpdb']->calls_to( 'prepare' ) as $args ) {
			$query = (string) $args[0];
		}
		$this->assertStringContainsString( 's.outbound_count <= %d', $query );
		$this->assertStringContainsString( "p.post_status = 'publish'", $query );
	}

	// -- C11: the schema version is stamped only when the tables exist ---------

	public function test_the_schema_version_is_stamped_once_the_tables_exist(): void {
		Plugin::activate( false );

		$this->assertSame( '1.1.10', get_option( 'dragoninternallinks_db_version' ) );
	}

	public function test_the_schema_version_is_not_stamped_when_a_table_is_missing(): void {
		$GLOBALS['dragoninternallinks_test']['create_fails'] = true;

		Plugin::activate( false );

		$this->assertFalse( get_option( 'dragoninternallinks_db_version' ) );

		// So the next admin request tries again.
		$GLOBALS['dragoninternallinks_test']['create_fails'] = false;
		$GLOBALS['dragoninternallinks_test']['is_admin']     = true;
		Plugin::maybe_install();
		$this->assertSame( '1.1.10', get_option( 'dragoninternallinks_db_version' ) );
	}

	// -- C12: tokens fold case in every script ---------------------------------

	public function test_capitalised_non_ascii_words_are_the_same_term(): void {
		$this->assertSame(
			array( 'über', 'kaffee', 'über', 'kaffee', 'кофе', 'кофе', 'éclair', 'éclair' ),
			Relevance::tokenize( 'Über Kaffee über kaffee Кофе кофе Éclair éclair' )
		);
	}

	public function test_word_length_counts_characters_not_bytes(): void {
		$this->assertSame( array( 'ёлка' ), Relevance::tokenize( 'çà ёж ёлка' ) );
	}

	// -- AB5: uninstall removes only this plugin's legacy options --------------

	public function test_uninstall_removes_the_named_legacy_options_and_nothing_else_prefixed_dil(): void {
		$legacy = array( 'dil_db_version', 'dil_auto_scan', 'dil_exclude_categories', 'dil_last_scan', 'dil_last_scan_count', 'dil_min_word_count', 'dil_post_types', 'dil_scan_frequency' );

		$options = array( 'dragoninternallinks_delete_data_on_uninstall' => true );
		foreach ( $legacy as $name ) {
			$options[ $name ] = 'old';
		}
		$options['dil_other_plugin_setting'] = 'keep me';
		$GLOBALS['dragoninternallinks_test']['options'] = $options;

		include __DIR__ . '/../uninstall.php';

		foreach ( $legacy as $name ) {
			$this->assertArrayNotHasKey( $name, $GLOBALS['dragoninternallinks_test']['options'], $name );
		}
		$this->assertSame( 'keep me', $GLOBALS['dragoninternallinks_test']['options']['dil_other_plugin_setting'] );

		foreach ( $GLOBALS['wpdb']->calls_to( 'query' ) as $args ) {
			$this->assertStringNotContainsString( "'dil\\_", (string) $args[0] );
			$this->assertStringNotContainsString( 'transient\\_dil', (string) $args[0] );
			$this->assertStringNotContainsString( 'timeout\\_dil', (string) $args[0] );
		}
	}
}
