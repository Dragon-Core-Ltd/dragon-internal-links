<?php
/**
 * wp-env integration checks for suggestion relevance (TF-IDF + AI ranking).
 *
 *   wp eval-file wp-content/plugins/dragon-internal-links/tests/integration/relevance.php
 *
 * @package DragonInternalLinks
 */

use DragonInternalLinks\Scanner;
use DragonInternalLinks\Analyzer;
use DragonInternalLinks\AI_Ranker;

function dragoninternallinks_rel_check( string $label, bool $cond ): void {
	if ( $cond ) {
		WP_CLI::log( 'PASS ' . $label );
	} else {
		WP_CLI::warning( 'FAIL ' . $label );
	}
}

// ---- Fixtures: one source, one topically-related target, one unrelated target
// whose title ALSO appears verbatim in the source (the old keyword-overlap trap).
$src = wp_insert_post(
	array(
		'post_title'   => 'Growing tomatoes in raised beds',
		'post_content' => '<p>Our guide to tomato fertiliser and watering covers soil preparation for raised beds. ' .
			'We once mentioned enterprise JavaScript frameworks in passing, but this post is about growing tomatoes: ' .
			'feeding tomato plants, fertiliser schedules, watering cadence and soil health in raised garden beds.</p>',
		'post_status'  => 'publish',
		'post_type'    => 'post',
	)
);
$rel = wp_insert_post(
	array(
		'post_title'   => 'Tomato fertiliser and watering',
		'post_content' => '<p>How to feed tomato plants: fertiliser types, watering schedules and soil nutrition for tomatoes in beds.</p>',
		'post_status'  => 'publish',
		'post_type'    => 'post',
	)
);
$unrel = wp_insert_post(
	array(
		'post_title'   => 'Enterprise JavaScript frameworks',
		'post_content' => '<p>Choosing between React, Angular and Vue for large enterprise applications. TypeScript, build tooling and CI pipelines.</p>',
		'post_status'  => 'publish',
		'post_type'    => 'post',
	)
);
dragoninternallinks_rel_check( 'fixtures created', $src > 0 && $rel > 0 && $unrel > 0 );

$analyzer = new Analyzer( new Scanner() );

// ---- TF-IDF path (no AI key) ----
delete_option( 'dragoninternallinks_ai_enabled' );
$suggestions = $analyzer->generate_suggestions_for_post( $src );
$by_target   = array();
foreach ( $suggestions as $s ) {
	$by_target[ (int) $s['target_post_id'] ] = (float) $s['relevance'];
}
dragoninternallinks_rel_check( 'both candidates suggested (anchors exist in text)', isset( $by_target[ $rel ], $by_target[ $unrel ] ) );
dragoninternallinks_rel_check(
	'related target outranks unrelated despite both titles appearing',
	isset( $by_target[ $rel ], $by_target[ $unrel ] ) && $by_target[ $rel ] > $by_target[ $unrel ]
);
dragoninternallinks_rel_check( 'scores on 0-100 scale', empty( $by_target ) || max( $by_target ) <= 100 );
WP_CLI::log( 'tfidf scores: ' . wp_json_encode( $by_target ) );

// ---- AI path (mocked provider) ----
update_option( 'dragoninternallinks_ai_enabled', true );
update_option( 'dragoninternallinks_ai_provider', 'openai' );
update_option( 'dragoninternallinks_ai_api_key', AI_Ranker::encrypt_key( 'sk-test-not-real' ) );
dragoninternallinks_rel_check( 'key round-trips through encryption', 'sk-test-not-real' === AI_Ranker::api_key() );
dragoninternallinks_rel_check( 'ai enabled', AI_Ranker::enabled() );

$GLOBALS['dragoninternallinks_ai_called'] = 0;
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		if ( false === strpos( $url, 'api.openai.com' ) ) {
			return $pre;
		}
		++$GLOBALS['dragoninternallinks_ai_called'];
		// Score every candidate: give ALL of them 7 except one 93 so the
		// override is observable regardless of candidate order.
		$body = json_decode( $args['body'], true );
		preg_match_all( '/^(\d+)\./m', $body['messages'][0]['content'], $m );
		$scores = array();
		foreach ( $m[1] as $i => $num ) {
			$scores[ $num ] = ( 0 === $i ) ? 93 : 7;
		}
		$payload = array(
			'choices' => array(
				array( 'message' => array( 'content' => wp_json_encode( $scores ) ) ),
			),
		);
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $payload ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
		);
	},
	10,
	3
);

$suggestions2 = $analyzer->generate_suggestions_for_post( $src );
$scores2      = array_map( fn( $s ) => (float) $s['relevance'], $suggestions2 );
dragoninternallinks_rel_check( 'AI endpoint was called once', 1 === $GLOBALS['dragoninternallinks_ai_called'] );
dragoninternallinks_rel_check( 'AI scores override (93 present)', in_array( 93.0, $scores2, true ) );
dragoninternallinks_rel_check( 'AI scores override (7 present)', in_array( 7.0, $scores2, true ) );
WP_CLI::log( 'ai scores: ' . wp_json_encode( $scores2 ) );

// ---- AI failure fails open ----
remove_all_filters( 'pre_http_request' );
add_filter( 'pre_http_request', fn() => new WP_Error( 'http_request_failed', 'refused' ), 10, 0 );
$suggestions3 = $analyzer->generate_suggestions_for_post( $src );
dragoninternallinks_rel_check( 'provider failure falls back to TF-IDF ordering', ! empty( $suggestions3 ) && (float) $suggestions3[0]['relevance'] <= 100 );
remove_all_filters( 'pre_http_request' );

// Cleanup.
delete_option( 'dragoninternallinks_ai_enabled' );
delete_option( 'dragoninternallinks_ai_api_key' );
wp_delete_post( $src, true );
wp_delete_post( $rel, true );
wp_delete_post( $unrel, true );

WP_CLI::success( 'Relevance checks done' );
