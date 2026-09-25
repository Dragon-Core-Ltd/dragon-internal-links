<?php
/**
 * TF-IDF cosine relevance between a source post and candidate link targets.
 *
 * @package DragonInternalLinks
 */

namespace DragonInternalLinks;

defined( 'ABSPATH' ) || exit;

/**
 * Pure lexical relevance scoring — no WordPress dependencies, unit-testable.
 * Replaces guesswork bonuses with document similarity: a target is relevant
 * to a source when the words that are RARE across the candidate pool are
 * shared between the two, not merely when its title happens to appear once.
 */
final class Relevance {

	/**
	 * Stop words excluded from vectors.
	 */
	private const STOP_WORDS = array(
		'the',
		'a',
		'an',
		'and',
		'or',
		'but',
		'in',
		'on',
		'at',
		'to',
		'for',
		'of',
		'with',
		'by',
		'is',
		'are',
		'was',
		'were',
		'be',
		'been',
		'being',
		'have',
		'has',
		'had',
		'do',
		'does',
		'did',
		'will',
		'would',
		'could',
		'should',
		'may',
		'might',
		'must',
		'shall',
		'can',
		'this',
		'that',
		'these',
		'those',
		'i',
		'you',
		'he',
		'she',
		'it',
		'we',
		'they',
		'what',
		'which',
		'who',
		'when',
		'where',
		'why',
		'how',
		'all',
		'each',
		'every',
		'both',
		'few',
		'more',
		'most',
		'other',
		'some',
		'such',
		'no',
		'nor',
		'not',
		'only',
		'own',
		'same',
		'so',
		'than',
		'too',
		'very',
		'just',
		'also',
		'your',
		'our',
		'their',
		'its',
		'from',
		'as',
		'if',
		'about',
		'into',
		'over',
		'after',
		'before',
		'up',
		'down',
		'out',
		'off',
	);

	/**
	 * Lowercased content words (length > 2, stop words removed).
	 *
	 * @param string $text Raw text (may contain markup — caller strips).
	 * @return string[] Tokens.
	 */
	public static function tokenize( string $text ): array {
		$text  = strtolower( $text );
		$text  = (string) preg_replace( '/[^\p{L}\p{N}\s]/u', ' ', $text );
		$words = preg_split( '/\s+/', trim( $text ) );
		if ( false === $words ) {
			$words = array();
		}
		$tokens = array();
		foreach ( $words as $word ) {
			if ( strlen( $word ) > 2 && ! in_array( $word, self::STOP_WORDS, true ) ) {
				$tokens[] = $word;
			}
		}
		return $tokens;
	}

	/**
	 * Whether a lowercased word is a stop word.
	 *
	 * @param string $word Lowercased word.
	 * @return bool
	 */
	public static function is_stop_word( string $word ): bool {
		return in_array( $word, self::STOP_WORDS, true );
	}

	/**
	 * Number of documents each term appears in.
	 *
	 * @param array<int|string, string[]> $docs Token lists from tokenize().
	 * @return array<string, int>
	 */
	public static function document_frequencies( array $docs ): array {
		$df = array();
		foreach ( $docs as $tokens ) {
			foreach ( array_unique( $tokens ) as $term ) {
				$df[ $term ] = ( $df[ $term ] ?? 0 ) + 1;
			}
		}
		return $df;
	}

	/**
	 * A document's terms ordered by TF-IDF weight, highest first.
	 *
	 * @param string[]           $tokens Document tokens from tokenize().
	 * @param array<string, int> $df     Document frequencies across the pool.
	 * @param int                $count  Number of documents in the pool.
	 * @param int                $limit  Most terms to return.
	 * @return array<string, float> Term => weight.
	 */
	public static function top_terms( array $tokens, array $df, int $count, int $limit ): array {
		$total = count( $tokens );
		if ( 0 === $total || $limit <= 0 ) {
			return array();
		}

		$weights = array();
		foreach ( array_count_values( $tokens ) as $term => $freq ) {
			$term             = (string) $term;
			$idf              = log( ( 1 + $count ) / ( 1 + ( $df[ $term ] ?? 0 ) ) ) + 1;
			$weights[ $term ] = ( $freq / $total ) * $idf;
		}

		// Ties keep a stable, alphabetical order.
		uksort(
			$weights,
			static function ( $a, $b ) use ( $weights ): int {
				$order = $weights[ $b ] <=> $weights[ $a ];
				return 0 !== $order ? $order : strcmp( (string) $a, (string) $b );
			}
		);

		return array_slice( $weights, 0, $limit, true );
	}

	/**
	 * Cosine similarity between the source document and every target
	 * document, using TF-IDF weights with IDF computed across the whole
	 * pool (source + targets).
	 *
	 * @param string   $source_text  Source document text.
	 * @param string[] $target_texts Target documents, keyed as given.
	 * @return array<int|string, float> Same keys as $target_texts, values 0..1.
	 */
	public static function batch_scores( string $source_text, array $target_texts ): array {
		$docs = array( '__source__' => self::tokenize( $source_text ) );
		foreach ( $target_texts as $key => $text ) {
			$docs[ $key ] = self::tokenize( (string) $text );
		}

		// Document frequency per term.
		$df    = self::document_frequencies( $docs );
		$count = count( $docs );

		// TF-IDF vector per document.
		$vectors = array();
		foreach ( $docs as $key => $tokens ) {
			$total = count( $tokens );
			if ( 0 === $total ) {
				$vectors[ $key ] = array();
				continue;
			}
			$tf = array_count_values( $tokens );
			$v  = array();
			foreach ( $tf as $term => $freq ) {
				// Smoothed IDF keeps pool-wide terms low without zeroing them.
				$idf        = log( ( 1 + $count ) / ( 1 + $df[ $term ] ) ) + 1;
				$v[ $term ] = ( $freq / $total ) * $idf;
			}
			$vectors[ $key ] = $v;
		}

		$source = $vectors['__source__'];
		$scores = array();
		foreach ( $target_texts as $key => $unused ) {
			$scores[ $key ] = self::cosine( $source, $vectors[ $key ] );
		}
		return $scores;
	}

	/**
	 * Cosine similarity of two sparse vectors.
	 *
	 * @param array<string, float> $a Vector.
	 * @param array<string, float> $b Vector.
	 * @return float 0..1.
	 */
	public static function cosine( array $a, array $b ): float {
		if ( array() === $a || array() === $b ) {
			return 0.0;
		}
		// Iterate the smaller vector.
		if ( count( $b ) < count( $a ) ) {
			list( $a, $b ) = array( $b, $a );
		}
		$dot = 0.0;
		foreach ( $a as $term => $weight ) {
			if ( isset( $b[ $term ] ) ) {
				$dot += $weight * $b[ $term ];
			}
		}
		if ( 0.0 === $dot ) {
			return 0.0;
		}
		$norm = sqrt( array_sum( array_map( fn( $w ) => $w * $w, $a ) ) )
			* sqrt( array_sum( array_map( fn( $w ) => $w * $w, $b ) ) );

		return $norm > 0 ? min( 1.0, $dot / $norm ) : 0.0;
	}
}
