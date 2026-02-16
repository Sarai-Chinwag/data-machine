<?php
/**
 * Tests for ImageGeneration tool handle_tool_call method.
 *
 * @package DataMachine\Tests\Unit\AI\Tools
 */

namespace DataMachine\Tests\Unit\AI\Tools;

use DataMachine\Engine\AI\Tools\Global\ImageGeneration;
use WP_UnitTestCase;
use WP_Error;

class ImageGenerationToolCallTest extends WP_UnitTestCase {

	private ImageGeneration $tool;

	public function set_up(): void {
		parent::set_up();
		$this->tool = new ImageGeneration();
	}

	public function tear_down(): void {
		parent::tear_down();
	}

	/**
	 * Test handle_tool_call returns error when ability not registered.
	 */
	public function test_handle_tool_call_missing_ability(): void {
		// Mock wp_get_ability to return null
		$filter = function( $ability, $ability_name ) {
			if ( 'datamachine/generate-image' === $ability_name ) {
				return null;
			}
			return $ability;
		};
		add_filter( 'wp_get_ability', $filter, 10, 2 );

		$result = $this->tool->handle_tool_call( [ 'prompt' => 'A beautiful sunset' ] );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'ability not registered', $result['error'] );
		$this->assertSame( 'image_generation', $result['tool_name'] );

