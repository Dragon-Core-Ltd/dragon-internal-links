<?php
/**
 * Scanner Class
 *
 * Extracts and stores internal links from post content
 *
 * @package DragonInternalLinks
 */

namespace DragonInternalLinks;

class Scanner {

	/**
	 * Site URL for matching internal links
	 */
	private string $site_url;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->site_url = home_url();
		$this->init_hooks();
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks(): void {
		add_action( 'save_post', array( $this, 'on_post_save' ), 20, 2 );
		add_action( 'wp_trash_post', array( $this, 'on_post_trash' ) );
		add_action( 'untrash_post', array( $this, 'on_post_untrash' ) );
	}

	/**
	 * Scan a single post for internal links
	 *
	 * @param int $post_id Post ID to scan
	 * @return array Array of found links
	 */
	public function scan_post( int $post_id ): array {
		$post = get_post( $post_id );

		if ( ! $post || 'publish' !== $post->post_status ) {
			return array();
		}

		// Check if post type should be scanned
		$post_types = get_option( 'dragoninternallinks_post_types', array( 'post', 'page' ) );
		if ( ! in_array( $post->post_type, $post_types, true ) ) {
			return array();
		}

		// Clear existing links for this post
		$this->clear_post_links( $post_id );

		// Extract links from content
		$links = $this->extract_links( $post->post_content );

		// Store links in database
		foreach ( $links as $link ) {
			$this->store_link( $post_id, $link );
		}

		// Update stats
		$this->update_post_stats( $post_id );

		return $links;
	}

