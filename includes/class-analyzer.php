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
	 * Maximum keyword length in characters (the keyword column is varchar(255)).
	 */
	private const KEYWORD_MAX_LENGTH = 255;

	/**
	 * Throwaway link target for the dry-run insert that confirms a suggestion
	 * can be applied (the .invalid TLD never resolves).
	 */
	private const PROBE_URL = 'https://dragon-internal-links.invalid/probe';

	/**
	 * Private-use characters that mark where the dry-run link starts and ends
	 * in the post's text.
	 */
	private const PROBE_OPEN  = "\u{E000}";
	private const PROBE_CLOSE = "\u{E001}";

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

		$scope = $this->report_scope( 's.inbound_count = 0' );
		if ( null === $scope ) {
			return array();
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom plugin table (no core API/cache); the scope clause is built from fixed fragments and generated placeholders.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*, p.post_title, p.post_date, p.post_type, p.post_modified
				 FROM %i s
				 JOIN {$wpdb->posts} p ON s.post_id = p.ID
				 {$scope['where']}
				 ORDER BY s.orphan_score DESC
				 LIMIT %d",
				...array_merge( array( $wpdb->prefix . 'dil_stats' ), $scope['args'], array( $limit ) )
			),
			ARRAY_A
		);
		// phpcs:enable

		return $results ? $results : array();
	}

	/**
	 * WHERE clause, joined as "s" (stats) and "p" (posts), for the posts the
	 * reports cover: the given stats condition, published, a scanned post type,
	 * and not in an excluded category. Shared by the orphan list, the dashboard
	 * orphan count and the low-outbound list so they always agree.
	 *
	 * @param string $condition      Stats condition, with placeholders.
	 * @param array  $condition_args Values for the condition's placeholders.
	 * @param string $alias          Alias of the posts table the scope applies to.
	 * @return array{where: string, args: array}|null Null when no post type is
	 *                                               scanned, so nothing matches.
	 */
	private function report_scope( string $condition, array $condition_args = array(), string $alias = 'p' ): ?array {
		global $wpdb;

		$post_types = Scanner::post_types();
		if ( array() === $post_types ) {
			return null;
		}

		$where = "WHERE {$condition}
				 AND {$alias}.post_status = 'publish'
				 AND {$alias}.post_type IN (" . implode( ',', array_fill( 0, count( $post_types ), '%s' ) ) . ')';
		$args  = array_merge( $condition_args, $post_types );

		$excluded = Scanner::excluded_categories();
		if ( array() !== $excluded ) {
			$where .= "
				 AND NOT EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr
					JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
					WHERE tr.object_id = {$alias}.ID AND tt.taxonomy = 'category'
					AND tt.term_id IN (" . implode( ',', array_fill( 0, count( $excluded ), '%d' ) ) . '))';
			$args   = array_merge( $args, $excluded );
		}

		return array(
			'where' => $where,
			'args'  => $args,
		);
	}

	/**
	 * WHERE clause for pending suggestions, joined as "s" (suggestions), "sp"
	 * (source post) and "p" (target post): both posts must still be ones the
	 * reports cover. Shared by the suggestion list and the dashboard count.
	 *
	 * @return array{where: string, args: array}|null Null when no post type is scanned.
	 */
	private function suggestion_scope(): ?array {
		$target = $this->report_scope( "s.status = 'pending'" );
		$source = $this->report_scope( '1 = 1', array(), 'sp' );
		if ( null === $target || null === $source ) {
			return null;
		}

		return array(
			'where' => $target['where'] . "\n\t\t\t\t AND " . substr( $source['where'], strlen( 'WHERE ' ) ),
			'args'  => array_merge( $target['args'], $source['args'] ),
		);
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

		$scope = $this->report_scope( 's.outbound_count <= %d', array( $max_outbound ) );
		if ( null === $scope ) {
			return array();
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom plugin table (no core API/cache); the scope clause is built from fixed fragments and generated placeholders.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*, p.post_title, p.post_date, p.post_type
				 FROM %i s
				 JOIN {$wpdb->posts} p ON s.post_id = p.ID
				 {$scope['where']}
				 ORDER BY s.outbound_count ASC, p.post_date DESC
				 LIMIT %d",
				...array_merge( array( $wpdb->prefix . 'dil_stats' ), $scope['args'], array( $limit ) )
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

		$post_types = Scanner::post_types();
		if ( array() === $post_types || Scanner::in_excluded_category( $post ) ) {
			return array();
		}

		$suggestions = array();
		$min_words   = (int) get_option( 'dragoninternallinks_min_word_count', 3 );

		// Get existing outbound links to avoid duplicates
		$existing_links  = $this->scanner->get_post_links( $post_id );
		$linked_post_ids = array_column( $existing_links, 'target_post_id' );

		// Get potential target posts: not itself, not already linked, and not one
		// the owner dismissed for this post (dismissed rows are kept for this).
		$exclude_ids = array_values(
			array_unique(
				array_merge( array( $post_id ), array_map( 'intval', $linked_post_ids ), $this->dismissed_targets( $post_id ) )
			)
		);

		$target_args = array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'exclude'        => $exclude_ids, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Admin-side suggestion generation on a bounded result set.
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		$excluded_categories = Scanner::excluded_categories();
		if ( array() !== $excluded_categories ) {
			$target_args['category__not_in'] = $excluded_categories;
		}

		$targets = get_posts( $target_args );

		foreach ( $targets as $target ) {
			// Extract keywords from target title
			$keywords = $this->extract_keywords( $target->post_title, $min_words );

			foreach ( $keywords as $keyword ) {
				// The keyword must appear as whole words, exactly as the linker
				// will look for it when the suggestion is applied.
				$context = $this->find_keyword_context( $post->post_content, $keyword );

				if ( null === $context || '' === $context ) {
					continue;
				}

				$suggestions[] = array(
					'source_post_id' => $post_id,
					'target_post_id' => $target->ID,
					'keyword'        => $keyword,
					'context'        => $context,
					'relevance'      => $this->calculate_relevance( $keyword, $target, $post ),
					'target_title'   => $target->post_title,
				);
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
	 * Targets the owner has dismissed as suggestions for a source post.
	 *
	 * @param int $post_id Source post ID.
	 * @return int[]
	 */
	private function dismissed_targets( int $post_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; no core API or cache available.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT target_post_id FROM %i WHERE source_post_id = %d AND status = 'dismissed'",
				$wpdb->prefix . 'dil_suggestions',
				$post_id
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Generate suggestions for a batch of posts (resumable).
	 *
	 * Processes one offset-based page so every post is eventually covered, not
	 * just the newest batch. Stale pending suggestions are cleared once, at the
	 * start of a pass (offset 0), so a mid-pass call never destroys the results
	 * earlier batches produced. Ordering is by ID (stable across a pass, unlike
	 * "modified" which shifts as posts are edited).
	 *
	 * @param int $batch_size Posts per batch.
	 * @param int $offset     Post offset to start from.
	 * @return array{generated:int,failed:int,stale:bool,offset:int,total:int,done:bool}
	 */
	public function generate_all_suggestions( int $batch_size = 20, int $offset = 0 ): array {
		global $wpdb;

		$stale = false;

		if ( 0 === $offset ) {
			// Fresh pass: clear old pending suggestions once. If the clear fails
			// the new pass is mixed in with the previous one, so the caller is told
			// rather than presenting the totals as a fresh result.
			$table = $wpdb->prefix . 'dil_suggestions';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; no core API or cache available.
			$stale = false === $wpdb->delete( $table, array( 'status' => 'pending' ), array( '%s' ) );
		}

		$post_types = Scanner::post_types();

		if ( array() === $post_types ) {
			// An empty post_type would make WP_Query fall back to "post".
			$ids   = array();
			$total = 0;
		} else {
			$args = array(
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'posts_per_page' => $batch_size,
				'offset'         => $offset,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
			);

			$excluded = Scanner::excluded_categories();
			if ( array() !== $excluded ) {
				$args['category__not_in'] = $excluded;
			}

			$query = new \WP_Query( $args );
			$ids   = array_map( 'intval', $query->posts );
			$total = (int) $query->found_posts;
		}

		$generated = 0;
		$failed    = 0;

		foreach ( $ids as $post_id ) {
			foreach ( $this->generate_suggestions_for_post( $post_id ) as $suggestion ) {
				if ( $this->store_suggestion( $suggestion ) ) {
					++$generated;
					continue;
				}

				// Pagination advances and the run completes either way, so an
				// unreported failure reads as "analysed everything, found nothing".
				++$failed;
			}
		}

		$next = $offset + count( $ids );

		return array(
			'generated' => $generated,
			'failed'    => $failed,
			'stale'     => $stale,
			'offset'    => $next,
			'total'     => $total,
			'done'      => array() === $ids || $next >= $total,
		);
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

		// The title as written: entities decoded (a title saved through kses
		// holds "&amp;"), whitespace collapsed, and sentence punctuation at the
		// end dropped so "What Is Cold Brew?" matches mid-sentence. Apostrophes,
		// hyphens, colons and ampersands stay; Linker::keyword_pattern() matches
		// each spelling they may have in the content.
		$title = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$title = trim( (string) preg_replace( '/\s+/', ' ', $title ) );
		$title = rtrim( $title, " ?!.,;:\u{2026}" );

		$words = '' === $title ? array() : explode( ' ', $title );

		$counted = array_filter( $words, fn( $w ) => 1 === preg_match( '/[\p{L}\p{N}]/u', $w ) || 1 !== preg_match( '//u', $w ) );
		if ( count( $counted ) >= $min_words ) {
			$keywords[] = self::cap_keyword( $title );
		}

		// Also try a shorter phrase: the first run of adjacent meaningful words
		// (no stop word or punctuation between them), held to the same Minimum
		// Keyword Words setting as the full title.
		$runs = array();
		$run  = array();
		foreach ( $words as $word ) {
			$core = preg_replace( '/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', $word );
			$core = is_string( $core ) ? $core : $word;

			$meaningful = '' !== $core && mb_strlen( $core ) > 2 && ! in_array( mb_strtolower( $core ), $stop_words, true );

			// A stop word, or punctuation before the word, ends the run.
			if ( ! $meaningful || 1 === preg_match( '/^[^\p{L}\p{N}]/u', $word ) ) {
				$runs[] = $run;
				$run    = array();
			}

			if ( ! $meaningful ) {
				continue;
			}

			$run[] = $core;

			// Punctuation after the word ends the run too.
			if ( 1 === preg_match( '/[^\p{L}\p{N}]$/u', $word ) ) {
				$runs[] = $run;
				$run    = array();
			}
		}
		$runs[] = $run;

		foreach ( $runs as $run ) {
			if ( count( $run ) >= max( 1, $min_words ) ) {
				$keywords[] = self::cap_keyword( implode( ' ', array_slice( $run, 0, max( 3, $min_words ) ) ) );
				break;
			}
		}

		return array_values( array_unique( array_filter( $keywords, fn( $k ) => '' !== $k ) ) );
	}

	/**
	 * Cap a keyword to the suggestions table's keyword column (varchar(255)),
	 * cutting at a word boundary so the stored keyword is exactly what was
	 * generated and matched, and the anchor text never ends mid-word.
	 *
	 * @param string $keyword Keyword.
	 * @return string
	 */
	private static function cap_keyword( string $keyword ): string {
		if ( mb_strlen( $keyword ) <= self::KEYWORD_MAX_LENGTH ) {
			return $keyword;
		}

		$capped = mb_substr( $keyword, 0, self::KEYWORD_MAX_LENGTH );
		$space  = mb_strrpos( $capped, ' ' );

		if ( false !== $space && $space > 0 ) {
			$capped = mb_substr( $capped, 0, $space );
		}

		return rtrim( $capped );
	}

	/**
	 * Find context around keyword in content
	 *
	 * The occurrence is the one Linker::insert() links when the suggestion is
	 * applied, found by a dry-run insert, so nothing is suggested that apply
	 * refuses (a phrase split by an inline tag or a line break, text already
	 * inside a link, a caption or a shortcode tag).
	 *
	 * @param string $content HTML content
	 * @param string $keyword Keyword to find
	 * @return string|null Context sentence or null
	 */
	private function find_keyword_context( string $content, string $keyword ): ?string {
		if ( '' === trim( $keyword ) ) {
			return null;
		}

		// Cheap rejection first: the joined text holds every occurrence the
		// linker could link, and more (also false on invalid UTF-8).
		if ( 1 !== preg_match( Linker::keyword_pattern( $keyword ), Linker::matchable_text( $content ) ) ) {
			return null;
		}

		$linked = ( new Linker() )->insert( $content, $keyword, self::PROBE_URL );
		if ( null === $linked ) {
			return null;
		}

		$linked   = str_replace( array( self::PROBE_OPEN, self::PROBE_CLOSE ), '', $linked );
		$open_tag = '<a href="' . esc_url( self::PROBE_URL ) . '">';
		$open_at  = strpos( $linked, $open_tag );
		$close_at = false === $open_at ? false : strpos( $linked, '</a>', $open_at + strlen( $open_tag ) );
		if ( false === $open_at || false === $close_at ) {
			return null;
		}

		$inner  = $open_at + strlen( $open_tag );
		$marked = substr( $linked, 0, $open_at ) . self::PROBE_OPEN
			. substr( $linked, $inner, $close_at - $inner ) . self::PROBE_CLOSE
			. substr( $linked, $close_at + strlen( '</a>' ) );

		$text  = Linker::matchable_text( $marked );
		$pos   = strpos( $text, self::PROBE_OPEN );
		$after = strpos( $text, self::PROBE_CLOSE );
		if ( false === $pos || false === $after || $after < $pos ) {
			return null;
		}

		$match = substr( $text, $pos + strlen( self::PROBE_OPEN ), $after - $pos - strlen( self::PROBE_OPEN ) );
		$text  = str_replace( array( self::PROBE_OPEN, self::PROBE_CLOSE ), '', $text );

		// Extract surrounding text (about 150 chars each side)
		$start  = max( 0, $pos - 100 );
		$length = strlen( $match ) + 200;

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
		$title = html_entity_decode( $target->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$title = rtrim( trim( (string) preg_replace( '/\s+/', ' ', $title ) ), " ?!.,;:\u{2026}" );
		if ( strtolower( $keyword ) === strtolower( $title ) ) {
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
	 * @return bool True if the row was written.
	 */
	private function store_suggestion( array $suggestion ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'dil_suggestions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table; no core API available.
		$result = $wpdb->insert(
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

		return false !== $result;
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

		// Only suggestions whose target and source are still published, scanned,
		// not excluded posts: a target drafted, trashed or excluded since would
		// link to a ?p= or __trashed URL, and an excluded source is off limits.
		$scope = $this->suggestion_scope();
		if ( null === $scope ) {
			return array();
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom plugin table (no core API/cache); the scope clause is built from fixed fragments and generated placeholders.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*,
						sp.post_title as source_title,
						p.post_title as target_title
				 FROM %i s
				 JOIN {$wpdb->posts} sp ON s.source_post_id = sp.ID
				 JOIN {$wpdb->posts} p ON s.target_post_id = p.ID
				 {$scope['where']}
				 ORDER BY s.relevance_score DESC
				 LIMIT %d",
				...array_merge( array( $table ), $scope['args'], array( $limit ) )
			),
			ARRAY_A
		);
		// phpcs:enable

		return $results ? $results : array();
	}

	/**
	 * Update suggestion status
	 *
	 * @param int    $suggestion_id Suggestion ID
	 * @param string $status        New status (applied, dismissed)
	 * @return bool False if the write failed or the row is gone. A row that
	 *              already holds the requested status counts as success.
	 */
	public function update_suggestion_status( int $suggestion_id, string $status ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'dil_suggestions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; no core API or cache available.
		$result = $wpdb->update(
			$table,
			array( 'status' => $status ),
			array( 'id' => $suggestion_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false === $result ) {
			return false;
		}

		if ( (int) $result > 0 ) {
			return true;
		}

		/*
		 * 0 changed rows is ambiguous: the row may already hold this status, which
		 * is a success, or it may have been deleted, in which case nothing was
		 * dismissed and saying otherwise would be untrue. The stored status
		 * settles it.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uncached read that disambiguates the write just made.
		$stored = $wpdb->get_var(
			$wpdb->prepare( 'SELECT status FROM %i WHERE id = %d', $table, $suggestion_id )
		);

		return null !== $stored && (string) $stored === $status;
	}

	/**
	 * Number of orphans, counted with exactly the filter the orphan list uses.
	 *
	 * @return int
	 */
	private function count_orphans(): int {
		global $wpdb;

		$scope = $this->report_scope( 's.inbound_count = 0' );
		if ( null === $scope ) {
			return 0;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom plugin table (no core API/cache); the scope clause is built from fixed fragments and generated placeholders.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i s
				 JOIN {$wpdb->posts} p ON s.post_id = p.ID
				 {$scope['where']}",
				...array_merge( array( $wpdb->prefix . 'dil_stats' ), $scope['args'] )
			)
		);
		// phpcs:enable

		return (int) $count;
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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom plugin tables (no core API/cache); the scope clauses are built from fixed fragments and generated placeholders.
		$total_links  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table_links ) );
		$orphan_posts = $this->count_orphans();

		// Posts scanned and the averages cover exactly the posts the orphan
		// count and the reports cover.
		$totals = null;
		$scope  = $this->report_scope( '1 = 1' );
		if ( null !== $scope ) {
			$totals = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT COUNT(*) AS posts, AVG(s.inbound_count) AS avg_in, AVG(s.outbound_count) AS avg_out
					 FROM %i s
					 JOIN {$wpdb->posts} p ON s.post_id = p.ID
					 {$scope['where']}",
					...array_merge( array( $table_stats ), $scope['args'] )
				),
				ARRAY_A
			);
		}
		$totals = is_array( $totals ) ? $totals : array();

		// Pending suggestions, counted with the filter the list uses.
		$pending_suggestions = 0;
		$scope               = $this->suggestion_scope();
		if ( null !== $scope ) {
			$pending_suggestions = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i s
					 JOIN {$wpdb->posts} sp ON s.source_post_id = sp.ID
					 JOIN {$wpdb->posts} p ON s.target_post_id = p.ID
					 {$scope['where']}",
					...array_merge( array( $table_suggestions ), $scope['args'] )
				)
			);
		}
		// phpcs:enable

		$total_posts  = (int) ( $totals['posts'] ?? 0 );
		$avg_inbound  = (float) ( $totals['avg_in'] ?? 0 );
		$avg_outbound = (float) ( $totals['avg_out'] ?? 0 );

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
