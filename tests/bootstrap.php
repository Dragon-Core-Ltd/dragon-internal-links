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
	);
	$GLOBALS['wpdb'] = new DragonInternalLinks_Test_Wpdb();
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
	public array $returns  = array();
	public array $calls    = array();

	public function __call( string $name, array $args ) {
		$this->calls[] = array( $name, $args );
		if ( array_key_exists( $name, $this->returns ) ) {
			$value = $this->returns[ $name ];
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
		unset( $args );
		$this->posts       = $GLOBALS['dragoninternallinks_test']['query_posts'];
		$this->found_posts = $GLOBALS['dragoninternallinks_test']['query_found'];
	}
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

function esc_html__( $text, $domain = 'default' ) {
	unset( $domain );
	return $text;
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

	return str_replace( array( '&', '<', '>', '"', "'" ), array( '&amp;', '&lt;', '&gt;', '&quot;', '&#039;' ), $text );
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
	unset( $args );
	return true;
}

function add_filter( ...$args ) {
	unset( $args );
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
	$parser = new \WP_Block_Parser();
	return $parser->parse( $content );
}

dragoninternallinks_test_reset();
