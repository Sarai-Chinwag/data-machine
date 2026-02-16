<?php
/**
 * Tests for BingWebmaster global tool.
 *
 * @package DataMachine\Tests\Unit\AI\Tools
 */

namespace DataMachine\Tests\Unit\AI\Tools;

use DataMachine\Engine\AI\Tools\Global\BingWebmaster;
use WP_UnitTestCase;
use WP_Error;

class BingWebmasterTest extends WP_UnitTestCase {

	private BingWebmaster $tool;

	public function set_up(): void {
		parent::set_up();
		$this->tool = new BingWebmaster();
	}

	public function tear_down(): void {
		delete_site_option( 'datamachine_bing_webmaster_config' );
		parent::tear_down();
	}

	/**
	 * Test tool definition has required fields.
	 */
	public function test_get_tool_definition(): void {
		$def = $this->tool->getToolDefinition();

		$this->assertSame( BingWebmaster::class, $def['class'] );
		$this->assertSame( 'handle_tool_call', $def['method'] );
		$this->assertArrayHasKey( 'description', $def );
		$this->assertArrayHasKey( 'parameters', $def );
		$this->assertTrue( $def['requires_config'] );
		$this->assertArrayHasKey( 'action', $def['parameters'] );
		$this->assertTrue( $def['parameters']['action']['required'] );
		$this->assertArrayHasKey( 'site_url', $def['parameters'] );
		$this->assertFalse( $def['parameters']['site_url']['required'] );
		$this->assertArrayHasKey( 'limit', $def['parameters'] );
		$this->assertFalse( $def['parameters']['limit']['required'] );
	}

	/**
	 * Test handle_tool_call returns error when ability not registered.
	 */
	public function test_handle_tool_call_missing_ability(): void {
		// Mock wp_get_ability to return null
		$filter = function( $ability, $ability_name ) {
			if ( 'datamachine/bing-webmaster' === $ability_name ) {
				return null;
			}
			return $ability;
		};
		add_filter( 'wp_get_ability', $filter, 10, 2 );

		$result = $this->tool->handle_tool_call( [ 'action' => 'query_stats' ] );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'ability not registered', $result['error'] );
		$this->assertSame( 'bing_webmaster', $result['tool_name'] );