		remove_filter( 'wp_get_ability', $filter, 10 );
	}

	/**
	 * Test handle_tool_call handles WP_Error from ability.
	 */
	public function test_handle_tool_call_wp_error(): void {
		$mock_ability = $this->createMock( \stdClass::class );
		$mock_ability->method( 'execute' )
			->willReturn( new WP_Error( 'api_error', 'Replicate API connection failed' ) );

		$filter = function( $ability, $ability_name ) use ( $mock_ability ) {
			if ( 'datamachine/generate-image' === $ability_name ) {
				return $mock_ability;
			}
			return $ability;
		};
		add_filter( 'wp_get_ability', $filter, 10, 2 );

		$result = $this->tool->handle_tool_call( [ 'prompt' => 'A beautiful sunset' ] );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'Replicate API connection failed', $result['error'] );
		$this->assertSame( 'image_generation', $result['tool_name'] );

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
			if ( 'datamachine/generate-image' === $ability_name ) {
				return $mock_ability;
			}
			return $ability;
		};
		add_filter( 'wp_get_ability', $filter, 10, 2 );

		$result = $this->tool->handle_tool_call( [ 'prompt' => 'A beautiful sunset' ] );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'Invalid API key', $result['error'] );
		$this->assertSame( 'image_generation', $result['tool_name'] );

		remove_filter( 'wp_get_ability', $filter, 10 );
	}

	/**
	 * Test handle_tool_call delegates to ability successfully with basic parameters.
	 */
	public function test_handle_tool_call_success_basic(): void {
		$input_params = [ 'prompt' => 'A beautiful sunset' ];
		$expected_ability_input = [
			'prompt' => 'A beautiful sunset',
			'model' => '',
			'aspect_ratio' => ''
		];
		$expected_result = [
			'success' => true,
			'pending' => true,
			'job_id' => 123,
			'prediction_id' => 'pred_abc123',
			'message' => 'Image generation scheduled'
		];

		$mock_ability = $this->createMock( \stdClass::class );
		$mock_ability->expects( $this->once() )
			->method( 'execute' )
			->with( $expected_ability_input )
			->willReturn( $expected_result );

		$filter = function( $ability, $ability_name ) use ( $mock_ability ) {
			if ( 'datamachine/generate-image' === $ability_name ) {
				return $mock_ability;
			}
			return $ability;
		};
		add_filter( 'wp_get_ability', $filter, 10, 2 );

		$result = $this->tool->handle_tool_call( $input_params );

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['pending'] );
		$this->assertSame( 123, $result['job_id'] );
		$this->assertSame( 'pred_abc123', $result['prediction_id'] );
		$this->assertSame( 'image_generation', $result['tool_name'] );

		remove_filter( 'wp_get_ability', $filter, 10 );
	}

	/**
	 * Test handle_tool_call passes all parameters including job_id as pipeline_job_id.
	 */
	public function test_handle_tool_call_with_job_id_and_parameters(): void {
		$input_params = [
			'prompt' => 'A serene mountain landscape',
			'model' => 'google/imagen-4-fast',
			'aspect_ratio' => '16:9',
			'job_id' => 456
		];
		$expected_ability_input = [
			'prompt' => 'A serene mountain landscape',
			'model' => 'google/imagen-4-fast',
			'aspect_ratio' => '16:9',
			'pipeline_job_id' => 456
		];
		$expected_result = [
			'success' => true,
			'pending' => true,
			'job_id' => 789,
			'prediction_id' => 'pred_xyz789',
			'message' => 'Image generation scheduled'
		];

		$mock_ability = $this->createMock( \stdClass::class );
		$mock_ability->expects( $this->once() )
			->method( 'execute' )
			->with( $expected_ability_input )
			->willReturn( $expected_result );

		$filter = function( $ability, $ability_name ) use ( $mock_ability ) {
			if ( 'datamachine/generate-image' === $ability_name ) {
				return $mock_ability;
			}
			return $ability;
		};
		add_filter( 'wp_get_ability', $filter, 10, 2 );

		$result = $this->tool->handle_tool_call( $input_params );

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['pending'] );
		$this->assertSame( 789, $result['job_id'] );
		$this->assertSame( 'pred_xyz789', $result['prediction_id'] );
		$this->assertSame( 'image_generation', $result['tool_name'] );

		remove_filter( 'wp_get_ability', $filter, 10 );
	}

	/**
	 * Test handle_tool_call doesn't pass pipeline_job_id when job_id is missing.
	 */
	public function test_handle_tool_call_no_job_id(): void {
		$input_params = [
			'prompt' => 'A peaceful forest scene',
			'model' => 'black-forest-labs/flux-schnell'
		];
		$expected_ability_input = [
			'prompt' => 'A peaceful forest scene',
			'model' => 'black-forest-labs/flux-schnell',
			'aspect_ratio' => ''
		];
		$expected_result = [
			'success' => true,
			'pending' => true,
			'job_id' => 999,
			'prediction_id' => 'pred_flux999'
		];

		$mock_ability = $this->createMock( \stdClass::class );
		$mock_ability->expects( $this->once() )
			->method( 'execute' )
			->with( $expected_ability_input )
			->willReturn( $expected_result );

		$filter = function( $ability, $ability_name ) use ( $mock_ability ) {
			if ( 'datamachine/generate-image' === $ability_name ) {
				return $mock_ability;
			}
			return $ability;
		};
		add_filter( 'wp_get_ability', $filter, 10, 2 );

		$result = $this->tool->handle_tool_call( $input_params );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 999, $result['job_id'] );
		$this->assertSame( 'image_generation', $result['tool_name'] );

		remove_filter( 'wp_get_ability', $filter, 10 );
	}

	/**
	 * Test handle_tool_call returns tool_name in result.
	 */
	public function test_handle_tool_call_returns_tool_name(): void {
		$mock_ability = $this->createMock( \stdClass::class );
		$mock_ability->method( 'execute' )
			->willReturn( [
				'success' => true,
				'pending' => true,
				'job_id' => 111
			] );

		$filter = function( $ability, $ability_name ) use ( $mock_ability ) {
			if ( 'datamachine/generate-image' === $ability_name ) {
				return $mock_ability;
			}
			return $ability;
		};
		add_filter( 'wp_get_ability', $filter, 10, 2 );

		$result = $this->tool->handle_tool_call( [ 'prompt' => 'Test image' ] );

		$this->assertSame( 'image_generation', $result['tool_name'] );

		remove_filter( 'wp_get_ability', $filter, 10 );
	}
}