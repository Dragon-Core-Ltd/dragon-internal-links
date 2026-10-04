<?php
/**
 * Settings screen: what a submitted form writes, and what a request without a
 * valid nonce or the capability leaves alone.
 *
 * @package DragonInternalLinks
 */

namespace DragonInternalLinks\Tests;

use DragonInternalLinks\Admin;
use DragonInternalLinks\AI_Ranker;
use DragonInternalLinks\Analyzer;
use DragonInternalLinks\Scanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-crypto.php';
require_once __DIR__ . '/../includes/class-ai-ranker.php';
require_once __DIR__ . '/../includes/class-scanner.php';
require_once __DIR__ . '/../includes/class-analyzer.php';
require_once __DIR__ . '/../includes/class-scheduler.php';
require_once __DIR__ . '/../includes/class-admin.php';

final class WporgScanSettingsTest extends TestCase {

	private const HOOK = 'dragoninternallinks_daily_scan';

	private Admin $admin;

	protected function setUp(): void {
		dragoninternallinks_test_reset();
		$_POST = array();

		$scanner     = new Scanner();
		$this->admin = new Admin( $scanner, new Analyzer( $scanner ) );
	}

	protected function tearDown(): void {
		$_POST = array();
	}

	/**
	 * The fields the settings form posts, slashed as WordPress slashes $_POST.
	 */
	private function form( array $overrides = array() ): array {
		return array_merge(
			array(
				'dragoninternallinks_settings_nonce'     => 'valid',
				'dragoninternallinks_post_types'         => array( 'post', 'page' ),
				'dragoninternallinks_auto_scan'          => '1',
				'dragoninternallinks_scan_frequency'     => 'weekly',
				'dragoninternallinks_min_word_count'     => '4',
				'dragoninternallinks_exclude_categories' => array( '3', '7' ),
				'dragoninternallinks_ai_enabled'         => '1',
				'dragoninternallinks_ai_provider'        => 'anthropic',
				'dragoninternallinks_ai_model'           => 'claude-haiku-4-5-20251001',
				'dragoninternallinks_ai_api_key'         => 'sk-ant-api03-AbC_123-xyz',
				'dragoninternallinks_delete_data'        => '1',
				'submit'                                 => 'Save Settings',
			),
			$overrides
		);
	}

	/**
	 * Load the settings screen with the given POST body and return its HTML.
	 */
	private function submit( array $post ): string {
		$_POST = $post;
		ob_start();
		try {
			$this->admin->render_settings_page();
		} finally {
			$html  = (string) ob_get_clean();
			$_POST = array();
		}
		return $html;
	}

	/**
	 * Settings as an earlier save left them, with a stored key and a schedule.
	 */
	private function seed(): array {
		$GLOBALS['dragoninternallinks_test']['options'] = array(
			'dragoninternallinks_post_types'               => array( 'post' ),
			'dragoninternallinks_auto_scan'                => false,
			'dragoninternallinks_min_word_count'           => 3,
			'dragoninternallinks_exclude_categories'       => array( 11 ),
			'dragoninternallinks_scan_frequency'           => 'daily',
			'dragoninternallinks_ai_enabled'               => false,
			'dragoninternallinks_ai_provider'              => 'openai',
			'dragoninternallinks_ai_model'                 => 'gpt-6-luna',
			'dragoninternallinks_ai_api_key'               => AI_Ranker::encrypt_key( 'sk-stored-key' ),
			'dragoninternallinks_delete_data_on_uninstall' => false,
		);
		$GLOBALS['dragoninternallinks_test']['cron']    = array( array( 1900000000, self::HOOK, 'daily' ) );

		return array(
			$GLOBALS['dragoninternallinks_test']['options'],
			$GLOBALS['dragoninternallinks_test']['cron'],
		);
	}

	private function assert_untouched( array $before, string $html ): void {
		$this->assertSame( $before[0], $GLOBALS['dragoninternallinks_test']['options'], 'no option is written' );
		$this->assertSame( $before[1], $GLOBALS['dragoninternallinks_test']['cron'], 'the schedule is left alone' );
		$this->assertSame( array(), $GLOBALS['dragoninternallinks_test']['settings_errors'] );
		$this->assertStringNotContainsString( 'Settings saved.', $html );
		$this->assertStringContainsString( 'name="dragoninternallinks_settings_nonce"', $html, 'the screen still renders' );
	}

	private function option( string $name ) {
		return get_option( 'dragoninternallinks_' . $name, 'UNSET' );
	}

	// -- refused requests -------------------------------------------------------

	public function test_opening_the_screen_writes_nothing(): void {
		$before = $this->seed();

		$html = $this->submit( array() );

		$this->assert_untouched( $before, $html );
		$this->assertSame( array(), dragoninternallinks_test_calls( 'wp_verify_nonce' ) );
	}

