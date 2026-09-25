<?php
/**
 * PHPUnit bootstrap. The classes under test are WP-light; the few core helpers
 * they touch are stubbed here. The block parser is the real core class (copied
 * into tests/fixtures) so parse_blocks()/serialize_blocks() behave exactly as
 * in WordPress. Everything else (dbDelta, url_to_postid, the post API) is a
 * recording stub whose result each test sets.
 *
 * @package DragonInternalLinks
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );
defined( 'WEEK_IN_SECONDS' ) || define( 'WEEK_IN_SECONDS', 604800 );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
defined( 'DRAGONINTERNALLINKS_PLUGIN_DIR' ) || define( 'DRAGONINTERNALLINKS_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

require_once __DIR__ . '/../../dragon-broken-links/vendor/autoload.php';
require_once __DIR__ . '/fixtures/wp-block-parser/class-wp-block-parser.php';

// Shared test state: options, posts, permalinks, url_to_postid map, recorded calls.
$GLOBALS['dragoninternallinks_test'] = array(
	'home_url'        => 'https://example.test',
	'options'         => array(),
	'posts'           => array(),
	'permalinks'      => array(),
	'url_to_postid'   => array(),
	'can'             => true,
	'kses_active'     => false,
	'update_post'     => 1,
	'query_posts'     => array(),
	'query_found'     => 0,
	'calls'           => array(),
);

/**
 * Reset the shared test state between tests.
 */
function dragoninternallinks_test_reset(): void {
	$GLOBALS['dragoninternallinks_test'] = array(
		'home_url'        => 'https://example.test',
		'options'         => array(),
		'posts'           => array(),
		'permalinks'      => array(),
		'url_to_postid'   => array(),
		'can'             => true,
		'kses_active'     => false,
		'update_post'     => 1,
		'query_posts'     => array(),
		'query_found'     => 0,
		'calls'           => array(),
		'get_posts'       => array(),
		'terms'           => array(),
		'cron'            => array(),
		'schedule_fails'  => false,
		'settings_errors' => array(),
		'http'            => array(),
		'uploads'         => array(),
		'multisite'       => false,
		'blog_id'         => 1,
		'blogs'           => array(),
		'blog_stack'      => array(),
		'network_active'  => false,
		'is_admin'        => false,
		'doing_cron'      => false,
	);
	$GLOBALS['wpdb']           = new DragonInternalLinks_Test_Wpdb();
	$GLOBALS['shortcode_tags'] = array();
}

/**
 * Record a stub call.
 */
function dragoninternallinks_test_record( string $name, array $args ): void {
	$GLOBALS['dragoninternallinks_test']['calls'][] = array( $name, $args );
}

/**
 * Names of the recorded calls, in order.
 */
function dragoninternallinks_test_call_names(): array {
	return array_column( $GLOBALS['dragoninternallinks_test']['calls'], 0 );
}

/**
 * Recorded calls to one stub.
 */
function dragoninternallinks_test_calls( string $name ): array {
	$out = array();
	foreach ( $GLOBALS['dragoninternallinks_test']['calls'] as $call ) {
		if ( $call[0] === $name ) {
			$out[] = $call[1];
		}
	}
	return $out;
}

/**
 * Thrown by the wp_send_json_* stubs in place of exit.
 */
class DragonInternalLinks_Test_Json_Response extends \RuntimeException {
	public bool $success;
	public $data;

	public function __construct( bool $success, $data ) {
		parent::__construct( $success ? 'success' : 'error' );
		$this->success = $success;
		$this->data    = $data;
	}
}

/**
 * Recording wpdb double. Return values are set per method via $returns.
 */
class DragonInternalLinks_Test_Wpdb {
	public string $prefix  = 'wp_';
	public string $posts   = 'wp_posts';
	public string $postmeta = 'wp_postmeta';
	public string $options = 'wp_options';
	public string $term_relationships = 'wp_term_relationships';
	public string $term_taxonomy      = 'wp_term_taxonomy';
	public array $returns  = array();
	public array $calls    = array();
	public string $base_prefix = 'wp_';

