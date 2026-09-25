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
	 * Default model per provider: the small current model of each family.
	 */
	public const DEFAULT_MODELS = array(
		'openai'    => 'gpt-6-luna',
		'anthropic' => 'claude-haiku-4-5-20251001',
		'google'    => 'gemini-3.5-flash-lite',
	);

	/**
	 * Model-name prefixes of families the providers have retired.
	 */
	private const RETIRED_PREFIXES = array(
		'claude-3-',
		'claude-3.',
		'claude-2',
		'claude-instant',
		'gemini-1.',
		'gemini-pro',
		'gemini-2.0-',
		'gpt-3.5',
		'gpt-4-',
		'gpt-4.5',
		'o1-preview',
		'o1-mini',
	);

	/**
	 * Option holding the last failed request, cleared by the next success.
	 */
	public const LAST_ERROR_OPTION = 'dragoninternallinks_ai_last_error';

	/**
	 * Option holding a one-time notice after a retired model was replaced.
	 */
	public const MODEL_CHANGED_OPTION = 'dragoninternallinks_ai_model_changed';

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
		$parsed = preg_match( '/\{[^{}]*\}/s', $raw, $m ) ? json_decode( $m[0], true ) : null;
		if ( ! is_array( $parsed ) || array() === $parsed ) {
			self::record_failure( $provider, $model, 200, '', 'unreadable' );
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
		if ( count( $scores ) < (int) ceil( count( $candidates ) / 2 ) ) {
			self::record_failure( $provider, $model, 200, '', 'unreadable' );
			return null;
		}

		if ( false !== get_option( self::LAST_ERROR_OPTION, false ) ) {
			delete_option( self::LAST_ERROR_OPTION );
		}

		return $scores;
	}

	/**
	 * Whether a model belongs to a family its provider has retired.
	 *
	 * @param string $model Model name.
	 * @return bool
	 */
	public static function is_retired_model( string $model ): bool {
		$model = strtolower( trim( $model ) );

		foreach ( self::RETIRED_PREFIXES as $prefix ) {
			if ( str_starts_with( $model, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Move a saved retired model to the provider default (a blank Model
	 * setting), verified by re-read, and leave a one-time notice saying so.
	 */
	public static function migrate_retired_model(): void {
		$saved = trim( (string) get_option( 'dragoninternallinks_ai_model', '' ) );
		if ( '' === $saved || ! self::is_retired_model( $saved ) ) {
			return;
		}

		update_option( 'dragoninternallinks_ai_model', '' );
		if ( '' !== get_option( 'dragoninternallinks_ai_model', '' ) ) {
			return;
		}

		$provider = self::provider();
		update_option(
			self::MODEL_CHANGED_OPTION,
			array(
				'from'     => $saved,
				'to'       => self::DEFAULT_MODELS[ $provider ],
				'provider' => $provider,
			),
			false
		);
	}

	/**
	 * The last failed request, or null when the last request succeeded.
	 *
	 * @return array{time:int,provider:string,model:string,code:int,message:string,reason:string}|null
	 */
	public static function last_error(): ?array {
		$error = get_option( self::LAST_ERROR_OPTION, null );
		return is_array( $error ) ? $error : null;
	}

	/**
	 * Store why a request fell back to the built-in scoring, for the Settings
	 * screen. The API key is masked out of the provider's message.
	 *
	 * @param string $provider Provider slug.
	 * @param string $model    Model name.
	 * @param int    $code     HTTP status, 0 when no response arrived.
	 * @param string $message  Provider or transport message.
	 * @param string $reason   'http', 'transport' or 'unreadable'.
	 */
	private static function record_failure( string $provider, string $model, int $code, string $message, string $reason ): void {
		$key = self::api_key();
		if ( '' !== $key ) {
			$message = str_replace( $key, '***', $message );
		}

		$message = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $message ) ) );

		update_option(
			self::LAST_ERROR_OPTION,
			array(
				'time'     => time(),
				'provider' => $provider,
				'model'    => $model,
				'code'     => $code,
				'message'  => mb_substr( $message, 0, 300 ),
				'reason'   => $reason,
			),
			false
		);
	}

	/**
	 * Whether an OpenAI model is a reasoning model (o-series, GPT-5 and
	 * later), which rejects temperature and max_tokens.
	 *
	 * @param string $model Model name.
	 * @return bool
	 */
	public static function is_openai_reasoning_model( string $model ): bool {
		$model = strtolower( $model );

		if ( 1 === preg_match( '/^o\d/', $model ) ) {
			return true;
		}

		return 1 === preg_match( '/^gpt-(\d+)/', $model, $m ) && (int) $m[1] >= 5;
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
	 * Accepted model-name prefixes per provider, so a stored model can only ever
	 * route the API key to a model in the selected provider's own family.
	 */
	private const MODEL_PREFIXES = array(
		'openai'    => array( 'gpt-', 'o1', 'o3', 'o4', 'chatgpt-' ),
		'anthropic' => array( 'claude-' ),
		'google'    => array( 'gemini-', 'gemma-' ),
	);

	/**
	 * Model from settings, falling back to the provider default.
	 *
	 * The stored value is validated against a character allowlist (which also
	 * protects the Google model path interpolation) and the provider's model
	 * family, so a crafted option can't route the key to an unexpected model.
	 *
	 * @return string
	 */
	public static function model(): string {
		$provider = self::provider();
		$default  = self::DEFAULT_MODELS[ $provider ] ?? self::DEFAULT_MODELS['openai'];
		$model    = trim( (string) get_option( 'dragoninternallinks_ai_model', '' ) );

		if ( '' === $model || ! self::is_allowed_model( $provider, $model ) ) {
			return $default;
		}

		return $model;
	}

	/**
	 * Whether a model name is acceptable for a provider.
	 *
	 * @param string $provider Provider slug.
	 * @param string $model    Model name.
	 * @return bool
	 */
	private static function is_allowed_model( string $provider, string $model ): bool {
		if ( ! preg_match( '/^[a-zA-Z0-9.\-:]+$/', $model ) ) {
			return false;
		}

		foreach ( self::MODEL_PREFIXES[ $provider ] ?? array() as $prefix ) {
			if ( str_starts_with( strtolower( $model ), $prefix ) ) {
				return true;
			}
		}

		return false;
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
		$decrypted = Crypto::decrypt( $stored );
		return null !== $decrypted ? $decrypted : '';
	}

	/**
	 * Encrypt an API key for storage.
	 *
	 * @param string $plain Plain key.
	 * @return string Prefixed base64 of the authenticated ciphertext.
	 */
	public static function encrypt_key( string $plain ): string {
		return Crypto::encrypt( $plain );
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
			$body                             = array(
				'model'                 => $model,
				'messages'              => array(
					array(
						'role'    => 'user',
						'content' => $prompt,
					),
				),
				'max_completion_tokens' => 500,
			);

			if ( self::is_openai_reasoning_model( $model ) ) {
				// Reasoning tokens count against the cap, so leave room for them.
				$body['max_completion_tokens'] = 4000;
				if ( str_starts_with( strtolower( $model ), 'gpt-6' ) ) {
					$body['reasoning_effort'] = 'none';
				}
			} else {
				$body['temperature'] = 0;
			}

			$args['body'] = wp_json_encode( $body );
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
		if ( is_wp_error( $response ) ) {
			self::record_failure( $provider, $model, 0, $response->get_error_message(), 'transport' );
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$message = is_array( $body ) ? ( $body['error']['message'] ?? ( $body[0]['error']['message'] ?? '' ) ) : '';
			self::record_failure( $provider, $model, $code, is_string( $message ) ? $message : '', 'http' );
			return null;
		}

		if ( ! is_array( $body ) ) {
			self::record_failure( $provider, $model, $code, '', 'unreadable' );
			return null;
		}

		if ( 'openai' === $provider ) {
			$text = $body['choices'][0]['message']['content'] ?? null;
		} elseif ( 'anthropic' === $provider ) {
			$text = $body['content'][0]['text'] ?? null;
		} else {
			$text = $body['candidates'][0]['content']['parts'][0]['text'] ?? null;
		}

		if ( ! is_string( $text ) ) {
			self::record_failure( $provider, $model, $code, '', 'unreadable' );
			return null;
		}

		return $text;
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