	public function test_a_form_without_the_nonce_field_writes_nothing(): void {
		$before = $this->seed();
		$post   = $this->form();
		unset( $post['dragoninternallinks_settings_nonce'] );

		$this->assert_untouched( $before, $this->submit( $post ) );
	}

	public function test_a_wrong_nonce_writes_nothing(): void {
		$before = $this->seed();

		$html = $this->submit( $this->form( array( 'dragoninternallinks_settings_nonce' => '0badc0ffee' ) ) );

		$this->assert_untouched( $before, $html );
		$this->assertSame(
			array( array( '0badc0ffee', 'dragoninternallinks_save_settings' ) ),
			dragoninternallinks_test_calls( 'wp_verify_nonce' )
		);
	}

	public function test_an_empty_or_array_nonce_writes_nothing(): void {
		$before = $this->seed();

		$this->assert_untouched( $before, $this->submit( $this->form( array( 'dragoninternallinks_settings_nonce' => '' ) ) ) );
		$this->assert_untouched( $before, $this->submit( $this->form( array( 'dragoninternallinks_settings_nonce' => array( 'valid' ) ) ) ) );
	}

	public function test_a_user_without_manage_options_writes_nothing(): void {
		$before = $this->seed();
		$GLOBALS['dragoninternallinks_test']['can'] = false;

		$this->assert_untouched( $before, $this->submit( $this->form() ) );
	}

	// -- a valid save -----------------------------------------------------------

	public function test_a_valid_form_saves_every_setting(): void {
		$this->seed();

		$html = $this->submit( $this->form() );

		$this->assertSame(
			array( array( 'valid', 'dragoninternallinks_save_settings' ) ),
			dragoninternallinks_test_calls( 'wp_verify_nonce' )
		);
		$this->assertSame( array( 'post', 'page' ), $this->option( 'post_types' ) );
		$this->assertTrue( $this->option( 'auto_scan' ) );
		$this->assertSame( 4, $this->option( 'min_word_count' ) );
		$this->assertSame( array( 3, 7 ), $this->option( 'exclude_categories' ) );
		$this->assertSame( 'weekly', $this->option( 'scan_frequency' ) );
		$this->assertTrue( $this->option( 'ai_enabled' ) );
		$this->assertSame( 'anthropic', $this->option( 'ai_provider' ) );
		$this->assertSame( 'claude-haiku-4-5-20251001', $this->option( 'ai_model' ) );
		$this->assertSame( 'sk-ant-api03-AbC_123-xyz', AI_Ranker::api_key() );
		$this->assertStringNotContainsString( 'sk-ant-api03', (string) $this->option( 'ai_api_key' ), 'the key is stored encrypted' );
		$this->assertTrue( $this->option( 'delete_data_on_uninstall' ) );

		$this->assertSame( array( 'weekly' ), array_column( $GLOBALS['dragoninternallinks_test']['cron'], 2 ) );
		$this->assertStringContainsString( 'Settings saved.', $html );
		$this->assertStringNotContainsString( 'sk-ant-api03', $html, 'the key is never printed back' );
	}

	public function test_unticked_boxes_and_empty_lists_are_saved_as_off(): void {
		$this->seed();
		$GLOBALS['dragoninternallinks_test']['options']['dragoninternallinks_auto_scan']                = true;
		$GLOBALS['dragoninternallinks_test']['options']['dragoninternallinks_ai_enabled']               = true;
		$GLOBALS['dragoninternallinks_test']['options']['dragoninternallinks_delete_data_on_uninstall'] = true;

		$post = $this->form();
		unset(
			$post['dragoninternallinks_post_types'],
			$post['dragoninternallinks_auto_scan'],
			$post['dragoninternallinks_exclude_categories'],
			$post['dragoninternallinks_ai_enabled'],
			$post['dragoninternallinks_delete_data']
		);
		$this->submit( $post );

		$this->assertSame( array(), $this->option( 'post_types' ) );
		$this->assertFalse( $this->option( 'auto_scan' ) );
		$this->assertSame( array(), $this->option( 'exclude_categories' ) );
		$this->assertFalse( $this->option( 'ai_enabled' ) );
		$this->assertFalse( $this->option( 'delete_data_on_uninstall' ) );
	}

	public function test_fields_missing_from_the_request_keep_their_stored_value(): void {
		$this->seed();

		$this->submit( array( 'dragoninternallinks_settings_nonce' => 'valid' ) );

		$this->assertSame( 3, $this->option( 'min_word_count' ) );
		$this->assertSame( 'daily', $this->option( 'scan_frequency' ) );
		$this->assertSame( 'openai', $this->option( 'ai_provider' ) );
		$this->assertSame( 'gpt-6-luna', $this->option( 'ai_model' ) );
		$this->assertSame( 'sk-stored-key', AI_Ranker::api_key() );
		$this->assertSame( array( 'daily' ), array_column( $GLOBALS['dragoninternallinks_test']['cron'], 2 ) );
	}