	/**
	 * Table prefix of a site, as core's wpdb::get_blog_prefix() builds it.
	 *
	 * @param int|null $blog_id Site ID, or null for the current site.
	 */
	public function get_blog_prefix( $blog_id = null ) {
		if ( ! is_multisite() ) {
			return $this->base_prefix;
		}
		if ( null === $blog_id ) {
			$blog_id = get_current_blog_id();
		}
		$blog_id = (int) $blog_id;
		return ( 0 === $blog_id || 1 === $blog_id ) ? $this->base_prefix : $this->base_prefix . $blog_id . '_';
	}

	public function __call( string $name, array $args ) {
		$this->calls[] = array( $name, $args );
		if ( array_key_exists( $name, $this->returns ) ) {
			$value = $this->returns[ $name ];
			if ( $value instanceof \Closure ) {
				return $value( ...$args );
			}
			if ( is_array( $value ) && array_key_exists( 'queue', $value ) ) {
				return array_shift( $this->returns[ $name ]['queue'] );
			}
			return $value;
		}
		return 'prepare' === $name ? (string) ( $args[0] ?? '' ) : null;
	}

	public function calls_to( string $name ): array {
		$out = array();
		foreach ( $this->calls as $call ) {
			if ( $call[0] === $name ) {
				$out[] = $call[1];
			}
		}
		return $out;
	}
}

/**
 * WP_Query double: returns the ids and total the test configured.
 */
class WP_Query {
	public array $posts;
	public int $found_posts;

	public function __construct( array $args = array() ) {
		dragoninternallinks_test_record( 'WP_Query', array( $args ) );
		$this->posts       = $GLOBALS['dragoninternallinks_test']['query_posts'];
		$this->found_posts = $GLOBALS['dragoninternallinks_test']['query_found'];
	}
}

/**
 * WP_Post double with the fields the plugin reads.
 */
final class WP_Post {
	public $ID           = 0;
	public $post_status  = 'publish';
	public $post_type    = 'post';
	public $post_title   = '';
	public $post_content = '';
	public $post_date    = '';
	public $post_parent  = 0;
	public $post_name    = '';
}

class WP_Error {
	public $code;
	public $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof \WP_Error;
}

function __( $text, $domain = 'default' ) {
	unset( $domain );
	return $text;
}

function _n( $single, $plural, $number, $domain = 'default' ) {
	unset( $domain );
	return 1 === (int) $number ? $single : $plural;
}

function number_format_i18n( $number, $decimals = 0 ) {
	return number_format( (float) $number, absint( $decimals ) );
}

function esc_html__( $text, $domain = 'default' ) {
	unset( $domain );
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8', false );
}

// Byte-preserving escape so invalid UTF-8 reaches the code under test
// (htmlspecialchars would substitute or drop it first).
/**
 * Mirrors core: returns '' for invalid UTF-8, or the text with the invalid
 * bytes removed when $strip is true.
 */
function wp_check_invalid_utf8( $text, $strip = false ) {
	$text = (string) $text;

	if ( '' === $text || 1 === preg_match( '//u', $text ) ) {
		return $text;
	}

	if ( ! $strip ) {
		return '';
	}

	return (string) preg_replace( '/[\x80-\xFF]/', '', $text );
}

/**
 * Mirrors core closely enough for the cases that matter: WordPress runs
 * wp_check_invalid_utf8() first, which returns an EMPTY STRING for text that is
 * not valid UTF-8. A byte-preserving stub hides every bug where invalid input
 * silently blanks the output.
 */
function esc_html( $text ) {
	$text = (string) $text;

	$text = wp_check_invalid_utf8( $text );

	if ( '' === $text ) {
		return '';
	}

	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8', false );
}

function esc_attr( $text ) {
	return esc_html( $text );
}

function esc_url( $url ) {
	return str_replace( '&', '&#038;', (string) $url );
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}


if ( ! function_exists( 'dragon_test_repair_utf8' ) ) {
	/**
	 * Mirrors wp_check_invalid_utf8( $text, true ) over a whole structure:
	 * invalid byte sequences are stripped rather than causing a failure, which is
	 * what core does before encoding.
	 *
	 * @param mixed $value Value to repair.
	 * @return mixed
	 */
	function dragon_test_repair_utf8( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ is_string( $key ) ? dragon_test_repair_utf8( $key ) : $key ] = dragon_test_repair_utf8( $item );
			}
			return $out;
		}

		if ( ! is_string( $value ) || '' === $value || 1 === preg_match( '//u', $value ) ) {
			return $value;
		}

		return (string) preg_replace( '/[\x80-\xFF]/', '', $value );
	}
}