		remove_filter( 'wp_get_ability', $filter, 10 );
	}

	/**
	 * Test handle_tool_call handles WP_Error from ability.
	 */
	public function test_handle_tool_call_wp_error(): void {
		$mock_ability = $this->createMock( \stdClass::class );
		$mock_ability->method( 'execute' )
			->willReturn( new WP_Error( 'api_error', 'Bing API connection failed' ) );

		$filter = function( $ability, $ability_name ) use ( $mock_ability ) {
			if ( 'datamachine/bing-webmaster' === $ability_name ) {
				return $mock_ability;
			}
			return $ability;
		};
		add_filter( 'wp_get_ability', $filter, 10, 2 );

		$result = $this->tool->handle_tool_call( [ 'action' => 'query_stats' ] );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'Bing API connection failed', $result['error'] );
		$this->assertSame( 'bing_webmaster', $result['tool_name'] );

		remove_filter( 'wp_get_ability', $filter, 10 );
	}

	/**
	 * Test handle_tool_call handles error from ability result.
	 */
	public function test_handle_tool_call_ability_error(): void {
		$mock_ability = $this->createMock( \stdClass::class );
		$mock_ability->method( 'execute' )
			->willReturn( [
				'success' => false,
				'error' => 'Invalid API key'
			] );

		$filter = function( $ability, $ability_name ) use ( $mock_ability ) {
			if ( 'datamachine/bing-webmaster' === $ability_name ) {
				return $mock_ability;
			}
			return $ability;
		};
		add_filter( 'wp_get_ability', $filter, 10, 2 );

		$result = $this->tool->handle_tool_call( [ 'action' => 'query_stats' ] );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'Invalid API key', $result['error'] );
		$this->assertSame( 'bing_webmaster', $result['tool_name'] );

		remove_filter( 'wp_get_ability', $filter, 10 );
	}

	/**
	 * Test handle_tool_call delegates to ability successfully.
	 */
	public function test_handle_tool_call_success(): void {
		$expected_params = [ 'action' => 'query_stats', 'limit' => 20 ];
		$expected_result = [
			'success' => true,
			'action' => 'query_stats',
			'results_count' => 2,
			'results' => [
				[ 'query' => 'test query 1', 'clicks' => 100 ],
				[ 'query' => 'test query 2', 'clicks' => 50 ]
			]
		];

		$mock_ability = $this->createMock( \stdClass::class );
		$mock_ability->expects( $this->once() )
			->method( 'execute' )
			->with( $expected_params )
			->willReturn( $expected_result );

		$filter = function( $ability, $ability_name ) use ( $mock_ability ) {
			if ( 'datamachine/bing-webmaster' === $ability_name ) {
				return $mock_ability;
			}
			return $ability;
		};
		add_filter( 'wp_get_ability', $filter, 10, 2 );

		$result = $this->tool->handle_tool_call( $expected_params );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'query_stats', $result['action'] );
		$this->assertSame( 2, $result['results_count'] );
		$this->assertSame( 'bing_webmaster', $result['tool_name'] );

		remove_filter( 'wp_get_ability', $filter, 10 );
	}

	/**
	 * Test configuration field definitions.
	 */
	public function test_get_config_fields(): void {
		$fields = $this->tool->get_config_fields( [], 'bing_webmaster' );

		$this->assertArrayHasKey( 'api_key', $fields );
		$this->assertArrayHasKey( 'site_url', $fields );

		$this->assertSame( 'password', $fields['api_key']['type'] );
		$this->assertTrue( $fields['api_key']['required'] );
		$this->assertSame( 'text', $fields['site_url']['type'] );
		$this->assertFalse( $fields['site_url']['required'] );
	}

	/**
	 * Test get_config_fields passthrough for different tool_id.
	 */
	public function test_get_config_fields_passthrough(): void {
		$existing = [ 'foo' => 'bar' ];
		$result = $this->tool->get_config_fields( $existing, 'image_generation' );
		$this->assertSame( $existing, $result );
	}

	/**
	 * Test check_configuration passthrough for wrong tool_id.
	 */
	public function test_check_configuration_passthrough(): void {
		$this->assertFalse( $this->tool->check_configuration( false, 'image_generation' ) );
		$this->assertTrue( $this->tool->check_configuration( true, 'image_generation' ) );
	}

	/**
	 * Test check_configuration for bing_webmaster tool.
	 */
	public function test_check_configuration_bing_webmaster(): void {
		delete_site_option( 'datamachine_bing_webmaster_config' );
		$this->assertFalse( $this->tool->check_configuration( true, 'bing_webmaster' ) );

		update_site_option( 'datamachine_bing_webmaster_config', [ 'api_key' => 'test-key' ] );
		$this->assertTrue( $this->tool->check_configuration( false, 'bing_webmaster' ) );
	}

	/**
	 * Test get_configuration passthrough for wrong tool_id.
	 */
	public function test_get_configuration_passthrough(): void {
		$existing = [ 'foo' => 'bar' ];
		$result = $this->tool->get_configuration( $existing, 'image_generation' );
		$this->assertSame( $existing, $result );
	}

	/**
	 * Test get_configuration for bing_webmaster tool.
	 */
	public function test_get_configuration_bing_webmaster(): void {
		$config = [ 'api_key' => 'test-key', 'site_url' => 'https://example.com' ];
		update_site_option( 'datamachine_bing_webmaster_config', $config );

		$result = $this->tool->get_configuration( [], 'bing_webmaster' );
		$this->assertSame( $config, $result );
	}

	/**
	 * Test is_configured returns false when no config.
	 */
	public function test_is_configured_false(): void {
		delete_site_option( 'datamachine_bing_webmaster_config' );
		$this->assertFalse( BingWebmaster::is_configured() );
	}

	/**
	 * Test is_configured returns true when configured.
	 */
	public function test_is_configured_true(): void {
		update_site_option( 'datamachine_bing_webmaster_config', [ 'api_key' => 'test-key' ] );
		$this->assertTrue( BingWebmaster::is_configured() );
	}
}