<?php
/**
 * Plugin lifecycle tests: the legacy-prefix migration runs once, and
 * activation, new sites and uninstall cover every site of a network.
 *
 * @package DragonInternalLinks
 */

namespace DragonInternalLinks\Tests;

use DragonInternalLinks\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-scanner.php';
require_once __DIR__ . '/../includes/class-analyzer.php';
require_once __DIR__ . '/../includes/class-scheduler.php';
require_once __DIR__ . '/../includes/class-plugin.php';

defined( 'DRAGONINTERNALLINKS_VERSION' ) || define( 'DRAGONINTERNALLINKS_VERSION', '1.1.10' );
defined( 'DRAGONINTERNALLINKS_PLUGIN_BASENAME' ) || define( 'DRAGONINTERNALLINKS_PLUGIN_BASENAME', 'dragon-internal-links/dragon-internal-links.php' );
defined( 'WP_UNINSTALL_PLUGIN' ) || define( 'WP_UNINSTALL_PLUGIN', 'dragon-internal-links/dragon-internal-links.php' );

final class PluginTest extends TestCase {

	protected function setUp(): void {
		dragoninternallinks_test_reset();
	}

	private function migrate(): void {
		$method = new \ReflectionMethod( Plugin::class, 'migrate_legacy_prefix' );
		$method->setAccessible( true );
		$method->invoke( null );
	}

	/**
	 * Table prefixes dbDelta() created tables under.
	 *
	 * @return string[]
	 */
	private function created_prefixes(): array {
		return array_values( array_unique( array_column( dragoninternallinks_test_calls( 'dbDelta' ), 0 ) ) );
	}

	private function network( array $blogs ): void {
		$GLOBALS['dragoninternallinks_test']['multisite'] = true;
		foreach ( $blogs as $id => $options ) {
			if ( 1 === $id ) {
				$GLOBALS['dragoninternallinks_test']['options'] = $options;
			} else {
				$GLOBALS['dragoninternallinks_test']['blogs'][ $id ] = array(
					'options' => $options,
					'cron'    => array(),
				);
			}
		}
	}

	public function test_legacy_migration_copies_once_then_is_skipped(): void {
		$options = &$GLOBALS['dragoninternallinks_test']['options'];

		$options['dil_auto_scan'] = false;
		$this->migrate();

		$this->assertFalse( $options['dragoninternallinks_auto_scan'] );
		$this->assertArrayNotHasKey( 'dil_auto_scan', $options );
		$this->assertNotEmpty( $options['dragoninternallinks_legacy_migrated'] );

		// A later request does no migration work at all.
		$options['dil_auto_scan'] = true;
		$this->migrate();

		$this->assertTrue( $options['dil_auto_scan'] );
		$this->assertFalse( $options['dragoninternallinks_auto_scan'] );
	}

	public function test_single_site_activation_creates_tables_on_that_site(): void {
		Plugin::activate( false );

		$this->assertSame( array( 'wp_' ), $this->created_prefixes() );
		$this->assertSame( '1.1.10', $GLOBALS['dragoninternallinks_test']['options']['dragoninternallinks_db_version'] );
	}

	public function test_network_activation_creates_tables_on_every_site(): void {
		$this->network(
			array(
				1 => array(),
				2 => array(),
				3 => array(),
			)
		);

		Plugin::activate( true );

		$this->assertSame( array( 'wp_', 'wp_2_', 'wp_3_' ), $this->created_prefixes() );
		$this->assertSame( 1, get_current_blog_id() );
		$this->assertSame( '1.1.10', $GLOBALS['dragoninternallinks_test']['blogs'][3]['options']['dragoninternallinks_db_version'] );
		$this->assertSame( 0, dragoninternallinks_test_calls( 'get_sites' )[0][0]['number'] );
	}

