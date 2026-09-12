<?php
/**
 * Admin Class
 *
 * Handles admin pages, menus, and assets
 *
 * @package DragonInternalLinks
 */

namespace DragonInternalLinks;

defined( 'ABSPATH' ) || exit;

class Admin {

	/**
	 * Scanner instance
	 */
	private Scanner $scanner;

	/**
	 * Analyzer instance
	 */
	private Analyzer $analyzer;

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
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'manage_posts_columns', array( $this, 'add_links_column' ) );
		add_action( 'manage_posts_custom_column', array( $this, 'render_links_column' ), 10, 2 );
		add_filter( 'manage_pages_columns', array( $this, 'add_links_column' ) );
		add_action( 'manage_pages_custom_column', array( $this, 'render_links_column' ), 10, 2 );
	}

	/**
	 * Add admin menu pages
	 */
	public function add_admin_menu(): void {
		// Main page under Tools menu
		add_management_page(
			__( 'Dragon Internal Links', 'dragon-internal-links' ),
			__( 'Internal Links', 'dragon-internal-links' ),
			'manage_options',
			'dragon-internal-links',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Render admin page with tabs
	 */
	public function render_admin_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dragon-internal-links' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selection; no state changes.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';

		switch ( $tab ) {
			case 'orphans':
				$this->render_orphans_page();
				break;
			case 'suggestions':
				$this->render_suggestions_page();
				break;
			case 'settings':
				$this->render_settings_page();
				break;
			default:
				$this->render_dashboard_page();
				break;
		}
	}

	/**
	 * Enqueue admin assets
	 */
	public function enqueue_assets( string $hook ): void {
		if ( ! str_contains( $hook, 'dragon-internal-links' ) ) {
			return;
		}

		wp_enqueue_style(
			'dragon-internal-links-dragon-ui',
			DRAGONINTERNALLINKS_PLUGIN_URL . 'admin/css/dragon-ui.css',
			array(),
			DRAGONINTERNALLINKS_VERSION
		);

		wp_enqueue_style(
			'dil-admin',
			DRAGONINTERNALLINKS_PLUGIN_URL . 'admin/css/admin.css',
			array( 'dragon-internal-links-dragon-ui' ),
			DRAGONINTERNALLINKS_VERSION
		);

		wp_enqueue_script(
			'dil-admin',
			DRAGONINTERNALLINKS_PLUGIN_URL . 'admin/js/admin.js',
			array( 'jquery' ),
			DRAGONINTERNALLINKS_VERSION,
			true
		);

		wp_localize_script(
			'dil-admin',
			'dilAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'dragoninternallinks_admin_nonce' ),
				'i18n'    => array(
					'scanning'     => __( 'Scanning...', 'dragon-internal-links' ),
					'scanComplete' => __( 'Scan complete!', 'dragon-internal-links' ),
					'generating'   => __( 'Generating suggestions...', 'dragon-internal-links' ),
					'error'        => __( 'An error occurred.', 'dragon-internal-links' ),
					'confirm'      => __( 'Are you sure?', 'dragon-internal-links' ),
				),
			)
		);
	}

	/**
	 * Render dashboard page
	 */
	public function render_dashboard_page(): void {
		$summary      = $this->analyzer->get_summary();
		$top_linked   = $this->analyzer->get_top_linked_posts( 10 );
		$broken_links = $this->scanner->find_broken_links();
		$last_scan    = get_option( 'dragoninternallinks_last_scan', 0 );
		$current_tab  = 'dashboard';

		include DRAGONINTERNALLINKS_PLUGIN_DIR . 'admin/views/dashboard.php';
	}

	/**
	 * Render orphans page
	 */
	public function render_orphans_page(): void {
		$orphans      = $this->analyzer->get_orphan_posts( 100 );
		$low_outbound = $this->analyzer->get_low_outbound_posts( 50 );
		$current_tab  = 'orphans';
		$last_scan    = (int) get_option( 'dragoninternallinks_last_scan', 0 );

		include DRAGONINTERNALLINKS_PLUGIN_DIR . 'admin/views/orphans.php';
	}

	/**
	 * Escape a suggestion's context and wrap the keyword in <mark>.
	 *
	 * preg_replace() returns null when the context is not valid UTF-8 (the /u
	 * flag), and a keyword that is not valid UTF-8 cannot be compiled into a /u
	 * pattern; both fall back to the escaped context with no highlight rather
	 * than a blank column.
	 *
	 * @param string $context Raw context text.
	 * @param string $keyword Keyword to highlight.
	 * @return string Escaped HTML containing only <mark> tags.
	 */
	public static function highlight_keyword( string $context, string $keyword ): string {
		// esc_html() returns an empty string for text that is not valid UTF-8, so
		// a stray byte anywhere in the excerpt would blank the whole column. The
		// invalid bytes are dropped first and the rest of the text is kept.
		$escaped = esc_html( wp_check_invalid_utf8( $context, true ) );

		if ( '' === $keyword || 1 !== preg_match( '//u', $keyword ) ) {
			return $escaped;
		}

		$highlighted = preg_replace(
			'/(' . preg_quote( $keyword, '/' ) . ')/iu',
			'<mark>$1</mark>',
			$escaped
		);

		return is_string( $highlighted ) ? $highlighted : $escaped;
	}

	/**
	 * Render suggestions page
	 */
	public function render_suggestions_page(): void {
		$suggestions = $this->analyzer->get_suggestions( 100 );
		$current_tab = 'suggestions';

		include DRAGONINTERNALLINKS_PLUGIN_DIR . 'admin/views/suggestions.php';
	}

	/**
	 * Render settings page
	 */
	public function render_settings_page(): void {
		// Handle form submission.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Presence check only; nonce is verified in save_settings().
		if ( isset( $_POST['dragoninternallinks_settings_nonce'] ) ) {
			$this->save_settings();
		}

		$settings    = $this->get_settings();
		$current_tab = 'settings';

		include DRAGONINTERNALLINKS_PLUGIN_DIR . 'admin/views/settings.php';
	}

	/**
	 * Get current settings
	 */
	private function get_settings(): array {
		return array(
			'post_types'         => get_option( 'dragoninternallinks_post_types', array( 'post', 'page' ) ),
			'auto_scan'          => get_option( 'dragoninternallinks_auto_scan', true ),
			'min_word_count'     => get_option( 'dragoninternallinks_min_word_count', 3 ),
			'exclude_categories' => get_option( 'dragoninternallinks_exclude_categories', array() ),
			'scan_frequency'     => get_option( 'dragoninternallinks_scan_frequency', 'daily' ),
		);
	}

	/**
	 * Save settings
	 */
	private function save_settings(): void {
		if ( ! isset( $_POST['dragoninternallinks_settings_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['dragoninternallinks_settings_nonce'] ) ), 'dragoninternallinks_save_settings' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_POST['dragoninternallinks_post_types'] ) ) {
			$post_types = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['dragoninternallinks_post_types'] ) );
			update_option( 'dragoninternallinks_post_types', $post_types );
		} else {
			update_option( 'dragoninternallinks_post_types', array() );
		}

		update_option( 'dragoninternallinks_auto_scan', isset( $_POST['dragoninternallinks_auto_scan'] ) );

		if ( isset( $_POST['dragoninternallinks_min_word_count'] ) ) {
			update_option( 'dragoninternallinks_min_word_count', absint( $_POST['dragoninternallinks_min_word_count'] ) );
		}

		if ( isset( $_POST['dragoninternallinks_exclude_categories'] ) ) {
			$cats = array_map( 'absint', (array) $_POST['dragoninternallinks_exclude_categories'] );
			update_option( 'dragoninternallinks_exclude_categories', $cats );
		} else {
			update_option( 'dragoninternallinks_exclude_categories', array() );
		}

		if ( isset( $_POST['dragoninternallinks_scan_frequency'] ) ) {
			update_option( 'dragoninternallinks_scan_frequency', sanitize_text_field( wp_unslash( $_POST['dragoninternallinks_scan_frequency'] ) ) );
		}

		// AI re-ranking (bring-your-own key).
		update_option( 'dragoninternallinks_ai_enabled', isset( $_POST['dragoninternallinks_ai_enabled'] ) );

		if ( isset( $_POST['dragoninternallinks_ai_provider'] ) ) {
			$provider = sanitize_key( wp_unslash( $_POST['dragoninternallinks_ai_provider'] ) );
			if ( in_array( $provider, array( 'openai', 'anthropic', 'google' ), true ) ) {
				update_option( 'dragoninternallinks_ai_provider', $provider );
			}
		}

		if ( isset( $_POST['dragoninternallinks_ai_model'] ) ) {
			$model = sanitize_text_field( wp_unslash( $_POST['dragoninternallinks_ai_model'] ) );
			update_option( 'dragoninternallinks_ai_model', $model );
		}

		if ( isset( $_POST['dragoninternallinks_ai_api_key'] ) ) {
			$submitted = trim( sanitize_text_field( wp_unslash( $_POST['dragoninternallinks_ai_api_key'] ) ) );
			if ( '' === $submitted ) {
				delete_option( 'dragoninternallinks_ai_api_key' );
			} elseif ( '••••••••' !== $submitted ) {
				// The masked placeholder means "keep the stored key".
				update_option( 'dragoninternallinks_ai_api_key', AI_Ranker::encrypt_key( $submitted ) );
			}
		}

		update_option( 'dragoninternallinks_delete_data_on_uninstall', isset( $_POST['dragoninternallinks_delete_data'] ) );

		add_settings_error( 'dragoninternallinks_settings', 'settings_saved', __( 'Settings saved.', 'dragon-internal-links' ), 'success' );
	}

	/**
	 * Add links column to post list
	 */
	public function add_links_column( array $columns ): array {
		$columns['dil_links'] = __( 'Links', 'dragon-internal-links' );
		return $columns;
	}

	/**
	 * Render links column content
	 */
	public function render_links_column( string $column, int $post_id ): void {
		if ( 'dil_links' !== $column ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'dil_stats';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; no core API or cache available.
		$stats = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT inbound_count, outbound_count FROM %i WHERE post_id = %d',
				$table,
				$post_id
			)
		);

		if ( ! $stats ) {
			echo '<span class="dil-no-data">—</span>';
			return;
		}

		$inbound_class = 0 === (int) $stats->inbound_count ? 'dil-orphan' : '';

		printf(
			'<span class="dil-link-stats %s" title="%s">↓%d ↑%d</span>',
			esc_attr( $inbound_class ),
			esc_attr__( 'Inbound / Outbound links', 'dragon-internal-links' ),
			(int) $stats->inbound_count,
			(int) $stats->outbound_count
		);
	}
}
