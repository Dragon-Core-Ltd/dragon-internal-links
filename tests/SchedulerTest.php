<?php
/**
 * The Full Scan Frequency setting decides how often the background scan runs.
 *
 * @package DragonInternalLinks
 */

namespace DragonInternalLinks\Tests;

use DragonInternalLinks\Admin;
use DragonInternalLinks\Plugin;
use DragonInternalLinks\Scheduler;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-scanner.php';
require_once __DIR__ . '/../includes/class-analyzer.php';
require_once __DIR__ . '/../includes/class-scheduler.php';
require_once __DIR__ . '/../includes/class-plugin.php';
require_once __DIR__ . '/../includes/class-admin.php';

final class SchedulerTest extends TestCase {

	private const HOOK = 'dragoninternallinks_daily_scan';

	protected function setUp(): void {
		dragoninternallinks_test_reset();
	}

	private function set_frequency( string $value ): void {
		$GLOBALS['dragoninternallinks_test']['options']['dragoninternallinks_scan_frequency'] = $value;
	}

	private function recurrences(): array {
		return array_column( $GLOBALS['dragoninternallinks_test']['cron'], 2 );
	}

	public function test_frequency_only_accepts_daily_or_weekly(): void {
		$this->assertSame( 'daily', Scheduler::frequency() );

		$this->set_frequency( 'weekly' );
		$this->assertSame( 'weekly', Scheduler::frequency() );

		$this->set_frequency( 'every_second' );
		$this->assertSame( 'daily', Scheduler::frequency() );
	}

	public function test_missing_event_is_scheduled_at_the_chosen_frequency(): void {
		$this->set_frequency( 'weekly' );

		Plugin::ensure_scheduled();

		$this->assertSame( array( 'weekly' ), $this->recurrences() );
	}

	public function test_an_event_at_the_wrong_frequency_is_rescheduled(): void {
		$this->set_frequency( 'weekly' );
		$GLOBALS['dragoninternallinks_test']['cron'] = array( array( time() + 3600, self::HOOK, 'daily' ) );

		Plugin::ensure_scheduled();

		$this->assertSame( array( 'weekly' ), $this->recurrences() );
	}

	public function test_a_pending_resume_of_an_unfinished_scan_is_left_alone(): void {
		$this->set_frequency( 'weekly' );
		$GLOBALS['dragoninternallinks_test']['cron'] = array(
			array( time() + 60, self::HOOK, false ),
			array( time() + 3600, self::HOOK, 'daily' ),
		);

		Plugin::ensure_scheduled();

		$this->assertSame( array( false, 'daily' ), $this->recurrences() );
	}

	public function test_reschedule_reports_a_failed_schedule(): void {
		$GLOBALS['dragoninternallinks_test']['schedule_fails'] = true;

		$this->assertFalse( Scheduler::reschedule( 'weekly' ) );
	}

	private function save( array $post ): void {
		$_POST = array_merge(
			array(
				'dragoninternallinks_settings_nonce' => 'valid',
				'dragoninternallinks_post_types'     => array( 'post' ),
			),
			$post
		);

		$admin = ( new \ReflectionClass( Admin::class ) )->newInstanceWithoutConstructor();
		$save  = new \ReflectionMethod( Admin::class, 'save_settings' );
		$save->setAccessible( true );
		try {
			$save->invoke( $admin );
		} finally {
			$_POST = array();
		}
	}

	public function test_saving_a_new_frequency_reschedules_the_scan(): void {
		$GLOBALS['dragoninternallinks_test']['cron'] = array( array( time() + 3600, self::HOOK, 'daily' ) );

		$this->save( array( 'dragoninternallinks_scan_frequency' => 'weekly' ) );

		$this->assertSame( 'weekly', get_option( 'dragoninternallinks_scan_frequency' ) );
		$this->assertSame( array( 'weekly' ), $this->recurrences() );
		$next = $GLOBALS['dragoninternallinks_test']['cron'][0][0];
		$this->assertGreaterThan( time() + 6 * 86400, $next, 'the next run is a week out, not an immediate full scan' );
	}

	public function test_saving_the_same_frequency_keeps_the_existing_schedule(): void {
		$this->set_frequency( 'daily' );
		$GLOBALS['dragoninternallinks_test']['cron'] = array( array( time() + 3600, self::HOOK, 'daily' ) );

		$this->save( array( 'dragoninternallinks_scan_frequency' => 'daily' ) );

		$this->assertSame( array(), dragoninternallinks_test_calls( 'wp_clear_scheduled_hook' ) );
	}

	public function test_an_unknown_frequency_is_stored_as_daily(): void {
		$this->set_frequency( 'weekly' );

		$this->save( array( 'dragoninternallinks_scan_frequency' => 'hourly' ) );

		$this->assertSame( 'daily', get_option( 'dragoninternallinks_scan_frequency' ) );
	}

	public function test_a_reschedule_that_fails_is_reported(): void {
		$GLOBALS['dragoninternallinks_test']['schedule_fails'] = true;

		$this->save( array( 'dragoninternallinks_scan_frequency' => 'weekly' ) );

		$codes = array_column( $GLOBALS['dragoninternallinks_test']['settings_errors'], 1 );
		$this->assertContains( 'schedule_failed', $codes );
	}
}