	public function test_a_new_site_on_a_network_activated_install_gets_its_tables(): void {
		$this->network(
			array(
				1 => array(),
				4 => array(),
			)
		);

		$GLOBALS['dragoninternallinks_test']['network_active'] = false;
		Plugin::initialize_site( (object) array( 'blog_id' => '4' ) );
		$this->assertSame( array(), $this->created_prefixes() );

		$GLOBALS['dragoninternallinks_test']['network_active'] = true;
		Plugin::initialize_site( (object) array( 'blog_id' => '4' ) );

		$this->assertSame( array( 'wp_4_' ), $this->created_prefixes() );
		$this->assertSame( 1, get_current_blog_id() );
		$this->assertSame( '1.1.10', $GLOBALS['dragoninternallinks_test']['blogs'][4]['options']['dragoninternallinks_db_version'] );
	}

	public function test_a_site_without_tables_gets_them_in_admin_but_not_on_the_front_end(): void {
		Plugin::maybe_install();
		$this->assertSame( array(), $this->created_prefixes() );

		$GLOBALS['dragoninternallinks_test']['is_admin'] = true;
		Plugin::maybe_install();
		$this->assertSame( array( 'wp_' ), $this->created_prefixes() );

		$GLOBALS['dragoninternallinks_test']['calls'] = array();
		Plugin::maybe_install();
		$this->assertSame( array(), $this->created_prefixes() );
	}

	public function test_uninstall_runs_per_site_and_honours_each_opt_in(): void {
		$this->network(
			array(
				1 => array( 'dragoninternallinks_delete_data_on_uninstall' => true ),
				2 => array( 'dragoninternallinks_delete_data_on_uninstall' => false ),
				3 => array( 'dragoninternallinks_delete_data_on_uninstall' => true ),
			)
		);

		include __DIR__ . '/../uninstall.php';

		$dropped = array();
		foreach ( $GLOBALS['wpdb']->calls_to( 'prepare' ) as $args ) {
			if ( str_starts_with( (string) $args[0], 'DROP TABLE' ) ) {
				$dropped[] = $args[1];
			}
		}

		$this->assertContains( 'wp_dil_links', $dropped );
		$this->assertContains( 'wp_3_dil_suggestions', $dropped );
		$this->assertNotContains( 'wp_2_dil_links', $dropped );
		$this->assertSame( 1, get_current_blog_id() );
	}

	public function test_activation_and_deactivation_never_flush_rewrite_rules(): void {
		$this->network(
			array(
				1 => array(),
				2 => array(),
				4 => array(),
			)
		);

		Plugin::activate( false );
		Plugin::activate( true );
		$GLOBALS['dragoninternallinks_test']['network_active'] = true;
		Plugin::initialize_site( (object) array( 'blog_id' => '4' ) );
		Plugin::deactivate( false );
		Plugin::deactivate( true );

		$this->assertSame( array(), dragoninternallinks_test_calls( 'flush_rewrite_rules' ) );
	}

	public function test_deleting_a_site_drops_its_tables(): void {
		$this->network(
			array(
				1 => array(),
				3 => array(),
			)
		);
		switch_to_blog( 3 );

		$tables = Plugin::drop_site_tables( array( 'posts' => 'wp_3_posts' ), 3 );

		restore_current_blog();
		$this->assertSame( array( 'posts' => 'wp_1_posts' ), Plugin::drop_site_tables( array( 'posts' => 'wp_1_posts' ) ), 'no site id, nothing added' );
		$this->assertSame( 'wp_3_posts', $tables['posts'] );
		$this->assertContains( 'wp_3_dil_links', $tables );
		$this->assertContains( 'wp_3_dil_stats', $tables );
		$this->assertContains( 'wp_3_dil_suggestions', $tables );
	}

	public function test_site_deletion_hook_receives_the_site_id(): void {
		$method = new \ReflectionMethod( Plugin::class, 'register_hooks' );
		$method->setAccessible( true );
		$method->invoke( null );

		$this->assertContains(
			array( 'wpmu_drop_tables', array( Plugin::class, 'drop_site_tables' ), 10, 2 ),
			dragoninternallinks_test_calls( 'add_filter' )
		);
	}

	public function test_uninstall_on_a_single_site_without_opt_in_keeps_data(): void {
		include __DIR__ . '/../uninstall.php';

		$this->assertSame( array(), $GLOBALS['wpdb']->calls_to( 'prepare' ) );
	}
}