	/**
	 * Extract internal links from HTML content
	 *
	 * @param string $content HTML content
	 * @return array Array of link data
	 */
	public function extract_links( string $content ): array {
		if ( empty( $content ) ) {
			return array();
		}

		$links = array();

		// Use DOMDocument for proper HTML parsing
		$dom = new \DOMDocument();

		// Suppress warnings for malformed HTML
		libxml_use_internal_errors( true );
		$dom->loadHTML(
			'<?xml encoding="UTF-8">' . $content,
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();

		$anchors = $dom->getElementsByTagName( 'a' );

		foreach ( $anchors as $anchor ) {
			$href = $anchor->getAttribute( 'href' );

			if ( empty( $href ) ) {
				continue;
			}

			// Check if internal link
			if ( ! $this->is_internal_link( $href ) ) {
				continue;
			}

			// Resolve to post ID
			$target_post_id = $this->url_to_post_id( $href );

			if ( ! $target_post_id ) {
				continue;
			}

			// Get anchor text
			$anchor_text = trim( $anchor->textContent ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode property name.

			// Get surrounding context (parent text)
			$context = $this->get_link_context( $anchor );

			$links[] = array(
				'url'         => $href,
				'target_id'   => $target_post_id,
				'anchor_text' => mb_substr( $anchor_text, 0, 255 ),
				'context'     => mb_substr( $context, 0, 500 ),
			);
		}

		return $links;
	}

	/**
	 * Check if URL is internal
	 *
	 * @param string $url URL to check
	 * @return bool
	 */
	public function is_internal_link( string $url ): bool {
		// Skip anchors, mailto, tel, etc.
		if ( preg_match( '/^(#|mailto:|tel:|javascript:)/i', $url ) ) {
			return false;
		}

		// Relative URLs are internal
		if ( strpos( $url, '/' ) === 0 && strpos( $url, '//' ) !== 0 ) {
			return true;
		}

		// Check if URL starts with site URL
		$site_host = wp_parse_url( $this->site_url, PHP_URL_HOST );
		$link_host = wp_parse_url( $url, PHP_URL_HOST );

		return $link_host === $site_host;
	}

	/**
	 * Convert URL to post ID
	 *
	 * @param string $url URL to convert
	 * @return int|null Post ID or null
	 */
	public function url_to_post_id( string $url ): ?int {
		// Make absolute if relative
		if ( strpos( $url, '/' ) === 0 ) {
			$url = $this->site_url . $url;
		}

		$post_id = url_to_postid( $url );

		if ( $post_id > 0 ) {
			return $post_id;
		}

		// Try attachment URL
		$attachment_id = attachment_url_to_postid( $url );

		return $attachment_id > 0 ? $attachment_id : null;
	}

	/**
	 * Get context around link (surrounding text)
	 *
	 * @param \DOMElement $anchor Anchor element
	 * @return string Context text
	 */
	private function get_link_context( \DOMElement $anchor ): string {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode property names.
		$parent = $anchor->parentNode;

		if ( ! $parent ) {
			return '';
		}

		// Get parent's text content
		$text = trim( $parent->textContent );

		// If too short, try grandparent
		if ( strlen( $text ) < 50 && $parent->parentNode ) {
			$text = trim( $parent->parentNode->textContent );
		}
		// phpcs:enable

		return $text;
	}

	/**
	 * Store a link in the database
	 *
	 * @param int   $source_post_id Source post ID
	 * @param array $link           Link data
	 */
	private function store_link( int $source_post_id, array $link ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'dil_links';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table; no core API available.
		$wpdb->insert(
			$table,
			array(
				'source_post_id' => $source_post_id,
				'target_post_id' => $link['target_id'],
				'anchor_text'    => $link['anchor_text'],
				'context'        => $link['context'],
				'link_url'       => $link['url'],
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Clear all links from a post
	 *
	 * @param int $post_id Post ID
	 */
	public function clear_post_links( int $post_id ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'dil_links';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; no core API or cache available.
		$wpdb->delete(
			$table,
			array( 'source_post_id' => $post_id ),
			array( '%d' )
		);
	}

	/**
	 * Update stats for a post and its linked posts
	 *
	 * @param int $post_id Post ID
	 */
	public function update_post_stats( int $post_id ): void {
		global $wpdb;

		$table_links = $wpdb->prefix . 'dil_links';
		$table_stats = $wpdb->prefix . 'dil_stats';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; no core API or cache available.
		// Get outbound count for this post
		$outbound = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE source_post_id = %d',
				$table_links,
				$post_id
			)
		);

		// Get inbound count for this post
		$inbound = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE target_post_id = %d',
				$table_links,
				$post_id
			)
		);
		// phpcs:enable

		// Calculate orphan score
		$orphan_score = $this->calculate_orphan_score( $post_id, $inbound );

		// Upsert stats
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; no core API or cache available.
		$wpdb->replace(
			$table_stats,
			array(
				'post_id'        => $post_id,
				'inbound_count'  => $inbound,
				'outbound_count' => $outbound,
				'orphan_score'   => $orphan_score,
				'last_scanned'   => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%d', '%f', '%s' )
		);
	}

	/**
	 * Calculate orphan score for a post
	 * Higher score = more urgently needs internal links
	 *
	 * @param int $post_id      Post ID
	 * @param int $inbound_count Inbound link count
	 * @return float Orphan score
	 */
	private function calculate_orphan_score( int $post_id, int $inbound_count ): float {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return 0.0;
		}

		// Days since publish
		$publish_date = strtotime( $post->post_date );
		$days_old     = max( 1, ( time() - $publish_date ) / DAY_IN_SECONDS );

		// Base score: older posts with fewer links score higher
		$score = ( $days_old / 30 ) * ( 1 / ( $inbound_count + 1 ) );

		// Importance factor
		$importance = 1.0;

		// Check if cornerstone/featured
		if ( is_sticky( $post_id ) ) {
			$importance = 1.5;
		}

		// Check if has Content Decay data (integration)
		if ( function_exists( 'dcd_get_post_decay' ) ) {
			$decay = dcd_get_post_decay( $post_id );
			if ( $decay && isset( $decay['pageviews_current'] ) && $decay['pageviews_current'] > 100 ) {
				$importance = 2.0; // High traffic = high priority
			}
		}

		return round( $score * $importance, 2 );
	}

	/**
	 * Scan all posts in batches
	 *
	 * @param int $batch_size Posts per batch
	 * @param int $offset     Offset for pagination
	 * @return array ['scanned' => int, 'total' => int, 'complete' => bool]
	 */
	public function scan_all( int $batch_size = 50, int $offset = 0 ): array {
		$post_types = get_option( 'dragoninternallinks_post_types', array( 'post', 'page' ) );

		$args = array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => $batch_size,
			'offset'         => $offset,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		);

		$query    = new \WP_Query( $args );
		$post_ids = $query->posts;
		$total    = $query->found_posts;

		$scanned = 0;
		foreach ( $post_ids as $post_id ) {
			$this->scan_post( $post_id );
			++$scanned;
		}

		// Update stats for all affected posts
		$this->recalculate_all_stats();

		return array(
			'scanned'  => $scanned,
			'total'    => $total,
			'offset'   => $offset + $scanned,
			'complete' => ( $offset + $scanned ) >= $total,
		);
	}

