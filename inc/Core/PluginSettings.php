<?php
/**
 * Plugin Settings Accessor
 *
 * Centralized access point for datamachine_settings option.
 * Provides caching and type-safe getters for plugin-wide settings.
 *
 * @package DataMachine\Core
 * @since 0.2.10
 */

namespace DataMachine\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PluginSettings {

	private static ?array $cache = null;

	/**
	 * Get all plugin settings.
	 *
	 * @return array
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			self::$cache = get_option( 'datamachine_settings', array() );
		}
		return self::$cache;
	}

	/**
	 * Get a specific setting value.
	 *
	 * @param string $key     Setting key
	 * @param mixed  $default Default value if key not found
	 * @return mixed
	 */
	public static function get( string $key, mixed $default = null ): mixed {
		$settings = self::all();
		return $settings[ $key ] ?? $default;
	}

	/**
	 * Clear the settings cache.
	 * Called automatically when datamachine_settings option is updated.
	 *
	 * @return void
	 */
	/**
	 * Get provider and model for a specific agent type.
	 *
	 * Resolution order:
	 * 1. Agent-specific override from agent_models setting
	 * 2. Global default_provider / default_model
	 * 3. Empty strings
	 *
	 * @param string $agent_type Agent type: 'chat', 'pipeline', 'system'.
	 * @return array{ provider: string, model: string }
	 */
	public static function getAgentModel( string $agent_type ): array {
		$agent_models = self::get( 'agent_models', array() );
		$agent_config = $agent_models[ $agent_type ] ?? array();
		$provider     = ! empty( $agent_config['provider'] ) ? $agent_config['provider'] : self::get( 'default_provider', '' );
		$model        = ! empty( $agent_config['model'] ) ? $agent_config['model'] : self::get( 'default_model', '' );

		return array(
			'provider' => $provider,
			'model'    => $model,
		);
	}

	/**
	 * Get the list of known agent types.
	 *
	 * @return array Array of agent type definitions with id, label, and description.
	 */
	public static function getAgentTypes(): array {
		return array(
			array(
				'id'          => 'chat',
				'label'       => __( 'Chat Agent', 'data-machine' ),
				'description' => __( 'Interactive chat conversations. Benefits from capable models for complex reasoning.', 'data-machine' ),
			),
			array(
				'id'          => 'pipeline',
				'label'       => __( 'Pipeline Agent', 'data-machine' ),
				'description' => __( 'Structured workflow execution. Operates within defined steps — efficient models work well.', 'data-machine' ),
			),
			array(
				'id'          => 'system',
				'label'       => __( 'System Agent', 'data-machine' ),
				'description' => __( 'Background tasks like alt text generation and issue creation.', 'data-machine' ),
			),
		);
	}

	/**
	 * Clear the settings cache.
	 *
	 * @return void
	 */
	public static function clearCache(): void {
		self::$cache = null;
	}
}
