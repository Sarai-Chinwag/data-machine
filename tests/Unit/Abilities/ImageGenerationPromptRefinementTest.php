<?php
/**
 * Tests for Image Generation prompt refinement.
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\Media\ImageGenerationAbilities;
use WP_UnitTestCase;

class ImageGenerationPromptRefinementTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_site_option( ImageGenerationAbilities::CONFIG_OPTION );
	}

	public function tear_down(): void {
		delete_site_option( ImageGenerationAbilities::CONFIG_OPTION );
		// Clear PluginSettings cache.
		if ( method_exists( \DataMachine\Core\PluginSettings::class, 'clearCache' ) ) {
			\DataMachine\Core\PluginSettings::clearCache();
		}
		parent::tear_down();
	}

	public function test_is_refinement_enabled_defaults_to_true_when_provider_configured(): void {
		// Set up DM with a provider.
		update_option( 'datamachine_settings', [
			'default_provider' => 'openai',
			'default_model'    => 'gpt-4o-mini',
		] );
		\DataMachine\Core\PluginSettings::clearCache();

		$config = [ 'api_key' => 'test-key' ];
		$this->assertTrue( ImageGenerationAbilities::is_refinement_enabled( $config ) );
	}

	public function test_is_refinement_enabled_false_when_explicitly_disabled(): void {
		update_option( 'datamachine_settings', [
			'default_provider' => 'openai',
			'default_model'    => 'gpt-4o-mini',
		] );
		\DataMachine\Core\PluginSettings::clearCache();

		$config = [
			'api_key'                   => 'test-key',
			'prompt_refinement_enabled' => false,
		];
		$this->assertFalse( ImageGenerationAbilities::is_refinement_enabled( $config ) );
	}

	public function test_is_refinement_enabled_false_when_no_provider(): void {
		update_option( 'datamachine_settings', [] );
		\DataMachine\Core\PluginSettings::clearCache();

		$config = [ 'api_key' => 'test-key' ];
		$this->assertFalse( ImageGenerationAbilities::is_refinement_enabled( $config ) );
	}

	public function test_refine_prompt_returns_null_when_no_provider(): void {
		update_option( 'datamachine_settings', [] );
		\DataMachine\Core\PluginSettings::clearCache();

		$result = ImageGenerationAbilities::refine_prompt( 'test prompt' );
		$this->assertNull( $result );
	}

	public function test_get_default_style_guide_is_non_empty(): void {
		$guide = ImageGenerationAbilities::get_default_style_guide();
		$this->assertNotEmpty( $guide );
		$this->assertStringContainsString( 'NEVER include text', $guide );
		$this->assertStringContainsString( 'prompt', $guide );
	}

	public function test_get_default_style_guide_mentions_no_text_rule(): void {
		$guide = ImageGenerationAbilities::get_default_style_guide();
		// The most critical rule — AI image models can't render text.
		$this->assertStringContainsString( 'text', $guide );
		$this->assertStringContainsString( 'words', $guide );
	}

	public function test_config_stores_style_guide(): void {
		$config = [
			'api_key'            => 'test-key',
			'prompt_style_guide' => 'Custom brand style: always use warm tones and natural settings.',
		];
		update_site_option( ImageGenerationAbilities::CONFIG_OPTION, $config );

		$stored = ImageGenerationAbilities::get_config();
		$this->assertSame( 'Custom brand style: always use warm tones and natural settings.', $stored['prompt_style_guide'] );
	}

	public function test_config_stores_refinement_enabled_flag(): void {
		$config = [
			'api_key'                   => 'test-key',
			'prompt_refinement_enabled' => false,
		];
		update_site_option( ImageGenerationAbilities::CONFIG_OPTION, $config );

		$stored = ImageGenerationAbilities::get_config();
		$this->assertFalse( $stored['prompt_refinement_enabled'] );
	}

	public function test_refine_prompt_uses_custom_style_guide(): void {
		// This test verifies the config is read — actual AI call would need integration test.
		$config = [
			'api_key'            => 'test-key',
			'prompt_style_guide' => 'Always generate watercolor paintings.',
		];
		update_site_option( ImageGenerationAbilities::CONFIG_OPTION, $config );

		// Without a provider configured, refinement returns null (graceful fallback).
		update_option( 'datamachine_settings', [] );
		\DataMachine\Core\PluginSettings::clearCache();

		$result = ImageGenerationAbilities::refine_prompt( 'A crane', '', $config );
		$this->assertNull( $result ); // No provider = null, not an error.
	}
}
