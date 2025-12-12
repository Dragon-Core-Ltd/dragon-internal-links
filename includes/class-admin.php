<?php
/**
 * Admin Class
 *
 * Handles admin pages, menus, and assets
 *
 * @package DragonInternalLinks
 */

namespace DragonInternalLinks;

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
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_filter( 'manage_posts_columns', [ $this, 'add_links_column' ] );
        add_action( 'manage_posts_custom_column', [ $this, 'render_links_column' ], 10, 2 );
        add_filter( 'manage_pages_columns', [ $this, 'add_links_column' ] );
        add_action( 'manage_pages_custom_column', [ $this, 'render_links_column' ], 10, 2 );
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
            [ $this, 'render_admin_page' ]
        );
    }

    /**
     * Render admin page with tabs
     */
    public function render_admin_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'dragon-internal-links' ) );
        }

        $tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'dashboard';

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
            'dil-admin',
            DIL_PLUGIN_URL . 'admin/css/admin.css',
            [],
            DIL_VERSION
        );

        wp_enqueue_script(
            'dil-admin',
            DIL_PLUGIN_URL . 'admin/js/admin.js',
            [ 'jquery' ],
            DIL_VERSION,
            true
        );

        wp_localize_script( 'dil-admin', 'dilAdmin', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'dil_admin_nonce' ),
            'i18n'    => [
                'scanning'     => __( 'Scanning...', 'dragon-internal-links' ),
                'scanComplete' => __( 'Scan complete!', 'dragon-internal-links' ),
                'generating'   => __( 'Generating suggestions...', 'dragon-internal-links' ),
                'error'        => __( 'An error occurred.', 'dragon-internal-links' ),
                'confirm'      => __( 'Are you sure?', 'dragon-internal-links' ),
            ],
        ] );
    }

    /**
     * Render dashboard page
     */
    public function render_dashboard_page(): void {
        $summary = $this->analyzer->get_summary();
        $top_linked = $this->analyzer->get_top_linked_posts( 10 );
        $broken_links = $this->scanner->find_broken_links();
        $last_scan = get_option( 'dil_last_scan', 0 );
        $current_tab = 'dashboard';

        include DIL_PLUGIN_DIR . 'admin/views/dashboard.php';
    }

    /**
     * Render orphans page
     */
    public function render_orphans_page(): void {
        $orphans = $this->analyzer->get_orphan_posts( 100 );
        $low_outbound = $this->analyzer->get_low_outbound_posts( 50 );
        $current_tab = 'orphans';

        include DIL_PLUGIN_DIR . 'admin/views/orphans.php';
    }

    /**
     * Render suggestions page
     */
    public function render_suggestions_page(): void {
        $suggestions = $this->analyzer->get_suggestions( 100 );
        $current_tab = 'suggestions';

        include DIL_PLUGIN_DIR . 'admin/views/suggestions.php';
    }

    /**
     * Render settings page
     */
    public function render_settings_page(): void {
        // Handle form submission
        if ( isset( $_POST['dil_settings_nonce'] ) && wp_verify_nonce( $_POST['dil_settings_nonce'], 'dil_save_settings' ) ) {
            $this->save_settings();
        }

        $settings = $this->get_settings();
        $current_tab = 'settings';

        include DIL_PLUGIN_DIR . 'admin/views/settings.php';
    }

    /**
     * Get current settings
     */
    private function get_settings(): array {
        return [
            'post_types'         => get_option( 'dil_post_types', [ 'post', 'page' ] ),
            'auto_scan'          => get_option( 'dil_auto_scan', true ),
            'min_word_count'     => get_option( 'dil_min_word_count', 3 ),
            'exclude_categories' => get_option( 'dil_exclude_categories', [] ),
            'scan_frequency'     => get_option( 'dil_scan_frequency', 'daily' ),
        ];
    }

    /**
     * Save settings
     */
    private function save_settings(): void {
        if ( isset( $_POST['dil_post_types'] ) ) {
            $post_types = array_map( 'sanitize_text_field', (array) $_POST['dil_post_types'] );
            update_option( 'dil_post_types', $post_types );
        } else {
            update_option( 'dil_post_types', [] );
        }

        update_option( 'dil_auto_scan', isset( $_POST['dil_auto_scan'] ) );

        if ( isset( $_POST['dil_min_word_count'] ) ) {
            update_option( 'dil_min_word_count', absint( $_POST['dil_min_word_count'] ) );
        }

        if ( isset( $_POST['dil_exclude_categories'] ) ) {
            $cats = array_map( 'absint', (array) $_POST['dil_exclude_categories'] );
            update_option( 'dil_exclude_categories', $cats );
        } else {
            update_option( 'dil_exclude_categories', [] );
        }

        if ( isset( $_POST['dil_scan_frequency'] ) ) {
            update_option( 'dil_scan_frequency', sanitize_text_field( $_POST['dil_scan_frequency'] ) );
        }

        add_settings_error( 'dil_settings', 'settings_saved', __( 'Settings saved.', 'dragon-internal-links' ), 'success' );
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

        $stats = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT inbound_count, outbound_count FROM {$table} WHERE post_id = %d",
                $post_id
            )
        );

        if ( ! $stats ) {
            echo '<span class="dil-no-data">—</span>';
            return;
        }

        $inbound_class = $stats->inbound_count === 0 ? 'dil-orphan' : '';

        printf(
            '<span class="dil-link-stats %s" title="%s">↓%d ↑%d</span>',
            esc_attr( $inbound_class ),
            esc_attr__( 'Inbound / Outbound links', 'dragon-internal-links' ),
            (int) $stats->inbound_count,
            (int) $stats->outbound_count
        );
    }
}
