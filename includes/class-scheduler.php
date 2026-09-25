<?php
/**
 * Scheduler Class
 *
 * Handles cron jobs for background scanning
 *
 * @package DragonInternalLinks
 */

namespace DragonInternalLinks;

defined( 'ABSPATH' ) || exit;

class Scheduler {

	/**
	 * Scanner instance
	 */
	private Scanner $scanner;

	/**
	 * Analyzer instance
	 */
	private Analyzer $analyzer;

	/**
	 * Cron hook name
	 */
	public const CRON_HOOK = 'dragoninternallinks_daily_scan';

	/**
	 * Full-scan frequencies the settings offer, as core cron recurrences, with
	 * the interval to the first run after a change.
	 */
	public const FREQUENCIES = array(
		'daily'  => DAY_IN_SECONDS,
		'weekly' => WEEK_IN_SECONDS,
	);

	/**
	 * A frequency value reduced to one the settings offer (daily otherwise).
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_frequency( $value ): string {
		$value = is_string( $value ) ? $value : '';

		return array_key_exists( $value, self::FREQUENCIES ) ? $value : 'daily';
	}

	/**
	 * The chosen Full Scan Frequency.
	 *
	 * @return string
	 */
	public static function frequency(): string {
		return self::sanitize_frequency( get_option( 'dragoninternallinks_scan_frequency', 'daily' ) );
	}

	/**
	 * Replace the recurring scan with one at the given frequency, first run one
	 * interval from now (a change of setting is not a request to scan now).
	 * Clearing the hook also drops a pending resume of an unfinished scan; the
	 * scan's progress is kept in options, so the next run carries on from it.
	 *
	 * @param string $frequency Frequency from FREQUENCIES.
	 * @return bool False when the new event could not be saved.
	 */
	public static function reschedule( string $frequency ): bool {
		$frequency = self::sanitize_frequency( $frequency );

		wp_clear_scheduled_hook( self::CRON_HOOK );

		return true === wp_schedule_event( time() + self::FREQUENCIES[ $frequency ], $frequency, self::CRON_HOOK, array(), true );
	}

	/**
	 * Constructor
	 */
	public function __construct( Scanner $scanner, Analyzer $analyzer ) {
		$this->scanner  = $scanner;
		$this->analyzer = $analyzer;

		$this->init_hooks();
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks(): void {
		add_action( self::CRON_HOOK, array( $this, 'run_scheduled_scan' ) );
	}

	/**
	 * Run scheduled scan
	 */
	public function run_scheduled_scan(): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'DIL: Starting scheduled scan at ' . current_time( 'mysql' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging, only when WP_DEBUG is enabled.
		}

		$start    = microtime( true );
		$deadline = time() + 2 * MINUTE_IN_SECONDS;

		// Phase 1 — scan, in batches inside a time box. A large site cannot finish
		// inside one cron request without hitting the PHP time limit; an unfinished
		// scan reschedules itself a minute out and continues from its offset. Once
		// generation has started (generate_offset set), this phase is skipped so a
		// reschedule resumes generation rather than restarting the scan.
		if ( false === get_option( 'dragoninternallinks_generate_offset', false ) ) {
			$offset = (int) get_option( 'dragoninternallinks_scan_offset', 0 );
			$result = $this->scanner->scan_all( 100, $offset );

			while ( ! $result['complete'] && time() < $deadline ) {
				$result = $this->scanner->scan_all( 100, $result['offset'] );
			}

			if ( ! $result['complete'] ) {
				update_option( 'dragoninternallinks_scan_offset', (int) $result['offset'], false );
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_HOOK );
				return;
			}

			delete_option( 'dragoninternallinks_scan_offset' );
			update_option( 'dragoninternallinks_scan_total', (int) $result['total'], false );
			update_option( 'dragoninternallinks_generate_offset', 0, false );
		}

		// Phase 2 — generate suggestions for EVERY post, resumable + time-boxed so
		// a large site (especially with AI re-ranking) never overruns one run.
		// Pending suggestions are cleared once, when generation starts at offset 0.
		$gen_offset = (int) get_option( 'dragoninternallinks_generate_offset', 0 );
		do {
			$gen        = $this->analyzer->generate_all_suggestions( 50, $gen_offset );
			$gen_offset = (int) $gen['offset'];
		} while ( ! $gen['done'] && time() < $deadline );

		if ( ! $gen['done'] ) {
			update_option( 'dragoninternallinks_generate_offset', $gen_offset, false );
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_HOOK );
			return;
		}

		delete_option( 'dragoninternallinks_generate_offset' );

		$total    = (int) get_option( 'dragoninternallinks_scan_total', 0 );
		$duration = round( microtime( true ) - $start, 2 );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( "DIL: Scheduled scan + generation complete for {$total} posts in {$duration}s" ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging, only when WP_DEBUG is enabled.
		}

		delete_option( 'dragoninternallinks_scan_total' );

		// phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- Local timestamp is intentional; displayed via date_i18n().
		update_option( 'dragoninternallinks_last_scan', current_time( 'timestamp' ) );
		update_option( 'dragoninternallinks_last_scan_count', $total );
	}

	/**
	 * Get last scan info
	 */
	public function get_last_scan_info(): array {
		$timestamp = get_option( 'dragoninternallinks_last_scan', 0 );
		$count     = get_option( 'dragoninternallinks_last_scan_count', 0 );

		return array(
			'timestamp' => $timestamp,
			'formatted' => $timestamp ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) : __( 'Never', 'dragon-internal-links' ),
			'count'     => $count,
		);
	}

	/**
	 * Get next scheduled scan
	 */
	public function get_next_scan(): string {
		$next = wp_next_scheduled( self::CRON_HOOK );

		if ( ! $next ) {
			return __( 'Not scheduled', 'dragon-internal-links' );
		}

		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next );
	}
}
