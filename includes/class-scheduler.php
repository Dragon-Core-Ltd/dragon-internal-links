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

		$start = microtime( true );

		// Scan in batches inside a time box. A large site cannot finish inside
		// one cron request without hitting the PHP time limit — an unfinished
		// scan reschedules itself a minute out and continues from its offset.
		$offset   = (int) get_option( 'dragoninternallinks_scan_offset', 0 );
		$deadline = time() + 2 * MINUTE_IN_SECONDS;

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

		// Generate suggestions
		$suggestions = $this->analyzer->generate_all_suggestions( 50 );

		$duration = round( microtime( true ) - $start, 2 );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( "DIL: Scan complete. Scanned {$result['total']} posts, generated {$suggestions} suggestions in {$duration}s" ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging, only when WP_DEBUG is enabled.
		}

		// phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- Local timestamp is intentional; displayed via date_i18n().
		update_option( 'dragoninternallinks_last_scan', current_time( 'timestamp' ) );
		update_option( 'dragoninternallinks_last_scan_count', $result['total'] );
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

		return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next );
	}
}
