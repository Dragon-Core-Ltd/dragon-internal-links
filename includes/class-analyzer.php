<?php
/**
 * Analyzer Class
 *
 * Handles orphan detection and link suggestions
 *
 * @package DragonInternalLinks
 */

namespace DragonInternalLinks;

defined( 'ABSPATH' ) || exit;

class Analyzer {

	/**
	 * Scanner instance
	 */
	private Scanner $scanner;

	/**
	 * Constructor
	 */
	public function __construct( Scanner $scanner ) {
		$this->scanner = $scanner;
	}

	/**
	 * Get orphan posts (posts with no inbound links)
	 *
	 * @param int $limit Max results
	 * @return array Orphan posts with stats
	 */
	public function get_orphan_posts( int $limit = 50 ): array {
		global $wpdb;

		$table_stats  = $wpdb->prefix . 'dil_stats';
		$post_types   = get_option( 'dragoninternallinks_post_types', array( 'post', 'page' ) );
		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom plugin table (no core API/cache); $placeholders is a dynamically generated list of %s placeholders.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*, p.post_title, p.post_date, p.post_type, p.post_modified
				 FROM %i s
				 JOIN {$wpdb->posts} p ON s.post_id = p.ID
				 WHERE s.inbound_count = 0
				 AND p.post_status = 'publish'
				 AND p.post_type IN ({$placeholders})
				 ORDER BY s.orphan_score DESC
				 LIMIT %d",
				...array_merge( array( $table_stats ), $post_types, array( $limit ) )
			),
			ARRAY_A
		);
		// phpcs:enable

		return $results ? $results : array();
	}

	/**
	 * Get posts that need more outbound links
	 *
	 * @param int $limit Max results
	 * @param int $max_outbound Consider posts with fewer than this many outbound links
	 * @return array Posts with low outbound counts
	 */
	public function get_low_outbound_posts( int $limit = 50, int $max_outbound = 2 ): array {
		global $wpdb;

		$table_stats  = $wpdb->prefix . 'dil_stats';
		$post_types   = get_option( 'dragoninternallinks_post_types', array( 'post', 'page' ) );
		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom plugin table (no core API/cache); $placeholders is a dynamically generated list of %s placeholders.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*, p.post_title, p.post_date, p.post_type
				 FROM %i s
				 JOIN {$wpdb->posts} p ON s.post_id = p.ID
				 WHERE s.outbound_count <= %d
				 AND p.post_status = 'publish'
				 AND p.post_type IN ({$placeholders})
				 ORDER BY s.outbound_count ASC, p.post_date DESC
				 LIMIT %d",
				...array_merge( array( $table_stats, $max_outbound ), $post_types, array( $limit ) )
			),
			ARRAY_A
		);
		// phpcs:enable

		return $results ? $results : array();
	}

	/**
	 * Get top linked posts
	 *
	 * @param int $limit Max results
	 * @return array Top posts by inbound links
	 */
	public function get_top_linked_posts( int $limit = 20 ): array {
		global $wpdb;

		$table_stats = $wpdb->prefix . 'dil_stats';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; no core API or cache available.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*, p.post_title, p.post_type
				 FROM %i s
				 JOIN {$wpdb->posts} p ON s.post_id = p.ID
				 WHERE p.post_status = 'publish'
				 AND s.inbound_count > 0
				 ORDER BY s.inbound_count DESC
				 LIMIT %d",
				$table_stats,
				$limit
			),
			ARRAY_A
		);

		return $results ? $results : array();
	}

	/**
	 * Generate link suggestions for a post
	 *
	 * @param int $post_id Source post ID
	 * @return array Suggestions
	 */
	public function generate_suggestions_for_post( int $post_id ): array {
		$post = get_post( $post_id );

		if ( ! $post || 'publish' !== $post->post_status ) {
			return array();
		}

		$suggestions = array();
		$post_types  = get_option( 'dragoninternallinks_post_types', array( 'post', 'page' ) );
		$min_words   = (int) get_option( 'dragoninternallinks_min_word_count', 3 );

		// Get existing outbound links to avoid duplicates
		$existing_links  = $this->scanner->get_post_links( $post_id );
		$linked_post_ids = array_column( $existing_links, 'target_post_id' );

		// Get potential target posts (exclude self and already linked)
		$exclude_ids = array_merge( array( $post_id ), array_map( 'intval', $linked_post_ids ) );

		$targets = get_posts(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'exclude'        => $exclude_ids, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Admin-side suggestion generation on a bounded result set.
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$content = strtolower( wp_strip_all_tags( $post->post_content ) );

		foreach ( $targets as $target ) {
			// Extract keywords from target title
			$keywords = $this->extract_keywords( $target->post_title, $min_words );

			foreach ( $keywords as $keyword ) {
				// Check if keyword appears in source content
				$keyword_lower = strtolower( $keyword );

				if ( strpos( $content, $keyword_lower ) !== false ) {
					// Find context around keyword
					$context = $this->find_keyword_context( $post->post_content, $keyword );

					if ( $context ) {
						$relevance = $this->calculate_relevance( $keyword, $target, $post );

						$suggestions[] = array(
							'source_post_id' => $post_id,
							'target_post_id' => $target->ID,
							'keyword'        => $keyword,
							'context'        => $context,
							'relevance'      => $relevance,
							'target_title'   => $target->post_title,
						);
					}
				}
			}
		}

		// Re-score: TF-IDF document similarity (always), then optional AI
		// re-ranking with the owner's own API key. Both fail open to the
		// heuristic score.
		$suggestions = $this->rescore_suggestions( $post, $suggestions );

		// Sort by relevance
		usort( $suggestions, fn( $a, $b ) => $b['relevance'] <=> $a['relevance'] );

		// Limit and dedupe by target
		$seen_targets = array();
		$filtered     = array();

		foreach ( $suggestions as $suggestion ) {
			if ( ! isset( $seen_targets[ $suggestion['target_post_id'] ] ) ) {
				$filtered[]                                    = $suggestion;
				$seen_targets[ $suggestion['target_post_id'] ] = true;
			}

			if ( count( $filtered ) >= 10 ) {
				break;
			}
		}

		return $filtered;
	}

	/**
	 * Generate suggestions for all posts
	 *
	 * @param int $batch_size Posts per batch
	 * @return int Number of suggestions generated
	 */
	public function generate_all_suggestions( int $batch_size = 20 ): int {
		global $wpdb;

		// Clear old pending suggestions
		$table = $wpdb->prefix . 'dil_suggestions';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; no core API or cache available.
		$wpdb->delete( $table, array( 'status' => 'pending' ), array( '%s' ) );

		$post_types = get_option( 'dragoninternallinks_post_types', array( 'post', 'page' ) );

		$posts = get_posts(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'posts_per_page' => $batch_size,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);

		$total = 0;

		foreach ( $posts as $post ) {
			$suggestions = $this->generate_suggestions_for_post( $post->ID );

			foreach ( $suggestions as $suggestion ) {
				$this->store_suggestion( $suggestion );
				++$total;
			}
		}

		return $total;
	}

	/**
	 * Upgrade heuristic scores to TF-IDF similarity, then optionally to AI
	 * relevance. Final relevance is 0-100. Any failure leaves the previous
	 * score for that suggestion untouched.
	 *
	 * @param \WP_Post $post        Source post.
	 * @param array    $suggestions Suggestions with heuristic 'relevance'.
	 * @return array Suggestions with upgraded 'relevance'.
	 */
	private function rescore_suggestions( \WP_Post $post, array $suggestions ): array {
		if ( array() === $suggestions ) {
			return $suggestions;
		}

		$source_text = $post->post_title . ' ' . wp_strip_all_tags( $post->post_content );

		// One text per suggestion, keyed by suggestion index.
		$target_texts = array();
		$target_cache = array();
		foreach ( $suggestions as $i => $s ) {
			$tid = (int) $s['target_post_id'];
			if ( ! isset( $target_cache[ $tid ] ) ) {
				$target               = get_post( $tid );
				$target_cache[ $tid ] = $target
					? $target->post_title . ' ' . wp_strip_all_tags( mb_substr( (string) $target->post_content, 0, 4000 ) )
					: '';
			}
			$target_texts[ $i ] = $target_cache[ $tid ];
		}

		$cosines = Relevance::batch_scores( $source_text, $target_texts );
		$max     = max( array_merge( array( 0.0 ), array_values( $cosines ) ) );

		foreach ( $suggestions as $i => $s ) {
			// Blend: mostly document similarity (relative to the best in this
			// batch so scores are comparable), a little of the old heuristic.
			$lexical = $max > 0 ? ( $cosines[ $i ] / $max ) : 0.0;
			$legacy  = min( 1.0, (float) $s['relevance'] / 5.0 );

			$suggestions[ $i ]['relevance'] = round( 100 * ( ( 0.7 * $lexical ) + ( 0.3 * $legacy ) ), 1 );
		}

		if ( AI_Ranker::enabled() ) {
			// Send only the current front-runners — one API call per post.
			$order = array_keys( $suggestions );
			usort( $order, fn( $a, $b ) => $suggestions[ $b ]['relevance'] <=> $suggestions[ $a ]['relevance'] );
			$top = array_slice( $order, 0, 12 );

			$candidates = array();
			foreach ( $top as $i ) {
				$candidates[ $i ] = array(
					'title'   => (string) $suggestions[ $i ]['target_title'],
					'excerpt' => (string) $target_texts[ $i ],
					'keyword' => (string) $suggestions[ $i ]['keyword'],
				);
			}

			$scores = AI_Ranker::rank(
				array(
					'title' => $post->post_title,
					'text'  => wp_strip_all_tags( $post->post_content ),
				),
				$candidates
			);

			if ( null !== $scores ) {
				foreach ( $scores as $i => $score ) {
					if ( isset( $suggestions[ $i ] ) ) {
						$suggestions[ $i ]['relevance'] = (float) $score;
					}
				}
			}
		}

		return $suggestions;
	}

	/**
	 * Extract keywords from text
	 *
	 * @param string $text      Text to extract from
	 * @param int    $min_words Minimum words in phrase
	 * @return array Keywords
	 */
	private function extract_keywords( string $text, int $min_words = 3 ): array {
		// Remove common stop words
		$stop_words = array( 'the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'is', 'are', 'was', 'were', 'be', 'been', 'being', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could', 'should', 'may', 'might', 'must', 'shall', 'can', 'this', 'that', 'these', 'those', 'i', 'you', 'he', 'she', 'it', 'we', 'they', 'what', 'which', 'who', 'when', 'where', 'why', 'how', 'all', 'each', 'every', 'both', 'few', 'more', 'most', 'other', 'some', 'such', 'no', 'nor', 'not', 'only', 'own', 'same', 'so', 'than', 'too', 'very', 'just', 'also' );

		$keywords = array();

		// Full title as keyword
		$clean_title = preg_replace( '/[^\w\s]/u', '', $text );
		$words       = preg_split( '/\s+/', trim( $clean_title ) );

		if ( count( $words ) >= $min_words ) {
			$keywords[] = $clean_title;
		}

		// Also try shorter meaningful phrases
		$meaningful_words = array_filter( $words, fn( $w ) => ! in_array( strtolower( $w ), $stop_words, true ) && strlen( $w ) > 2 );

		if ( count( $meaningful_words ) >= 2 ) {
			$keywords[] = implode( ' ', array_slice( $meaningful_words, 0, 3 ) );
		}

		return array_unique( $keywords );
	}

	/**
	 * Find context around keyword in content
	 *
	 * @param string $content HTML content
	 * @param string $keyword Keyword to find
	 * @return string|null Context sentence or null
	 */
	private function find_keyword_context( string $content, string $keyword ): ?string {
		$text          = wp_strip_all_tags( $content );
		$keyword_lower = strtolower( $keyword );
		$text_lower    = strtolower( $text );

		$pos = strpos( $text_lower, $keyword_lower );

		if ( false === $pos ) {
			return null;
		}

		// Extract surrounding text (about 150 chars each side)
		$start  = max( 0, $pos - 100 );
		$length = strlen( $keyword ) + 200;

		$context = substr( $text, $start, $length );

		// Try to start at word boundary
		if ( $start > 0 ) {
			$space_pos = strpos( $context, ' ' );
			if ( false !== $space_pos && $space_pos < 20 ) {
				$context = substr( $context, $space_pos + 1 );
			}
			$context = '...' . $context;
		}

		// Try to end at word boundary
		$last_space = strrpos( $context, ' ' );
		if ( false !== $last_space && $last_space > strlen( $context ) - 20 ) {
			$context = substr( $context, 0, $last_space );
		}

		if ( strlen( $context ) < strlen( $text ) ) {
			$context .= '...';
		}

		return trim( $context );
	}

	/**
	 * Calculate relevance score for a suggestion
	 *
	 * @param string   $keyword Keyword
	 * @param \WP_Post $target  Target post
	 * @param \WP_Post $source  Source post
	 * @return float Relevance score
	 */
	private function calculate_relevance( string $keyword, \WP_Post $target, \WP_Post $source ): float {
		$score = 1.0;

		// Exact title match bonus
		if ( strtolower( $keyword ) === strtolower( $target->post_title ) ) {
			$score *= 1.5;
		}

		// Keyword length bonus (longer = more specific)
		$word_count = str_word_count( $keyword );
		$score     *= 1 + ( $word_count * 0.1 );

		// Freshness bonus for target
		$target_age = ( time() - strtotime( $target->post_date ) ) / DAY_IN_SECONDS;
		if ( $target_age < 30 ) {
			$score *= 1.2;
		} elseif ( $target_age < 90 ) {
			$score *= 1.1;
		}

		// Same category bonus
		$source_cats = wp_get_post_categories( $source->ID );
		$target_cats = wp_get_post_categories( $target->ID );

		if ( ! empty( array_intersect( $source_cats, $target_cats ) ) ) {
			$score *= 1.3;
		}

		return round( $score, 2 );
	}

	/**
	 * Store a suggestion in the database
	 *
	 * @param array $suggestion Suggestion data
	 */
	private function store_suggestion( array $suggestion ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'dil_suggestions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table; no core API available.
		$wpdb->insert(
			$table,
			array(
				'source_post_id'  => $suggestion['source_post_id'],
				'target_post_id'  => $suggestion['target_post_id'],
				'keyword'         => $suggestion['keyword'],
				'context'         => $suggestion['context'],
				'relevance_score' => $suggestion['relevance'],
				'status'          => 'pending',
			),
			array( '%d', '%d', '%s', '%s', '%f', '%s' )
		);
	}

	/**
	 * Get pending suggestions
	 *
	 * @param int $limit Max results
	 * @return array Suggestions
	 */
	public function get_suggestions( int $limit = 50 ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'dil_suggestions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; no core API or cache available.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*,
						sp.post_title as source_title,
						tp.post_title as target_title
				 FROM %i s
				 JOIN {$wpdb->posts} sp ON s.source_post_id = sp.ID
				 JOIN {$wpdb->posts} tp ON s.target_post_id = tp.ID
				 WHERE s.status = 'pending'
				 ORDER BY s.relevance_score DESC
				 LIMIT %d",
				$table,
				$limit
			),
			ARRAY_A
		);

		return $results ? $results : array();
	}

	/**
	 * Update suggestion status
	 *
	 * @param int    $suggestion_id Suggestion ID
	 * @param string $status        New status (applied, dismissed)
	 */
	public function update_suggestion_status( int $suggestion_id, string $status ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'dil_suggestions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; no core API or cache available.
		$wpdb->update(
			$table,
			array( 'status' => $status ),
			array( 'id' => $suggestion_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Get summary statistics
	 *
	 * @return array Stats
	 */
	public function get_summary(): array {
		global $wpdb;

		$table_links       = $wpdb->prefix . 'dil_links';
		$table_stats       = $wpdb->prefix . 'dil_stats';
		$table_suggestions = $wpdb->prefix . 'dil_suggestions';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables; no core API or cache available.
		$total_links         = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table_links ) );
		$total_posts         = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table_stats ) );
		$orphan_posts        = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE inbound_count = 0', $table_stats ) );
		$pending_suggestions = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'pending'", $table_suggestions ) );

		$avg_inbound  = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT AVG(inbound_count) FROM %i', $table_stats ) );
		$avg_outbound = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT AVG(outbound_count) FROM %i', $table_stats ) );
		// phpcs:enable

		$broken_links = count( $this->scanner->find_broken_links() );

		return array(
			'total_links'         => $total_links,
			'total_posts_scanned' => $total_posts,
			'orphan_posts'        => $orphan_posts,
			'pending_suggestions' => $pending_suggestions,
			'broken_links'        => $broken_links,
			'avg_inbound'         => round( $avg_inbound, 1 ),
			'avg_outbound'        => round( $avg_outbound, 1 ),
		);
	}
}
