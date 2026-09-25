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

		// Failures so far come back from the page, so the count covers the whole
		// run rather than the last batch.
		$failed_before = isset( $_POST['failed'] ) ? absint( $_POST['failed'] ) : 0;

		$result = $this->scanner->scan_all( $batch_size, $offset );
		$failed = $failed_before + (int) ( $result['failed'] ?? 0 );

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
				'failed'   => $failed,
				'warning'  => $failed > 0 || ( $result['complete'] && false === ( $result['pruned'] ?? true ) ),
				'message'  => self::scan_message( $result, $failed ),
			)
		);
	}

	/**
	 * The progress or completion message for a scan batch.
	 *
	 * @param array $result Scan result.
	 * @param int   $failed How many posts could not be indexed.
	 * @return string
	 */
	private static function scan_message( array $result, int $failed ): string {
		if ( $result['complete'] ) {
			$message = sprintf(
				/* translators: %s: number of posts processed. */
				_n( 'Scan complete! Processed %s post.', 'Scan complete! Processed %s posts.', (int) $result['total'], 'dragon-internal-links' ),
				number_format_i18n( (int) $result['total'] )
			);
		} else {
			/* translators: 1: number of posts scanned so far, 2: total number of posts. */
			$message = sprintf( __( 'Scanning... %1$s / %2$s', 'dragon-internal-links' ), number_format_i18n( (int) $result['offset'] ), number_format_i18n( (int) $result['total'] ) );
		}

		if ( $failed > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %s: number of posts whose links could not be saved. */
				_n(
					'%s post could not be indexed and keeps its previous links.',
					'%s posts could not be indexed and keep their previous links.',
					$failed,
					'dragon-internal-links'
				),
				number_format_i18n( $failed )
			);
		}

		if ( $result['complete'] && false === ( $result['pruned'] ?? true ) ) {
			$message .= ' ' . __( 'Links from posts that are no longer published, scanned or included could not be removed, so some link counts may be too high. Run the scan again.', 'dragon-internal-links' );
		}

		return $message;
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

		// The links are returned even when the index could not be replaced, so
		// counting them alone would report a scan that did not actually store
		// anything.
		if ( $this->scanner->scan_failed() ) {
			wp_send_json_error( array( 'message' => __( 'The links could not be saved, so the index is unchanged. Please try again.', 'dragon-internal-links' ) ) );
		}

		wp_send_json_success(
			array(
				'links_found' => count( $links ),
				'message'     => sprintf(
					/* translators: %s: number of internal links found. */
					_n( 'Found %s internal link.', 'Found %s internal links.', count( $links ), 'dragon-internal-links' ),
					number_format_i18n( count( $links ) )
				),
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

		$batch_size = 20;
		$offset     = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;

		// Failures and a stale list so far come back from the page, so the
		// warning covers the whole run rather than the last batch.
		$failed_before = isset( $_POST['failed'] ) ? absint( $_POST['failed'] ) : 0;
		$stale_before  = ! empty( $_POST['stale'] );

		$result = $this->analyzer->generate_all_suggestions( $batch_size, $offset );

		$failed = $failed_before + (int) ( $result['failed'] ?? 0 );
		$stale  = $stale_before || ! empty( $result['stale'] );

		if ( $result['done'] ) {
			$message = sprintf(
				/* translators: %s: total posts analyzed for link suggestions. */
				_n( 'Done - analyzed %s post for link suggestions.', 'Done - analyzed %s posts for link suggestions.', (int) $result['total'], 'dragon-internal-links' ),
				number_format_i18n( (int) $result['total'] )
			);
		} else {
			/* translators: 1: posts processed so far, 2: total posts. */
			$message = sprintf( __( 'Generating... %1$s / %2$s', 'dragon-internal-links' ), number_format_i18n( (int) $result['offset'] ), number_format_i18n( (int) $result['total'] ) );
		}

		// A suggestion that could not be stored is not on the list, so a run that
		// reported only the total would read as "nothing to suggest".
		if ( $failed > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %s: number of suggestions that could not be saved. */
				_n(
					'%s suggestion could not be saved.',
					'%s suggestions could not be saved.',
					$failed,
					'dragon-internal-links'
				),
				number_format_i18n( $failed )
			);
		}

		if ( $stale ) {
			$message .= ' ' . __( 'Previous suggestions could not be cleared first, so the list may mix this run with an earlier one.', 'dragon-internal-links' );
		}

		wp_send_json_success(
			array(
				'generated' => $result['generated'],
				'failed'    => $failed,
				'stale'     => $stale,
				'warning'   => $failed > 0 || $stale,
				'offset'    => $result['offset'],
				'total'     => $result['total'],
				'done'      => $result['done'],
				'message'   => $message,
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

		if ( ! $this->analyzer->update_suggestion_status( $suggestion_id, 'dismissed' ) ) {
			wp_send_json_error( array( 'message' => __( 'The suggestion could not be updated. Please try again.', 'dragon-internal-links' ) ) );
		}

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

		// The target may have been drafted, trashed or excluded since the
		// suggestion was made; linking to it now would add a ?p= or __trashed URL.
		// The list leaves out such suggestions, and those from a source that has
		// left the scan, so a stale page is refused the same way.
		if ( ! Scanner::in_scope( get_post( $suggestion['target_post_id'] ) ) || ! Scanner::in_scope( $post ) ) {
			wp_send_json_error( array( 'message' => __( 'This suggestion is out of date: its post or the page it links to is no longer published or is excluded from linking, so no change was made. Regenerate suggestions to refresh the list.', 'dragon-internal-links' ) ) );
		}

		// Applying a suggestion edits the source post's content, so require edit
		// rights on that specific post — manage_options alone must not let a role
		// without edit access mutate arbitrary posts.
		if ( ! current_user_can( 'edit_post', (int) $post->ID ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'dragon-internal-links' ) ) );
		}

		// Link the first text occurrence of the keyword (never inside a tag,
		// a block delimiter or an existing link).
		$linker      = new Linker();
		$new_content = $linker->insert( (string) $post->post_content, (string) $suggestion['keyword'], (string) $target_url );

		if ( $linker->regex_failed() ) {
			wp_send_json_error( array( 'message' => __( 'The post content could not be processed, so no change was made.', 'dragon-internal-links' ) ) );
		}

		if ( null === $new_content ) {
			wp_send_json_error( array( 'message' => __( 'Could not find keyword in content.', 'dragon-internal-links' ) ) );
		}

		if ( ! $linker->save( (int) $post->ID, $new_content ) ) {
			wp_send_json_error( array( 'message' => __( 'The post could not be saved, so no change was made.', 'dragon-internal-links' ) ) );
		}

		// Mark suggestion as applied
		if ( ! $this->analyzer->update_suggestion_status( $suggestion_id, 'applied' ) ) {
			wp_send_json_error( array( 'message' => __( 'The link was added, but the suggestion could not be marked as applied. Refresh the page.', 'dragon-internal-links' ) ) );
		}

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
