<?php
/**
 * Ajax Class
 *
 * Handles AJAX requests
 *
 * @package DragonInternalLinks
 */

namespace DragonInternalLinks;

class Ajax {

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
        add_action( 'wp_ajax_dil_scan_all', [ $this, 'handle_scan_all' ] );
        add_action( 'wp_ajax_dil_scan_post', [ $this, 'handle_scan_post' ] );
        add_action( 'wp_ajax_dil_generate_suggestions', [ $this, 'handle_generate_suggestions' ] );
        add_action( 'wp_ajax_dil_dismiss_suggestion', [ $this, 'handle_dismiss_suggestion' ] );
        add_action( 'wp_ajax_dil_apply_suggestion', [ $this, 'handle_apply_suggestion' ] );
    }

    /**
     * Handle scan all posts request
     */
    public function handle_scan_all(): void {
        check_ajax_referer( 'dil_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dragon-internal-links' ) ] );
        }

        $offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
        $batch_size = 50;

        $result = $this->scanner->scan_all( $batch_size, $offset );

        if ( $result['complete'] ) {
            update_option( 'dil_last_scan', current_time( 'timestamp' ) );
            update_option( 'dil_last_scan_count', $result['total'] );
        }

        wp_send_json_success( [
            'scanned'  => $result['scanned'],
            'total'    => $result['total'],
            'offset'   => $result['offset'],
            'complete' => $result['complete'],
            'message'  => $result['complete']
                ? sprintf( __( 'Scan complete! Processed %d posts.', 'dragon-internal-links' ), $result['total'] )
                : sprintf( __( 'Scanning... %d / %d', 'dragon-internal-links' ), $result['offset'], $result['total'] ),
        ] );
    }

    /**
     * Handle scan single post request
     */
    public function handle_scan_post(): void {
        check_ajax_referer( 'dil_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dragon-internal-links' ) ] );
        }

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

        if ( ! $post_id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid post ID.', 'dragon-internal-links' ) ] );
        }

        $links = $this->scanner->scan_post( $post_id );

        wp_send_json_success( [
            'links_found' => count( $links ),
            'message'     => sprintf( __( 'Found %d internal links.', 'dragon-internal-links' ), count( $links ) ),
        ] );
    }

    /**
     * Handle generate suggestions request
     */
    public function handle_generate_suggestions(): void {
        check_ajax_referer( 'dil_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dragon-internal-links' ) ] );
        }

        $count = $this->analyzer->generate_all_suggestions( 30 );

        wp_send_json_success( [
            'generated' => $count,
            'message'   => sprintf( __( 'Generated %d link suggestions.', 'dragon-internal-links' ), $count ),
        ] );
    }

    /**
     * Handle dismiss suggestion request
     */
    public function handle_dismiss_suggestion(): void {
        check_ajax_referer( 'dil_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dragon-internal-links' ) ] );
        }

        $suggestion_id = isset( $_POST['suggestion_id'] ) ? absint( $_POST['suggestion_id'] ) : 0;

        if ( ! $suggestion_id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid suggestion ID.', 'dragon-internal-links' ) ] );
        }

        $this->analyzer->update_suggestion_status( $suggestion_id, 'dismissed' );

        wp_send_json_success( [
            'message' => __( 'Suggestion dismissed.', 'dragon-internal-links' ),
        ] );
    }

    /**
     * Handle apply suggestion request
     */
    public function handle_apply_suggestion(): void {
        check_ajax_referer( 'dil_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dragon-internal-links' ) ] );
        }

        $suggestion_id = isset( $_POST['suggestion_id'] ) ? absint( $_POST['suggestion_id'] ) : 0;

        if ( ! $suggestion_id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid suggestion ID.', 'dragon-internal-links' ) ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'dil_suggestions';

        $suggestion = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $suggestion_id ),
            ARRAY_A
        );

        if ( ! $suggestion ) {
            wp_send_json_error( [ 'message' => __( 'Suggestion not found.', 'dragon-internal-links' ) ] );
        }

        $post = get_post( $suggestion['source_post_id'] );
        $target_url = get_permalink( $suggestion['target_post_id'] );

        if ( ! $post || ! $target_url ) {
            wp_send_json_error( [ 'message' => __( 'Post not found.', 'dragon-internal-links' ) ] );
        }

        // Build the link HTML
        $link_html = sprintf(
            '<a href="%s">%s</a>',
            esc_url( $target_url ),
            esc_html( $suggestion['keyword'] )
        );

        // Replace first occurrence of keyword with link
        $content = $post->post_content;
        $keyword = preg_quote( $suggestion['keyword'], '/' );

        // Only replace if not already inside a link
        $pattern = '/(?<!["\'>])(' . $keyword . ')(?![^<]*<\/a>)/iu';
        $new_content = preg_replace( $pattern, $link_html, $content, 1, $count );

        if ( $count === 0 ) {
            wp_send_json_error( [ 'message' => __( 'Could not find keyword in content.', 'dragon-internal-links' ) ] );
        }

        // Update post
        wp_update_post( [
            'ID'           => $post->ID,
            'post_content' => $new_content,
        ] );

        // Mark suggestion as applied
        $this->analyzer->update_suggestion_status( $suggestion_id, 'applied' );

        // Re-scan the post
        $this->scanner->scan_post( $post->ID );

        wp_send_json_success( [
            'message'  => __( 'Link added successfully!', 'dragon-internal-links' ),
            'edit_url' => get_edit_post_link( $post->ID, 'raw' ),
        ] );
    }
}