function wp_json_encode( $data, $options = 0, $depth = 512 ) {
	return json_encode( dragon_test_repair_utf8( $data ), $options, $depth );
}

function home_url( $path = '' ) {
	return $GLOBALS['dragoninternallinks_test']['home_url'] . $path;
}

function add_action( ...$args ) {
	dragoninternallinks_test_record( 'add_action', $args );
	return true;
}

function add_filter( ...$args ) {
	dragoninternallinks_test_record( 'add_filter', $args );
	return true;
}

function apply_filters( $tag, $value, ...$args ) {
	unset( $tag, $args );
	return $value;
}

function do_action( ...$args ) {
	unset( $args );
}

function get_option( $name, $default_value = false ) {
	$options = $GLOBALS['dragoninternallinks_test']['options'];
	return array_key_exists( $name, $options ) ? $options[ $name ] : $default_value;
}

function update_option( $name, $value, $autoload = null ) {
	unset( $autoload );
	// Core returns false when the stored value is UNCHANGED as well as on a
	// failed write, which is why callers verify an option by reading it back.
	if ( array_key_exists( $name, (array) ( $GLOBALS['dragoninternallinks_test']['options'] ?? array() ) )
		&& $GLOBALS['dragoninternallinks_test']['options'][ $name ] === $value ) {
		return false;
	}
	$GLOBALS['dragoninternallinks_test']['options'][ $name ] = $value;
	return true;
}

function add_option( $name, $value = '', $deprecated = '', $autoload = null ) {
	unset( $deprecated, $autoload );
	// Core never overwrites: an option that already exists is left alone.
	if ( array_key_exists( $name, (array) $GLOBALS['dragoninternallinks_test']['options'] ) ) {
		return false;
	}
	$GLOBALS['dragoninternallinks_test']['options'][ $name ] = $value;
	return true;
}

function current_time( $type, $gmt = 0 ) {
	unset( $gmt );
	return 'mysql' === $type ? gmdate( 'Y-m-d H:i:s' ) : time();
}

function absint( $maybeint ) {
	return abs( (int) $maybeint );
}

function check_ajax_referer( ...$args ) {
	unset( $args );
	return 1;
}

function current_user_can( ...$args ) {
	unset( $args );
	return $GLOBALS['dragoninternallinks_test']['can'];
}

function get_post( $post = null ) {
	$id = is_object( $post ) ? (int) $post->ID : (int) $post;
	return $GLOBALS['dragoninternallinks_test']['posts'][ $id ] ?? null;
}

function is_sticky( $post_id = 0 ) {
	return in_array( (int) $post_id, $GLOBALS['dragoninternallinks_test']['sticky'] ?? array(), true );
}

function get_permalink( $post = 0 ) {
	$id = is_object( $post ) ? (int) $post->ID : (int) $post;
	return $GLOBALS['dragoninternallinks_test']['permalinks'][ $id ] ?? false;
}

function get_edit_post_link( $post_id, $context = 'display' ) {
	unset( $context );
	return 'https://example.test/wp-admin/post.php?post=' . (int) $post_id . '&action=edit';
}

function url_to_postid( $url ) {
	dragoninternallinks_test_record( 'url_to_postid', array( $url ) );
	return $GLOBALS['dragoninternallinks_test']['url_to_postid'][ $url ] ?? 0;
}

function attachment_url_to_postid( $url ) {
	dragoninternallinks_test_record( 'attachment_url_to_postid', array( $url ) );
	return 0;
}

function wp_slash( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'wp_slash', $value );
	}
	return is_string( $value ) ? addslashes( $value ) : $value;
}

function wp_update_post( $postarr = array(), $wp_error = false, $fire_after_hooks = true ) {
	unset( $fire_after_hooks );
	dragoninternallinks_test_record( 'wp_update_post', array( $postarr, $wp_error ) );
	return $GLOBALS['dragoninternallinks_test']['update_post'];
}

