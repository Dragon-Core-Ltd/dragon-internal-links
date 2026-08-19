<?php
/**
 * Ajax Class
 *
 * Handles AJAX requests
 *
 * @package DragonInternalLinks
 */

namespace DragonInternalLinks;

defined( 'ABSPATH' ) || exit;

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
		add_action( 'wp_ajax_dragoninternallinks_scan_all', array( $this, 'handle_scan_all' ) );
		add_action( 'wp_ajax_dragoninternallinks_scan_post', array( $this, 'handle_scan_post' ) );
		add_action( 'wp_ajax_dragoninternallinks_generate_suggestions', array( $this, 'handle_generate_suggestions' ) );
		add_action( 'wp_ajax_dragoninternallinks_dismiss_suggestion', array( $this, 'handle_dismiss_suggestion' ) );
		add_action( 'wp_ajax_dragoninternallinks_apply_suggestion', array( $this, 'handle_apply_suggestion' ) );
	}

	/**
	 * Handle scan all posts request
	 */
	public function handle_scan_all(): void {
		check_ajax_referer( 'dragoninternallinks_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'dragon-internal-links' ) ) );
		}

		$offset     = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$batch_size = 50;

		$result = $this->scanner->scan_all( $batch_size, $offset );

		if ( $result['complete'] ) {
			// phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- Local timestamp is intentional; displayed via date_i18n().
			update_option( 'dragoninternallinks_last_scan', current_time( 'timestamp' ) );
			update_option( 'dragoninternallinks_last_scan_count', $result['total'] );
		}

		wp_send_json_success(
			array(
				'scanned'  => $result['scanned'],
				'total'    => $result['total'],
				'offset'   => $result['offset'],
				'complete' => $result['complete'],
				'message'  => $result['complete']
					/* translators: %d: number of posts processed. */
					? sprintf( __( 'Scan complete! Processed %d posts.', 'dragon-internal-links' ), $result['total'] )
					/* translators: 1: number of posts scanned so far, 2: total number of posts. */
					: sprintf( __( 'Scanning... %1$d / %2$d', 'dragon-internal-links' ), $result['offset'], $result['total'] ),
			)
		);
	}

	/**
	 * Handle scan single post request
	 */
	public function handle_scan_post(): void {
		check_ajax_referer( 'dragoninternallinks_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'dragon-internal-links' ) ) );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post ID.', 'dragon-internal-links' ) ) );
		}

		$links = $this->scanner->scan_post( $post_id );

		wp_send_json_success(
			array(
				'links_found' => count( $links ),
				/* translators: %d: number of internal links found. */
				'message'     => sprintf( __( 'Found %d internal links.', 'dragon-internal-links' ), count( $links ) ),
			)
		);
	}

	/**
	 * Handle generate suggestions request
	 */
	public function handle_generate_suggestions(): void {
		check_ajax_referer( 'dragoninternallinks_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'dragon-internal-links' ) ) );
		}

		$count = $this->analyzer->generate_all_suggestions( 30 );

		wp_send_json_success(
			array(
				'generated' => $count,
				/* translators: %d: number of link suggestions generated. */
				'message'   => sprintf( __( 'Generated %d link suggestions.', 'dragon-internal-links' ), $count ),
			)
		);
	}

	/**
	 * Handle dismiss suggestion request
	 */
	public function handle_dismiss_suggestion(): void {
		check_ajax_referer( 'dragoninternallinks_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'dragon-internal-links' ) ) );
		}

		$suggestion_id = isset( $_POST['suggestion_id'] ) ? absint( $_POST['suggestion_id'] ) : 0;

		if ( ! $suggestion_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid suggestion ID.', 'dragon-internal-links' ) ) );
		}

		$this->analyzer->update_suggestion_status( $suggestion_id, 'dismissed' );

		wp_send_json_success(
			array(
				'message' => __( 'Suggestion dismissed.', 'dragon-internal-links' ),
			)
		);
	}

	/**
	 * Handle apply suggestion request
	 */
	public function handle_apply_suggestion(): void {
		check_ajax_referer( 'dragoninternallinks_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'dragon-internal-links' ) ) );
		}

		$suggestion_id = isset( $_POST['suggestion_id'] ) ? absint( $_POST['suggestion_id'] ) : 0;

		if ( ! $suggestion_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid suggestion ID.', 'dragon-internal-links' ) ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'dil_suggestions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; no core API or cache available.
		$suggestion = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $suggestion_id ),
			ARRAY_A
		);

		if ( ! $suggestion ) {
			wp_send_json_error( array( 'message' => __( 'Suggestion not found.', 'dragon-internal-links' ) ) );
		}

		$post       = get_post( $suggestion['source_post_id'] );
		$target_url = get_permalink( $suggestion['target_post_id'] );

		if ( ! $post || ! $target_url ) {
			wp_send_json_error( array( 'message' => __( 'Post not found.', 'dragon-internal-links' ) ) );
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
		$pattern     = '/(?<!["\'>])(' . $keyword . ')(?![^<]*<\/a>)/iu';
		$new_content = preg_replace( $pattern, $link_html, $content, 1, $count );

		if ( 0 === $count ) {
			wp_send_json_error( array( 'message' => __( 'Could not find keyword in content.', 'dragon-internal-links' ) ) );
		}

		// Update post
		wp_update_post(
			array(
				'ID'           => $post->ID,
				'post_content' => $new_content,
			)
		);

		// Mark suggestion as applied
		$this->analyzer->update_suggestion_status( $suggestion_id, 'applied' );

		// Re-scan the post
		$this->scanner->scan_post( $post->ID );

		wp_send_json_success(
			array(
				'message'  => __( 'Link added successfully!', 'dragon-internal-links' ),
				'edit_url' => get_edit_post_link( $post->ID, 'raw' ),
			)
		);
	}
}
