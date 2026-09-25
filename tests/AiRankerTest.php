<?php
/**
 * AI ranker tests: current default models, retired-model migration, request
 * bodies per model family, and the recorded fallback reason.
 *
 * @package DragonInternalLinks
 */

namespace DragonInternalLinks\Tests;

use DragonInternalLinks\AI_Ranker;
use DragonInternalLinks\Crypto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-crypto.php';
require_once __DIR__ . '/../includes/class-ai-ranker.php';

final class AiRankerTest extends TestCase {

	protected function setUp(): void {
		dragoninternallinks_test_reset();
		$this->configure( 'openai', '' );
	}

	private function configure( string $provider, string $model ): void {
		$options = &$GLOBALS['dragoninternallinks_test']['options'];

		$options['dragoninternallinks_ai_enabled']  = true;
		$options['dragoninternallinks_ai_provider'] = $provider;
		$options['dragoninternallinks_ai_model']    = $model;
		$options['dragoninternallinks_ai_api_key']  = Crypto::encrypt( 'sk-secret-key-123' );
	}

	private function respond( int $code, string $body ): void {
		$GLOBALS['dragoninternallinks_test']['http'] = array(
			'response' => array( 'code' => $code ),
			'body'     => $body,
		);
	}

	private function rank(): ?array {
		return AI_Ranker::rank(
			array(
				'title' => 'Source',
				'text'  => 'Text',
			),
			array(
				7 => array(
					'title'   => 'Target',
					'excerpt' => 'Excerpt',
					'keyword' => 'target',
				),
			)
		);
	}

	private function sent_body(): array {
		$calls = dragoninternallinks_test_calls( 'wp_safe_remote_post' );
		$this->assertNotEmpty( $calls );
		return json_decode( $calls[ count( $calls ) - 1 ][1]['body'], true );
	}

	public function test_default_models_are_current(): void {
		$this->assertSame( 'claude-haiku-4-5-20251001', AI_Ranker::DEFAULT_MODELS['anthropic'] );
		$this->assertSame( 'gpt-6-luna', AI_Ranker::DEFAULT_MODELS['openai'] );
		$this->assertSame( 'gemini-3.5-flash-lite', AI_Ranker::DEFAULT_MODELS['google'] );

		foreach ( AI_Ranker::DEFAULT_MODELS as $model ) {
			$this->assertFalse( AI_Ranker::is_retired_model( $model ) );
		}
	}

	/**
	 * @return iterable<string,array{0:string,1:bool}>
	 */
	public static function models(): iterable {
		yield 'old anthropic default' => array( 'claude-3-5-haiku-20241022', true );
		yield 'claude 3 opus' => array( 'claude-3-opus-20240229', true );
		yield 'claude 2' => array( 'claude-2.1', true );
		yield 'gemini 1.5' => array( 'gemini-1.5-flash', true );
		yield 'gemini 2.0' => array( 'gemini-2.0-flash', true );
		yield 'gpt 3.5' => array( 'gpt-3.5-turbo', true );
		yield 'current haiku' => array( 'claude-haiku-4-5-20251001', false );
		yield 'current sonnet' => array( 'claude-sonnet-5', false );
		yield 'current opus' => array( 'claude-opus-5-5', false );
		yield 'gemini 3.8' => array( 'gemini-3.8-flash', false );
	}

	#[DataProvider( 'models' )]
	public function test_retired_models( string $model, bool $retired ): void {
		$this->assertSame( $retired, AI_Ranker::is_retired_model( $model ) );
	}