function has_filter( $hook, $callback = false ) {
	unset( $hook, $callback );
	return $GLOBALS['dragoninternallinks_test']['kses_active'];
}

function kses_remove_filters() {
	dragoninternallinks_test_record( 'kses_remove_filters', array() );
}

function kses_init_filters() {
	dragoninternallinks_test_record( 'kses_init_filters', array() );
}

function wp_send_json_error( $data = null ) {
	throw new DragonInternalLinks_Test_Json_Response( false, $data );
}

function wp_send_json_success( $data = null ) {
	throw new DragonInternalLinks_Test_Json_Response( true, $data );
}

// Block serialization, as in wp-includes/blocks.php.
function serialize_block_attributes( $block_attributes ) {
	$encoded_attributes = wp_json_encode( $block_attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

	return strtr(
		$encoded_attributes,
		array(
			'\\\\' => '\\u005c',
			'--'   => '\\u002d\\u002d',
			'<'    => '\\u003c',
			'>'    => '\\u003e',
			'&'    => '\\u0026',
			'\\"'  => '\\u0022',
		)
	);
}

function strip_core_block_namespace( $block_name = null ) {
	if ( is_string( $block_name ) && str_starts_with( $block_name, 'core/' ) ) {
		return substr( $block_name, 5 );
	}
	return $block_name;
}

function get_comment_delimited_block_content( $block_name, $block_attributes, $block_content ) {
	if ( is_null( $block_name ) ) {
		return $block_content;
	}
	$serialized_block_name = strip_core_block_namespace( $block_name );
	$serialized_attributes = empty( $block_attributes ) ? '' : serialize_block_attributes( $block_attributes ) . ' ';
	if ( empty( $block_content ) ) {
		return sprintf( '<!-- wp:%s %s/-->', $serialized_block_name, $serialized_attributes );
	}
	return sprintf(
		'<!-- wp:%s %s-->%s<!-- /wp:%s -->',
		$serialized_block_name,
		$serialized_attributes,
		$block_content,
		$serialized_block_name
	);
}

function serialize_block( $block ) {
	$block_content = '';
	$index         = 0;
	foreach ( $block['innerContent'] as $chunk ) {
		$block_content .= is_string( $chunk ) ? $chunk : serialize_block( $block['innerBlocks'][ $index++ ] );
	}
	if ( ! is_array( $block['attrs'] ) ) {
		$block['attrs'] = array();
	}
	return get_comment_delimited_block_content( $block['blockName'], $block['attrs'], $block_content );
}

function serialize_blocks( $blocks ) {
	return implode( '', array_map( 'serialize_block', $blocks ) );
}

function parse_blocks( $content ) {
	dragoninternallinks_test_record( 'parse_blocks', array( strlen( (string) $content ) ) );
	$parser = new \WP_Block_Parser();
	return $parser->parse( $content );
}

/**
 * Category term IDs of a post, as core returns them by default.
 */
function wp_get_post_categories( $post_id = 0, $args = array() ) {
	unset( $args );
	return array_map( 'intval', $GLOBALS['dragoninternallinks_test']['terms'][ (int) $post_id ]['category'] ?? array() );
}

function get_posts( $args = null ) {
	dragoninternallinks_test_record( 'get_posts', array( $args ) );
	return $GLOBALS['dragoninternallinks_test']['get_posts'];
}

function wp_strip_all_tags( $text, $remove_breaks = false ) {
	$text = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
	$text = strip_tags( $text );
	if ( $remove_breaks ) {
		$text = (string) preg_replace( '/[\r\n\t ]+/', ' ', $text );
	}
	return trim( $text );
}

/**
 * Mirrors core for the category check the plugin makes: term IDs only.
 */
function has_term( $term = '', $taxonomy = '', $post = null ) {
	$id    = is_object( $post ) ? (int) $post->ID : (int) $post;
	$terms = $GLOBALS['dragoninternallinks_test']['terms'][ $id ][ $taxonomy ] ?? array();
	if ( '' === $term || array() === $term ) {
		return array() !== $terms;
	}
	return array() !== array_intersect( array_map( 'intval', (array) $term ), $terms );
}

function wp_is_post_autosave( $post ) {
	unset( $post );
	return false;
}

function wp_is_post_revision( $post ) {
	unset( $post );
	return false;
}

function delete_option( $name ) {
	unset( $GLOBALS['dragoninternallinks_test']['options'][ $name ] );
	return true;
}

// Cron: a list of events, each array( timestamp, hook, schedule|false ).
function wp_next_scheduled( $hook, $args = array() ) {
	unset( $args );
	$times = array();
	foreach ( $GLOBALS['dragoninternallinks_test']['cron'] as $event ) {
		if ( $event[1] === $hook ) {
			$times[] = $event[0];
		}
	}
	return array() === $times ? false : min( $times );
}

function wp_get_schedule( $hook, $args = array() ) {
	unset( $args );
	$next = wp_next_scheduled( $hook );
	foreach ( $GLOBALS['dragoninternallinks_test']['cron'] as $event ) {
		if ( $event[1] === $hook && $event[0] === $next ) {
			return $event[2];
		}
	}
	return false;
}

function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array(), $wp_error = false ) {
	unset( $args );
	dragoninternallinks_test_record( 'wp_schedule_event', array( $timestamp, $recurrence, $hook ) );
	if ( $GLOBALS['dragoninternallinks_test']['schedule_fails'] ) {
		return $wp_error ? new \WP_Error( 'could_not_set', 'The cron event could not be saved.' ) : false;
	}
	$GLOBALS['dragoninternallinks_test']['cron'][] = array( (int) $timestamp, $hook, $recurrence );
	return true;
}

