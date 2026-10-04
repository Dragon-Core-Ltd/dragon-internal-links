<?php
/**
 * The stored AI key belongs to the provider it was entered for: changing the
 * provider without entering a new key removes it, and a key remembered for
 * another provider is never sent.
 *
 * @package DragonInternalLinks
 */

namespace DragonInternalLinks\Tests;

use DragonInternalLinks\Admin;
use DragonInternalLinks\AI_Ranker;
use DragonInternalLinks\Analyzer;
use DragonInternalLinks\Crypto;
use DragonInternalLinks\Scanner;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-crypto.php';
require_once __DIR__ . '/../includes/class-ai-ranker.php';
require_once __DIR__ . '/../includes/class-scanner.php';
require_once __DIR__ . '/../includes/class-analyzer.php';
require_once __DIR__ . '/../includes/class-scheduler.php';
require_once __DIR__ . '/../includes/class-admin.php';

final class AiKeyProviderTest extends TestCase {

	private const MASK = '••••••••';

	private Admin $admin;

	protected function setUp(): void {
		dragoninternallinks_test_reset();
		$_POST = array();

		$scanner     = new Scanner();
		$this->admin = new Admin( $scanner, new Analyzer( $scanner ) );

		$GLOBALS['dragoninternallinks_test']['http'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"choices":[{"message":{"content":"{\"1\":80}"}}],"content":[{"text":"{\"1\":80}"}],"candidates":[{"content":{"parts":[{"text":"{\"1\":80}"}]}}]}',
		);
	}

	protected function tearDown(): void {
		$_POST = array();
	}

	private function save( string $provider, string $key ): void {
		$_POST = array(
			'dragoninternallinks_settings_nonce' => 'valid',
			'dragoninternallinks_ai_enabled'     => '1',
			'dragoninternallinks_ai_provider'    => $provider,
			'dragoninternallinks_ai_model'       => '',
			'dragoninternallinks_ai_api_key'     => $key,
		);
		ob_start();
		try {
			$this->admin->render_settings_page();
		} finally {
			ob_end_clean();
			$_POST = array();
		}
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

	/**
	 * Every key header sent so far, as url => key.
	 */
	private function sent_keys(): array {
		$out = array();
		foreach ( dragoninternallinks_test_calls( 'wp_safe_remote_post' ) as $call ) {
			$headers = $call[1]['headers'];
			$key     = $headers['x-goog-api-key'] ?? $headers['x-api-key'] ?? substr( (string) ( $headers['Authorization'] ?? '' ), strlen( 'Bearer ' ) );
			$out[]   = array( $call[0], $key );
		}
		return $out;
	}

	private function error_codes(): array {
		return array_column( $GLOBALS['dragoninternallinks_test']['settings_errors'], 1 );
	}

	public function test_a_key_is_remembered_with_the_provider_it_was_entered_for(): void {
		$this->save( 'anthropic', 'sk-ant-1' );

		$this->assertSame( 'anthropic', get_option( AI_Ranker::KEY_PROVIDER_OPTION ) );
		$this->assertSame( 'sk-ant-1', AI_Ranker::api_key() );
	}

	public function test_switching_provider_with_the_mask_removes_the_key_and_says_so(): void {
		$this->save( 'openai', 'sk-OPENAI-SECRET-0001' );
		$this->save( 'google', self::MASK );

		$this->assertSame( 'google', AI_Ranker::provider() );
		$this->assertFalse( get_option( 'dragoninternallinks_ai_api_key' ) );
		$this->assertSame( '', AI_Ranker::api_key() );
		$this->assertContains( 'ai_key_removed', $this->error_codes() );
		$this->assertFalse( AI_Ranker::enabled() );

		$this->assertNull( $this->rank() );
		$this->assertSame( array(), $this->sent_keys(), 'the OpenAI key is never sent to Google' );
	}

	public function test_switching_provider_with_a_new_key_keeps_the_new_key(): void {
		$this->save( 'openai', 'sk-openai' );
		$this->save( 'anthropic', 'sk-ant-new' );

		$this->assertSame( 'sk-ant-new', AI_Ranker::api_key() );
		$this->assertSame( 'anthropic', get_option( AI_Ranker::KEY_PROVIDER_OPTION ) );
		$this->assertNotContains( 'ai_key_removed', $this->error_codes() );

		$this->rank();
		$this->assertSame( array( array( 'https://api.anthropic.com/v1/messages', 'sk-ant-new' ) ), $this->sent_keys() );
	}

	public function test_the_mask_with_the_same_provider_keeps_the_key(): void {
		$this->save( 'google', 'AIza-key' );
		$this->save( 'google', self::MASK );

		$this->assertSame( 'AIza-key', AI_Ranker::api_key() );
		$this->assertNotContains( 'ai_key_removed', $this->error_codes() );
	}

	public function test_a_key_remembered_for_another_provider_is_never_sent(): void {
		$options = &$GLOBALS['dragoninternallinks_test']['options'];

		$options['dragoninternallinks_ai_enabled']  = true;
		$options['dragoninternallinks_ai_provider'] = 'google';
		$options['dragoninternallinks_ai_api_key']  = Crypto::encrypt( 'sk-openai' );
		$options[ AI_Ranker::KEY_PROVIDER_OPTION ]  = 'openai';

		$this->assertSame( '', AI_Ranker::api_key() );
		$this->assertFalse( AI_Ranker::enabled() );
		$this->assertNull( $this->rank() );
		$this->assertSame( array(), $this->sent_keys() );
	}

	public function test_a_key_saved_before_the_provider_was_remembered_belongs_to_the_saved_provider(): void {
		$options = &$GLOBALS['dragoninternallinks_test']['options'];

		$options['dragoninternallinks_ai_enabled']  = true;
		$options['dragoninternallinks_ai_provider'] = 'anthropic';
		$options['dragoninternallinks_ai_api_key']  = Crypto::encrypt( 'sk-legacy' );

		$this->assertSame( 'sk-legacy', AI_Ranker::api_key() );
		$this->rank();
		$this->assertSame( array( array( 'https://api.anthropic.com/v1/messages', 'sk-legacy' ) ), $this->sent_keys() );

		// Switching away with the mask removes it rather than sending it on.
		$this->save( 'openai', self::MASK );
		$this->assertSame( '', AI_Ranker::api_key() );
		$this->assertContains( 'ai_key_removed', $this->error_codes() );
	}

	public function test_a_key_saved_by_1_1_12_survives_a_resave_with_the_dots_and_the_same_provider(): void {
		$options = &$GLOBALS['dragoninternallinks_test']['options'];

		$options['dragoninternallinks_ai_enabled']  = true;
		$options['dragoninternallinks_ai_provider'] = 'anthropic';
		$options['dragoninternallinks_ai_api_key']  = Crypto::encrypt( 'sk-legacy-1112' );
		$stored                                     = $options['dragoninternallinks_ai_api_key'];

		$this->save( 'anthropic', self::MASK );

		$this->assertSame( $stored, get_option( 'dragoninternallinks_ai_api_key' ), 'the stored key is untouched' );
		$this->assertNotContains( 'ai_key_removed', $this->error_codes() );
		$this->assertSame( 'sk-legacy-1112', AI_Ranker::api_key() );

		$this->rank();
		$this->assertSame( array( array( 'https://api.anthropic.com/v1/messages', 'sk-legacy-1112' ) ), $this->sent_keys() );
	}

	public function test_clearing_the_key_forgets_its_provider_too(): void {
		$this->save( 'openai', 'sk-openai' );
		$this->save( 'openai', '' );

		$this->assertFalse( get_option( 'dragoninternallinks_ai_api_key' ) );
		$this->assertFalse( get_option( AI_Ranker::KEY_PROVIDER_OPTION ) );
	}
}