	// -- post types -------------------------------------------------------------

	public static function post_type_lists(): array {
		return array(
			'core types'              => array( array( 'post', 'page' ), array( 'post', 'page' ) ),
			'custom type slugs'       => array( array( 'my-type_2', 'product' ), array( 'my-type_2', 'product' ) ),
			'a single string'         => array( 'page', array( 'page' ) ),
			'a nested array is dropped' => array( array( 'post', array( 'page' ), 'product' ), array( 'post', 'product' ) ),
			'markup and case'         => array( array( 'Book<b>', "po'st" ), array( 'bookb', 'post' ) ),
			'string keys are dropped' => array(
				array(
					'a' => 'post',
					'b' => 'page',
				),
				array( 'post', 'page' ),
			),
		);
	}

	#[DataProvider( 'post_type_lists' )]
	public function test_post_types_are_saved_as_a_flat_list_of_slugs( $posted, array $expected ): void {
		$this->submit( $this->form( array( 'dragoninternallinks_post_types' => wp_slash( $posted ) ) ) );

		$this->assertSame( $expected, $this->option( 'post_types' ) );
		$this->assertSame( $expected, Scanner::post_types() );
	}

	// -- excluded categories ----------------------------------------------------

	public static function category_lists(): array {
		return array(
			'ids as the form posts them' => array( array( '3', '7' ), array( 3, 7 ) ),
			'integers'                   => array( array( 12, 5 ), array( 12, 5 ) ),
			'a single string'            => array( '5', array( 5 ) ),
			'text after the digits'      => array( array( '7abc', '-4' ), array( 7, 4 ) ),
			'a nested array is dropped'  => array( array( '3', array( '9' ), '8' ), array( 3, 8 ) ),
		);
	}

	#[DataProvider( 'category_lists' )]
	public function test_excluded_categories_are_saved_as_a_flat_list_of_ids( $posted, array $expected ): void {
		$this->submit( $this->form( array( 'dragoninternallinks_exclude_categories' => $posted ) ) );

		$this->assertSame( $expected, $this->option( 'exclude_categories' ) );
		$this->assertSame( $expected, Scanner::excluded_categories() );
	}

	// -- numbers, choices and text ----------------------------------------------

	public function test_minimum_words_is_saved_as_a_whole_number(): void {
		$this->submit( $this->form( array( 'dragoninternallinks_min_word_count' => '6' ) ) );
		$this->assertSame( 6, $this->option( 'min_word_count' ) );

		$this->submit( $this->form( array( 'dragoninternallinks_min_word_count' => '-2' ) ) );
		$this->assertSame( 2, $this->option( 'min_word_count' ) );

		$this->submit( $this->form( array( 'dragoninternallinks_min_word_count' => 'abc' ) ) );
		$this->assertSame( 0, $this->option( 'min_word_count' ) );
	}

	public function test_an_unknown_frequency_or_provider_is_not_stored(): void {
		$this->seed();

		$this->submit(
			$this->form(
				array(
					'dragoninternallinks_scan_frequency' => 'hourly',
					'dragoninternallinks_ai_provider'    => 'evil.example',
				)
			)
		);

		$this->assertSame( 'daily', $this->option( 'scan_frequency' ) );
		$this->assertSame( 'openai', $this->option( 'ai_provider' ) );

		$this->submit(
			$this->form(
				array(
					'dragoninternallinks_scan_frequency' => array( 'weekly' ),
					'dragoninternallinks_ai_provider'    => array( 'google' ),
				)
			)
		);

		$this->assertSame( 'daily', $this->option( 'scan_frequency' ) );
		$this->assertSame( 'openai', $this->option( 'ai_provider' ) );
	}

	public function test_each_known_provider_is_stored(): void {
		foreach ( array( 'openai', 'anthropic', 'google' ) as $provider ) {
			$this->submit( $this->form( array( 'dragoninternallinks_ai_provider' => $provider ) ) );
			$this->assertSame( $provider, $this->option( 'ai_provider' ) );
		}
	}

