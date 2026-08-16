<?php
/**
 * Optional AI re-ranking of link suggestions (bring-your-own API key).
 *
 * @package DragonInternalLinks
 */

namespace DragonInternalLinks;

defined( 'ABSPATH' ) || exit;

/**
 * Re-scores candidate link targets with the site owner's own OpenAI,
 * Anthropic, or Google API key. Fails open: any error, timeout, or
 * unparseable response leaves the TF-IDF ordering untouched. Only numeric
 * scores are ever read from the model output — no model text reaches the
 * page, so a prompt-injected response has nothing to inject into.
 */
final class AI_Ranker {

	/**
	 * Fixed provider endpoints — never derived from user input.
	 */
	private const ENDPOINTS = array(
		'openai'    => 'https://api.openai.com/v1/chat/completions',
		'anthropic' => 'https://api.anthropic.com/v1/messages',
		'google'    => 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
	);

	/**
	 * Default model per provider.
	 */
	public const DEFAULT_MODELS = array(
		'openai'    => 'gpt-4o-mini',
		'anthropic' => 'claude-3-5-haiku-20241022',
		'google'    => 'gemini-2.0-flash',
	);

	/**
	 * Whether AI ranking is configured and enabled.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return (bool) get_option( 'dragoninternallinks_ai_enabled', false ) && '' !== self::api_key();
	}

	/**
	 * Re-rank candidates. Returns candidate-key => 0-100 score, or null when
	 * anything fails (caller keeps the lexical ordering).
	 *
	 * @param array $source     {title: string, text: string} of the source post.
	 * @param array $candidates key => {title: string, excerpt: string, keyword: string}.
	 * @return array<int|string, int>|null
	 */
	public static function rank( array $source, array $candidates ): ?array {
		if ( array() === $candidates ) {
			return null;
		}

		$provider = self::provider();
		$model    = self::model();
		$key      = self::api_key();
		if ( '' === $key || ! isset( self::ENDPOINTS[ $provider ] ) ) {
			return null;
		}

		$lines = array();
		$index = array();
		$n     = 0;
		foreach ( $candidates as $ckey => $c ) {
			++$n;
			$index[ $n ] = $ckey;
			$lines[]     = sprintf(
				'%d. title: %s | excerpt: %s | proposed anchor: %s',
				$n,
				self::clip( (string) ( $c['title'] ?? '' ), 120 ),
				self::clip( (string) ( $c['excerpt'] ?? '' ), 240 ),
				self::clip( (string) ( $c['keyword'] ?? '' ), 80 )
			);
		}

		$prompt = "You score internal-link candidates for a blog post.\n"
			. 'SOURCE POST TITLE: ' . self::clip( (string) ( $source['title'] ?? '' ), 150 ) . "\n"
			. 'SOURCE POST TEXT: ' . self::clip( (string) ( $source['text'] ?? '' ), 1500 ) . "\n\n"
			. "CANDIDATE TARGET PAGES:\n" . implode( "\n", $lines ) . "\n\n"
			. 'For each candidate, rate 0-100 how editorially valuable a link from the source post to that page would be for a reader '
			. '(topical relevance, reader intent, natural fit of the proposed anchor). '
			. 'Respond with ONLY a JSON object mapping candidate number to integer score, like {"1":85,"2":10}. No other text.';

		$raw = self::request( $provider, $model, $key, $prompt );
		if ( null === $raw ) {
			return null;
		}

		// Accept a bare JSON object, or one embedded in stray prose/fences.
		if ( ! preg_match( '/\{[^{}]*\}/s', $raw, $m ) ) {
			return null;
		}
		$parsed = json_decode( $m[0], true );
		if ( ! is_array( $parsed ) || array() === $parsed ) {
			return null;
		}

		$scores = array();
		foreach ( $parsed as $num => $score ) {
			$num = (int) $num;
			if ( isset( $index[ $num ] ) && is_numeric( $score ) ) {
				$scores[ $index[ $num ] ] = max( 0, min( 100, (int) $score ) );
			}
		}

		// A response that scored almost nothing is not trustworthy.
		return count( $scores ) >= (int) ceil( count( $candidates ) / 2 ) ? $scores : null;
	}

	/**
	 * Provider slug from settings, constrained to known providers.
	 *
	 * @return string
	 */
	public static function provider(): string {
		$provider = (string) get_option( 'dragoninternallinks_ai_provider', 'openai' );
		return isset( self::ENDPOINTS[ $provider ] ) ? $provider : 'openai';
	}

