<?php
/**
 * Main Plugin Class
 *
 * @package DragonInternalLinks
 */

namespace DragonInternalLinks;

defined( 'ABSPATH' ) || exit;

class Plugin {

	/**
	 * Singleton instance
	 */
	private static ?Plugin $instance = null;

	/**
	 * Component instances
	 */
	private ?Admin $admin         = null;
	private ?Scanner $scanner     = null;
	private ?Analyzer $analyzer   = null;
	private ?Scheduler $scheduler = null;
	private ?Ajax $ajax           = null;

	/**
	 * Get singleton instance
	 */
	public static function get_instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		self::migrate_legacy_prefix();
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
		$this->init_components();
	}

	/**
	 * Move options and the scan schedule off the pre-1.0.1 three-letter (dil_)
	 * prefix.
	 *
	 * The prefix was renamed to the namespace-derived `dragoninternallinks_` to
	 * satisfy the WordPress.org uniqueness rule. Option values are carried across
	 * once and the daily-scan cron is re-pointed at the renamed hook. The links,
	 * stats and suggestions tables keep their names (matched by exact name), so
	 * scanned link data is untouched.
	 */
	private static function migrate_legacy_prefix(): void {
		// db_version is a schema marker managed by activation, not user data.
		delete_option( 'dil_db_version' );

		$options = array( 'auto_scan', 'exclude_categories', 'last_scan', 'last_scan_count', 'min_word_count', 'post_types', 'scan_frequency' );

		// Copy each legacy value onto the new name, then remove the legacy copy —
		// per option, so the delete only ever runs after a successful copy. (A
		// single shared guard would delete on a deactivate/reactivate cycle, where
		// activation re-stamps the new db_version before the copy could run.)
		foreach ( $options as $name ) {
			$legacy = get_option( 'dil_' . $name, null );
			if ( null !== $legacy ) {
				update_option( 'dragoninternallinks_' . $name, $legacy );
				delete_option( 'dil_' . $name );
			}
		}

		$legacy_cron = wp_next_scheduled( 'dil_daily_scan' );
		if ( $legacy_cron ) {
			wp_unschedule_event( $legacy_cron, 'dil_daily_scan' );
		}
	}

	/**
	 * Schedule the full-scan event (dragoninternallinks_daily_scan) at the
	 * chosen Full Scan Frequency if it is missing, or move it when it recurs at a
	 * different one. Runs on init because scheduling reads every plugin's
	 * translated cron_schedules labels.
	 *
	 * A next event with no recurrence is a pending resume of an unfinished scan
	 * and is left alone; the check happens again once it has run.
	 */
	public static function ensure_scheduled(): void {
		$frequency = Scheduler::frequency();

		if ( ! wp_next_scheduled( Scheduler::CRON_HOOK ) ) {
			wp_schedule_event( time(), $frequency, Scheduler::CRON_HOOK );
			return;
		}

		$current = wp_get_schedule( Scheduler::CRON_HOOK );
		if ( is_string( $current ) && $current !== $frequency ) {
			Scheduler::reschedule( $frequency );
		}
	}

	/**
	 * Initialize plugin components
	 */
	private function init_components(): void {
		$this->scanner   = new Scanner();
		$this->analyzer  = new Analyzer( $this->scanner );
		$this->scheduler = new Scheduler( $this->scanner, $this->analyzer );
		$this->ajax      = new Ajax( $this->scanner, $this->analyzer );
		$this->admin     = new Admin( $this->scanner, $this->analyzer );
	}

	/**
	 * Plugin activation
	 */
	public static function activate(): void {
		self::create_tables();
		self::set_default_options();

		// Schedule cron events
		self::ensure_scheduled();

		// Flush rewrite rules
		flush_rewrite_rules();
	}

	/**
	 * Plugin deactivation
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'dragoninternallinks_daily_scan' );
		flush_rewrite_rules();
	}

	/**
	 * Create database tables
	 */
	private static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Links table - stores all internal links found
		$table_links = $wpdb->prefix . 'dil_links';
		$sql_links   = "CREATE TABLE $table_links (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_post_id bigint(20) unsigned NOT NULL,
            target_post_id bigint(20) unsigned NOT NULL,
            anchor_text varchar(255) DEFAULT '',
            context text,
            link_url varchar(500) NOT NULL,
            created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_source (source_post_id),
            KEY idx_target (target_post_id),
            KEY idx_source_target (source_post_id, target_post_id)
        ) $charset_collate;";

		// Stats table - cached link counts per post
		$table_stats = $wpdb->prefix . 'dil_stats';
		$sql_stats   = "CREATE TABLE $table_stats (
            post_id bigint(20) unsigned NOT NULL,
            inbound_count int(11) NOT NULL DEFAULT 0,
            outbound_count int(11) NOT NULL DEFAULT 0,
            orphan_score float NOT NULL DEFAULT 0,
            last_scanned timestamp NULL DEFAULT NULL,
            PRIMARY KEY  (post_id),
            KEY idx_orphan (orphan_score)
        ) $charset_collate;";

		// Suggestions table - link opportunities
		$table_suggestions = $wpdb->prefix . 'dil_suggestions';
		$sql_suggestions   = "CREATE TABLE $table_suggestions (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_post_id bigint(20) unsigned NOT NULL,
            target_post_id bigint(20) unsigned NOT NULL,
            keyword varchar(255) NOT NULL,
            context text,
            relevance_score float NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'pending',
            created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_status (status),
            KEY idx_source (source_post_id),
            KEY idx_relevance (relevance_score)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_links );
		dbDelta( $sql_stats );
		dbDelta( $sql_suggestions );

		update_option( 'dragoninternallinks_db_version', DRAGONINTERNALLINKS_VERSION );
	}

	/**
	 * Set default plugin options
	 */
	private static function set_default_options(): void {
		$defaults = array(
			'dragoninternallinks_post_types'         => array( 'post', 'page' ),
			'dragoninternallinks_auto_scan'          => true,
			'dragoninternallinks_min_word_count'     => 3,
			'dragoninternallinks_exclude_categories' => array(),
			'dragoninternallinks_scan_frequency'     => 'daily',
		);

		foreach ( $defaults as $option => $value ) {
			if ( false === get_option( $option ) ) {
				add_option( $option, $value );
			}
		}
	}

	/**
	 * Get Scanner instance
	 */
	public function get_scanner(): Scanner {
		return $this->scanner;
	}

	/**
	 * Get Analyzer instance
	 */
	public function get_analyzer(): Analyzer {
		return $this->analyzer;
	}
}