	public function test_the_model_name_is_stored_as_plain_text(): void {
		foreach ( array( 'gpt-6-luna', 'gemini-3.5-flash-lite', 'ft:gpt-6-luna:acme:2026', '' ) as $model ) {
			$this->submit( $this->form( array( 'dragoninternallinks_ai_model' => $model ) ) );
			$this->assertSame( $model, $this->option( 'ai_model' ) );
		}

		$this->submit( $this->form( array( 'dragoninternallinks_ai_model' => "  gpt-6-luna<script>x</script>\n" ) ) );
		$this->assertSame( 'gpt-6-luna', $this->option( 'ai_model' ) );

		$this->submit( $this->form( array( 'dragoninternallinks_ai_model' => array( 'gpt-6-luna' ) ) ) );
		$this->assertSame( '', $this->option( 'ai_model' ) );
	}

	// -- the API key ------------------------------------------------------------

	public static function api_keys(): array {
		return array(
			'openai project key'     => array( 'sk-proj-AbCdEf_0123456789-xyzXYZ' ),
			'anthropic key'          => array( 'sk-ant-api03-AbC_123-xyz-_AA' ),
			'google key'             => array( 'AIzaSyD-abc_123XYZ' ),
			'percent octets'         => array( 'key%20with%4Foctets%zz' ),
			'plus slash equals amp'  => array( 'a+b/c=d&e[]' ),
			'angle brackets'         => array( 'k<e>y' ),
			'an unclosed bracket'    => array( 'abc<def' ),
			'quotes'                 => array( 'quo\'te"key' ),
			'a backslash'            => array( 'back\\slash\\\\two' ),
			'multibyte'              => array( 'ключ-密钥-ñ' ),
			'inner spaces'           => array( 'two  spaces inside' ),
		);
	}

	#[DataProvider( 'api_keys' )]
	public function test_the_api_key_is_stored_exactly_as_entered( string $key ): void {
		$this->submit( $this->form( array( 'dragoninternallinks_ai_api_key' => wp_slash( $key ) ) ) );

		$this->assertSame( $key, AI_Ranker::api_key() );
	}

	public function test_spaces_and_line_breaks_around_a_pasted_key_are_removed(): void {
		$this->submit( $this->form( array( 'dragoninternallinks_ai_api_key' => "  sk-proj-abc_123 \r\n" ) ) );
		$this->assertSame( 'sk-proj-abc_123', AI_Ranker::api_key() );

		$this->submit( $this->form( array( 'dragoninternallinks_ai_api_key' => "sk-proj-\r\nabc\t_456\0" ) ) );
		$this->assertSame( 'sk-proj-abc_456', AI_Ranker::api_key() );
	}

	public function test_the_masked_placeholder_keeps_the_stored_key(): void {
		$this->seed();
		$stored = $this->option( 'ai_api_key' );

		$this->submit(
			$this->form(
				array(
					'dragoninternallinks_ai_provider' => 'openai',
					'dragoninternallinks_ai_api_key'  => '••••••••',
				)
			)
		);

		$this->assertSame( $stored, $this->option( 'ai_api_key' ) );
		$this->assertSame( 'sk-stored-key', AI_Ranker::api_key() );
	}

	public function test_an_empty_key_field_removes_the_stored_key(): void {
		$this->seed();

		$this->submit( $this->form( array( 'dragoninternallinks_ai_api_key' => '   ' ) ) );

		$this->assertSame( 'UNSET', $this->option( 'ai_api_key' ) );
		$this->assertSame( '', AI_Ranker::api_key() );
	}

	public function test_a_key_field_that_is_not_text_keeps_the_stored_key(): void {
		$this->seed();
		$stored = $this->option( 'ai_api_key' );

		$this->submit(
			$this->form(
				array(
					'dragoninternallinks_ai_provider' => 'openai',
					'dragoninternallinks_ai_api_key'  => array( 'sk-other' ),
				)
			)
		);

		$this->assertSame( $stored, $this->option( 'ai_api_key' ) );
	}

	public function test_a_new_key_clears_the_recorded_ai_failure_and_the_same_key_keeps_it(): void {
		$this->seed();
		$error = array(
			'time'    => 1758800000,
			'model'   => 'gpt-6-luna',
			'code'    => 401,
			'message' => 'bad key',
			'reason'  => 'http',
		);
		$same  = array(
			'dragoninternallinks_ai_provider' => 'openai',
			'dragoninternallinks_ai_model'    => 'gpt-6-luna',
			'dragoninternallinks_ai_api_key'  => '••••••••',
		);

		$GLOBALS['dragoninternallinks_test']['options'][ AI_Ranker::LAST_ERROR_OPTION ] = $error;
		$this->submit( $this->form( $same ) );
		$this->assertSame( $error, get_option( AI_Ranker::LAST_ERROR_OPTION ) );

		$this->submit( $this->form( array_merge( $same, array( 'dragoninternallinks_ai_api_key' => 'sk-new-key' ) ) ) );
		$this->assertFalse( get_option( AI_Ranker::LAST_ERROR_OPTION ) );
	}
}