function wp_clear_scheduled_hook( $hook, $args = array(), $wp_error = false ) {
	unset( $args, $wp_error );
	dragoninternallinks_test_record( 'wp_clear_scheduled_hook', array( $hook ) );
	$before = count( $GLOBALS['dragoninternallinks_test']['cron'] );
	$GLOBALS['dragoninternallinks_test']['cron'] = array_values(
		array_filter( $GLOBALS['dragoninternallinks_test']['cron'], fn( $event ) => $event[1] !== $hook )
	);
	return $before - count( $GLOBALS['dragoninternallinks_test']['cron'] );
}

function wp_unschedule_event( $timestamp, $hook, $args = array(), $wp_error = false ) {
	unset( $args, $wp_error );
	$GLOBALS['dragoninternallinks_test']['cron'] = array_values(
		array_filter( $GLOBALS['dragoninternallinks_test']['cron'], fn( $event ) => ! ( $event[1] === $hook && $event[0] === $timestamp ) )
	);
	return true;
}

function wp_verify_nonce( $nonce, $action = -1 ) {
	unset( $action );
	return 'valid' === $nonce ? 1 : false;
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function wp_unslash( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'wp_unslash', $value );
	}
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function sanitize_text_field( $str ) {
	// Mirrors core's _sanitize_text_fields( $str, false ).
	$filtered = (string) $str;

	if ( '' !== $filtered && 1 !== preg_match( '//u', $filtered ) ) {
		$filtered = (string) preg_replace( '/[\x80-\xFF]/', '', $filtered );
	}

	if ( str_contains( $filtered, '<' ) ) {
		$filtered = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $filtered );
		$filtered = strip_tags( $filtered );
	}

	$filtered = trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', $filtered ) );

	$found = false;
	while ( preg_match( '/%[a-f0-9]{2}/i', $filtered, $match ) ) {
		$filtered = str_replace( $match[0], '', $filtered );
		$found    = true;
	}

	if ( $found ) {
		$filtered = trim( (string) preg_replace( '/ +/', ' ', $filtered ) );
	}

	return $filtered;
}

function add_settings_error( $setting, $code, $message, $type = 'error' ) {
	$GLOBALS['dragoninternallinks_test']['settings_errors'][] = array( $setting, $code, $message, $type );
}

function wp_salt( $scheme = 'auth' ) {
	return 'test-salt-' . $scheme;
}

// HTTP: 'http' holds the canned response (array or WP_Error); each call is recorded.
function wp_safe_remote_post( $url, $args = array() ) {
	dragoninternallinks_test_record( 'wp_safe_remote_post', array( $url, $args ) );
	return $GLOBALS['dragoninternallinks_test']['http'] ?? new \WP_Error( 'http_request_failed', 'No response.' );
}