	/**
	 * Model from settings, falling back to the provider default.
	 *
	 * @return string
	 */
	public static function model(): string {
		$model = trim( (string) get_option( 'dragoninternallinks_ai_model', '' ) );
		return '' !== $model ? $model : ( self::DEFAULT_MODELS[ self::provider() ] ?? 'gpt-4o-mini' );
	}

	/**
	 * Decrypted API key ('' when unset or undecryptable).
	 *
	 * @return string
	 */
	public static function api_key(): string {
		$stored = (string) get_option( 'dragoninternallinks_ai_api_key', '' );
		if ( '' === $stored ) {
			return '';
		}
		$data = base64_decode( $stored, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding the AES-256-CBC encrypted API key from storage.
		if ( false === $data || strlen( $data ) < 17 ) {
			return '';
		}
		$key       = hash( 'sha256', wp_salt( 'auth' ), true );
		$decrypted = openssl_decrypt( substr( $data, 16 ), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, substr( $data, 0, 16 ) );
		return false !== $decrypted ? $decrypted : '';
	}

	/**
	 * Encrypt an API key for storage.
	 *
	 * @param string $plain Plain key.
	 * @return string base64(iv + ciphertext).
	 */
	public static function encrypt_key( string $plain ): string {
		$key = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv  = openssl_random_pseudo_bytes( 16 );
		$enc = openssl_encrypt( $plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		return base64_encode( $iv . $enc ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding an AES-256-CBC encrypted API key for storage.
	}

	/**
	 * One completion request. Returns the model's text or null on any failure.
	 *
	 * @param string $provider Provider slug (validated by caller).
	 * @param string $model    Model name.
	 * @param string $key      API key.
	 * @param string $prompt   User prompt.
	 * @return string|null
	 */
	private static function request( string $provider, string $model, string $key, string $prompt ): ?string {
		$args = array(
			'timeout' => 25,
			'headers' => array( 'Content-Type' => 'application/json' ),
		);

		if ( 'openai' === $provider ) {
			$url                              = self::ENDPOINTS['openai'];
			$args['headers']['Authorization'] = 'Bearer ' . $key;
			$args['body']                     = wp_json_encode(
				array(
					'model'       => $model,
					'messages'    => array(
						array(
							'role'    => 'user',
							'content' => $prompt,
						),
					),
					'temperature' => 0,
					'max_tokens'  => 500,
				)
			);
		} elseif ( 'anthropic' === $provider ) {
			$url                                  = self::ENDPOINTS['anthropic'];
			$args['headers']['x-api-key']         = $key;
			$args['headers']['anthropic-version'] = '2023-06-01';
			$args['body']                         = wp_json_encode(
				array(
					'model'      => $model,
					'max_tokens' => 500,
					'messages'   => array(
						array(
							'role'    => 'user',
							'content' => $prompt,
						),
					),
				)
			);
		} else {
			// Model name is embedded in the Google URL path — constrain it.
			if ( ! preg_match( '/^[a-zA-Z0-9.\-]+$/', $model ) ) {
				return null;
			}
			$url                               = sprintf( self::ENDPOINTS['google'], rawurlencode( $model ) );
			$args['headers']['x-goog-api-key'] = $key;
			$args['body']                      = wp_json_encode(
				array(
					'contents'         => array( array( 'parts' => array( array( 'text' => $prompt ) ) ) ),
					'generationConfig' => array(
						'temperature'     => 0,
						'maxOutputTokens' => 500,
					),
				)
			);
		}

		$response = wp_safe_remote_post( $url, $args );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return null;
		}

		if ( 'openai' === $provider ) {
			$text = $body['choices'][0]['message']['content'] ?? null;
		} elseif ( 'anthropic' === $provider ) {
			$text = $body['content'][0]['text'] ?? null;
		} else {
			$text = $body['candidates'][0]['content']['parts'][0]['text'] ?? null;
		}

		return is_string( $text ) ? $text : null;
	}

	/**
	 * Clip text to a length without splitting words awkwardly mid-multibyte.
	 *
	 * @param string $text Text.
	 * @param int    $max  Max characters.
	 * @return string
	 */
	private static function clip( string $text, int $max ): string {
		$text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );
		return mb_strlen( $text ) > $max ? mb_substr( $text, 0, $max ) . '…' : $text;
	}
}