	public function test_saved_retired_model_moves_to_the_default_once_with_a_notice(): void {
		$this->configure( 'anthropic', 'claude-3-5-haiku-20241022' );

		AI_Ranker::migrate_retired_model();

		$options = $GLOBALS['dragoninternallinks_test']['options'];
		$this->assertSame( '', $options['dragoninternallinks_ai_model'] );
		$this->assertSame( 'claude-haiku-4-5-20251001', AI_Ranker::model() );
		$this->assertSame(
			array(
				'from'     => 'claude-3-5-haiku-20241022',
				'to'       => 'claude-haiku-4-5-20251001',
				'provider' => 'anthropic',
			),
			$options['dragoninternallinks_ai_model_changed']
		);

		unset( $GLOBALS['dragoninternallinks_test']['options']['dragoninternallinks_ai_model_changed'] );
		AI_Ranker::migrate_retired_model();
		$this->assertArrayNotHasKey( 'dragoninternallinks_ai_model_changed', $GLOBALS['dragoninternallinks_test']['options'] );
	}

	public function test_current_saved_model_is_left_alone(): void {
		$this->configure( 'anthropic', 'claude-sonnet-5' );

		AI_Ranker::migrate_retired_model();

		$this->assertSame( 'claude-sonnet-5', $GLOBALS['dragoninternallinks_test']['options']['dragoninternallinks_ai_model'] );
		$this->assertArrayNotHasKey( 'dragoninternallinks_ai_model_changed', $GLOBALS['dragoninternallinks_test']['options'] );
	}

	public function test_openai_reasoning_model_gets_max_completion_tokens_and_no_temperature(): void {
		foreach ( array( 'o3-mini', 'o4-mini', 'o1', 'gpt-5-mini', 'gpt-6-luna' ) as $model ) {
			$this->configure( 'openai', $model );
			$this->respond( 200, '{"choices":[{"message":{"content":"{\"1\":80}"}}]}' );

			$this->assertSame( array( 7 => 80 ), $this->rank(), $model );

			$body = $this->sent_body();
			$this->assertSame( $model, $body['model'] );
			$this->assertArrayNotHasKey( 'temperature', $body, $model );
			$this->assertArrayNotHasKey( 'max_tokens', $body, $model );
			$this->assertGreaterThan( 0, $body['max_completion_tokens'], $model );
		}
	}

	public function test_openai_classic_model_keeps_temperature_zero(): void {
		$this->configure( 'openai', 'gpt-4o-mini' );
		$this->respond( 200, '{"choices":[{"message":{"content":"{\"1\":80}"}}]}' );

		$this->rank();

		$body = $this->sent_body();
		$this->assertSame( 0, $body['temperature'] );
		$this->assertArrayNotHasKey( 'max_tokens', $body );
		$this->assertSame( 500, $body['max_completion_tokens'] );
	}

	public function test_api_error_is_recorded_without_the_key_and_cleared_by_a_success(): void {
		$this->configure( 'anthropic', '' );
		$this->respond( 404, '{"type":"error","error":{"type":"not_found_error","message":"model: claude-x not found for sk-secret-key-123"}}' );

		$this->assertNull( $this->rank() );

		$error = AI_Ranker::last_error();
		$this->assertIsArray( $error );
		$this->assertSame( 404, $error['code'] );
		$this->assertSame( 'anthropic', $error['provider'] );
		$this->assertSame( 'claude-haiku-4-5-20251001', $error['model'] );
		$this->assertStringContainsString( 'not found', $error['message'] );
		$this->assertStringNotContainsString( 'sk-secret-key-123', $error['message'] );

		$this->respond( 200, '{"content":[{"text":"{\"1\":55}"}]}' );
		$this->assertSame( array( 7 => 55 ), $this->rank() );
		$this->assertNull( AI_Ranker::last_error() );
	}

	public function test_transport_error_and_unreadable_reply_are_recorded(): void {
		$GLOBALS['dragoninternallinks_test']['http'] = new \WP_Error( 'http_request_failed', 'cURL error 28: timed out' );

		$this->assertNull( $this->rank() );
		$this->assertSame( 0, AI_Ranker::last_error()['code'] );
		$this->assertStringContainsString( 'timed out', AI_Ranker::last_error()['message'] );

		$this->respond( 200, '{"choices":[{"message":{"content":"I cannot help with that."}}]}' );

		$this->assertNull( $this->rank() );
		$this->assertSame( 200, AI_Ranker::last_error()['code'] );
	}
}