	/**
	 * Recalculate stats for all posts
	 */
	public function recalculate_all_stats(): void {
		global $wpdb;

		$table_links = $wpdb->prefix . 'dil_links';
		$table_stats = $wpdb->prefix . 'dil_stats';

		// Get all unique post IDs (both source and target)
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; no core API or cache available.
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT post_id FROM (
					SELECT source_post_id AS post_id FROM %i
					UNION
					SELECT target_post_id AS post_id FROM %i
				) AS combined',
				$table_links,
				$table_links
			)
		);

		foreach ( $post_ids as $post_id ) {
			$this->update_post_stats( (int) $post_id );
		}
	}

	/**
	 * Handle post save
	 *
	 * @param int      $post_id Post ID
	 * @param \WP_Post $post    Post object
	 */
	public function on_post_save( int $post_id, \WP_Post $post ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by save_post hook signature.
		// Skip autosaves and revisions
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Skip if auto-scan disabled
		if ( ! get_option( 'dragoninternallinks_auto_scan', true ) ) {
			return;
		}

		// Scan the post
		$this->scan_post( $post_id );

		// Update stats for posts that link TO this post
		$this->update_inbound_stats( $post_id );
	}

	/**
	 * Update stats for posts linking to a specific post
	 *
	 * @param int $target_post_id Target post ID
	 */
	private function update_inbound_stats( int $target_post_id ): void {
		global $wpdb;

		$table_links = $wpdb->prefix . 'dil_links';

		// Find all posts linking to this one
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; no core API or cache available.
		$source_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT source_post_id FROM %i WHERE target_post_id = %d',
				$table_links,
				$target_post_id
			)
		);

		foreach ( $source_ids as $source_id ) {
			$this->update_post_stats( (int) $source_id );
		}

		// Also update the target post's stats
		$this->update_post_stats( $target_post_id );
	}

	/**
	 * Handle post trash
	 *
	 * @param int $post_id Post ID
	 */
	public function on_post_trash( int $post_id ): void {
		// Clear links from this post
		$this->clear_post_links( $post_id );

		// Recalculate stats for posts that linked to this post
		$this->update_inbound_stats( $post_id );
	}

	/**
	 * Handle post untrash
	 *
	 * @param int $post_id Post ID
	 */
	public function on_post_untrash( int $post_id ): void {
		// Re-scan the restored post
		$this->scan_post( $post_id );
	}

	/**
	 * Get links from a post
	 *
	 * @param int $post_id Post ID
	 * @return array Links
	 */
	public function get_post_links( int $post_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'dil_links';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; no core API or cache available.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.*, p.post_title as target_title
				 FROM %i l
				 JOIN {$wpdb->posts} p ON l.target_post_id = p.ID
				 WHERE l.source_post_id = %d
				 ORDER BY l.id ASC",
				$table,
				$post_id
			),
			ARRAY_A
		);

		return $results ? $results : array();
	}

	/**
	 * Get posts linking to a post
	 *
	 * @param int $post_id Target post ID
	 * @return array Links
	 */
	public function get_inbound_links( int $post_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'dil_links';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; no core API or cache available.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.*, p.post_title as source_title
				 FROM %i l
				 JOIN {$wpdb->posts} p ON l.source_post_id = p.ID
				 WHERE l.target_post_id = %d
				 ORDER BY l.id ASC",
				$table,
				$post_id
			),
			ARRAY_A
		);

		return $results ? $results : array();
	}

	/**
	 * Find broken internal links (links to non-existent or non-published posts)
	 *
	 * @return array Broken links
	 */
	public function find_broken_links(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'dil_links';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; no core API or cache available.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.*,
						sp.post_title as source_title,
						tp.post_title as target_title,
						tp.post_status as target_status
				 FROM %i l
				 JOIN {$wpdb->posts} sp ON l.source_post_id = sp.ID
				 LEFT JOIN {$wpdb->posts} tp ON l.target_post_id = tp.ID
				 WHERE tp.ID IS NULL
					OR tp.post_status NOT IN ('publish', 'private')
				 ORDER BY l.source_post_id ASC",
				$table
			),
			ARRAY_A
		);

		return $results ? $results : array();
	}
}