function wp_remote_retrieve_response_code( $response ) {
	if ( is_wp_error( $response ) || ! isset( $response['response'] ) || ! is_array( $response['response'] ) ) {
		return '';
	}
	return $response['response']['code'];
}

function wp_remote_retrieve_body( $response ) {
	if ( is_wp_error( $response ) || ! isset( $response['body'] ) ) {
		return '';
	}
	return $response['body'];
}

function wp_upload_dir( $time = null, $create_dir = true, $refresh_cache = false ) {
	unset( $time, $create_dir, $refresh_cache );
	return array_merge(
		array(
			'path'    => '',
			'url'     => '',
			'subdir'  => '',
			'basedir' => '',
			'baseurl' => 'https://example.test/wp-content/uploads',
			'error'   => false,
		),
		$GLOBALS['dragoninternallinks_test']['uploads']
	);
}

function wp_date( $format, $timestamp = null, $timezone = null ) {
	unset( $timezone );
	return gmdate( $format, $timestamp ?? time() );
}

function is_admin() {
	return (bool) $GLOBALS['dragoninternallinks_test']['is_admin'];
}

function wp_doing_cron() {
	return (bool) $GLOBALS['dragoninternallinks_test']['doing_cron'];
}

function flush_rewrite_rules( $hard = true ) {
	dragoninternallinks_test_record( 'flush_rewrite_rules', array( $hard ) );
}

function dbDelta( $queries = '', $execute = true ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
	unset( $execute );
	dragoninternallinks_test_record( 'dbDelta', array( $GLOBALS['wpdb']->prefix, $queries ) );
	return array();
}

// Multisite: switch_to_blog() swaps the options, cron and table prefix per site, as core does.
function is_multisite() {
	return (bool) $GLOBALS['dragoninternallinks_test']['multisite'];
}

function get_current_blog_id() {
	return (int) $GLOBALS['dragoninternallinks_test']['blog_id'];
}

function get_sites( $args = array() ) {
	dragoninternallinks_test_record( 'get_sites', array( $args ) );
	$ids = array_keys( $GLOBALS['dragoninternallinks_test']['blogs'] );
	if ( ! in_array( 1, $ids, true ) ) {
		array_unshift( $ids, 1 );
	}
	$number = $args['number'] ?? 100;
	if ( $number ) {
		$ids = array_slice( $ids, 0, (int) $number );
	}
	return 'ids' === ( $args['fields'] ?? '' ) ? $ids : array_map( fn( $id ) => (object) array( 'blog_id' => (string) $id ), $ids );
}

function dragoninternallinks_test_load_blog( int $id ): void {
	$t = &$GLOBALS['dragoninternallinks_test'];

	$t['blogs'][ $t['blog_id'] ] = array(
		'options' => $t['options'],
		'cron'    => $t['cron'],
	);

	$t['blog_id']            = $id;
	$t['options']            = $t['blogs'][ $id ]['options'] ?? array();
	$t['cron']               = $t['blogs'][ $id ]['cron'] ?? array();
	$GLOBALS['wpdb']->prefix  = 1 === $id ? 'wp_' : 'wp_' . $id . '_';
	$GLOBALS['wpdb']->options = $GLOBALS['wpdb']->prefix . 'options';
}

function switch_to_blog( $new_blog_id, $deprecated = null ) {
	unset( $deprecated );
	$GLOBALS['dragoninternallinks_test']['blog_stack'][] = get_current_blog_id();
	dragoninternallinks_test_load_blog( (int) $new_blog_id );
	return true;
}

function restore_current_blog() {
	$stack = &$GLOBALS['dragoninternallinks_test']['blog_stack'];
	if ( array() === $stack ) {
		return false;
	}
	dragoninternallinks_test_load_blog( (int) array_pop( $stack ) );
	return true;
}

function is_plugin_active_for_network( $plugin ) {
	unset( $plugin );
	return is_multisite() && (bool) $GLOBALS['dragoninternallinks_test']['network_active'];
}

dragoninternallinks_test_reset();
