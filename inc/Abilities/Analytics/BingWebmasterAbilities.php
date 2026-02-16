<?php
/**
 * Bing Webmaster Tools Abilities
 *
 * Primitive ability for Bing Webmaster API analytics.
 * All Bing Webmaster data — tools, CLI, REST, chat — flows through this ability.
 *
 * @package DataMachine\Abilities\Analytics
 * @since 0.23.0
 */

namespace DataMachine\Abilities\Analytics;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\HttpClient;

defined( 'ABSPATH' ) || exit;

class BingWebmasterAbilities {

	/**
	 * Option key for storing Bing Webmaster configuration.
	 *
	 * @var string
	 */
	const CONFIG_OPTION = 'datamachine_bing_webmaster_config';

	/**
	 * API endpoint mapping for supported actions.
	 *
	 * @var array
	 */
	const ACTION_ENDPOINTS = [
		'query_stats'   => 'GetQueryStats',
		'traffic_stats' => 'GetRankAndTrafficStats',
		'page_stats'    => 'GetPageStats',
		'crawl_stats'   => 'GetCrawlStats',
	];

	/**
	 * Default result limit.
	 *
	 * @var int
	 */
	const DEFAULT_LIMIT = 20;

	private static bool $registered = false;

	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		if ( self::$registered ) {
			return;
		}

		$this->registerAbilities();
		self::$registered = true;
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/bing-webmaster',
				[
					'label'               => 'Bing Webmaster Tools',
					'description'         => 'Fetch search analytics data from Bing Webmaster Tools API',
					'category'            => 'datamachine',
					'input_schema'        => [
						'type'       => 'object',
						'required'   => [ 'action' ],
						'properties' => [
							'action'   => [
								'type'        => 'string',
								'description' => 'Analytics action: query_stats, traffic_stats, page_stats, crawl_stats.',
							],
							'site_url' => [
								'type'        => 'string',
								'description' => 'Site URL to query (defaults to configured site URL).',
							],
							'limit'    => [
								'type'        => 'integer',
								'description' => 'Maximum number of results to return (default: 20).',
							],
						],
					],
					'output_schema'       => [
						'type'       => 'object',
						'properties' => [
							'success'       => [ 'type' => 'boolean' ],
							'action'        => [ 'type' => 'string' ],
							'results_count' => [ 'type' => 'integer' ],
							'results'       => [ 'type' => 'array' ],
							'error'         => [ 'type' => 'string' ],
						],
					],
					'execute_callback'    => [ self::class, 'fetchStats' ],
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => [ 'show_in_rest' => false ],
				]
			);
		};

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Fetch stats from Bing Webmaster Tools API.
	 *
	 * @param array $input Ability input.
	 * @return array Ability response.
	 */
	public static function fetchStats( array $input ): array {
		$action = sanitize_text_field( $input['action'] ?? '' );

		if ( empty( $action ) || ! isset( self::ACTION_ENDPOINTS[ $action ] ) ) {
			return [
				'success' => false,
				'error'   => 'Invalid action. Must be one of: ' . implode( ', ', array_keys( self::ACTION_ENDPOINTS ) ),
			];
		}

		$config = self::get_config();

		if ( empty( $config['api_key'] ) ) {
			return [
				'success' => false,
				'error'   => 'Bing Webmaster Tools not configured. Add an API key in Settings.',
			];
		}

		$site_url = ! empty( $input['site_url'] ) ? sanitize_text_field( $input['site_url'] ) : ( $config['site_url'] ?? get_site_url() );
		$limit    = ! empty( $input['limit'] ) ? (int) $input['limit'] : self::DEFAULT_LIMIT;
		$endpoint = self::ACTION_ENDPOINTS[ $action ];

		$request_url = add_query_arg(
			[
				'apikey'  => $config['api_key'],
				'siteUrl' => $site_url,
			],
			'https://ssl.bing.com/webmaster/api.svc/json/' . $endpoint
		);

		$result = HttpClient::get(
			$request_url,
			[
				'timeout' => 15,
				'headers' => [
					'Accept' => 'application/json',
				],
				'context' => 'Bing Webmaster Tools Ability',
			]
		);

		if ( ! $result['success'] ) {
			return [
				'success' => false,
				'error'   => 'Failed to connect to Bing Webmaster API: ' . ( $result['error'] ?? 'Unknown error' ),
			];
		}

		$data = json_decode( $result['data'], true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return [
				'success' => false,
				'error'   => 'Failed to parse Bing Webmaster API response.',
			];
		}

		$results = $data['d'] ?? [];

		if ( is_array( $results ) && count( $results ) > $limit ) {
			$results = array_slice( $results, 0, $limit );
		}

		return [
			'success'       => true,
			'action'        => $action,
			'results_count' => is_array( $results ) ? count( $results ) : 0,
			'results'       => $results,
		];
	}

	/**
	 * Check if Bing Webmaster Tools is configured.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		$config = self::get_config();
		return ! empty( $config['api_key'] );
	}

	/**
	 * Get stored configuration.
	 *
	 * @return array
	 */
	public static function get_config(): array {
		return get_site_option( self::CONFIG_OPTION, [] );
	}
}
