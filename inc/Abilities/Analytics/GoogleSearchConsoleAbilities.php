<?php
/**
 * Google Search Console Abilities
 *
 * Primitive ability for Google Search Console Search Analytics API.
 * All GSC data — tools, CLI, REST, chat — flows through this ability.
 *
 * @package DataMachine\Abilities\Analytics
 * @since 0.25.0
 */

namespace DataMachine\Abilities\Analytics;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\HttpClient;

defined( 'ABSPATH' ) || exit;

class GoogleSearchConsoleAbilities {

	/**
	 * Option key for storing GSC configuration.
	 *
	 * @var string
	 */
	const CONFIG_OPTION = 'datamachine_gsc_config';

	/**
	 * Transient key for cached access token.
	 *
	 * @var string
	 */
	const TOKEN_TRANSIENT = 'datamachine_gsc_access_token';

	/**
	 * Action-to-dimensions mapping.
	 *
	 * @var array
	 */
	const ACTION_DIMENSIONS = [
		'query_stats'      => [ 'query' ],
		'page_stats'       => [ 'page' ],
		'query_page_stats' => [ 'query', 'page' ],
		'date_stats'       => [ 'date' ],
	];

	/**
	 * Default result limit.
	 *
	 * @var int
	 */
	const DEFAULT_LIMIT = 25;

	/**
	 * Maximum result limit.
	 *
	 * @var int
	 */
	const MAX_LIMIT = 25000;

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
				'datamachine/google-search-console',
				[
					'label'               => 'Google Search Console',
					'description'         => 'Fetch search analytics data from Google Search Console API',
					'category'            => 'datamachine',
					'input_schema'        => [
						'type'       => 'object',
						'required'   => [ 'action' ],
						'properties' => [
							'action'       => [
								'type'        => 'string',
								'description' => 'Analytics action: query_stats, page_stats, query_page_stats, date_stats.',
							],
							'site_url'     => [
								'type'        => 'string',
								'description' => 'Site URL (sc-domain: or https://). Defaults to configured site URL.',
							],
							'start_date'   => [
								'type'        => 'string',
								'description' => 'Start date in YYYY-MM-DD format (defaults to 28 days ago).',
							],
							'end_date'     => [
								'type'        => 'string',
								'description' => 'End date in YYYY-MM-DD format (defaults to 3 days ago for final data).',
							],
							'limit'        => [
								'type'        => 'integer',
								'description' => 'Row limit (default: 25, max: 25000).',
							],
							'url_filter'   => [
								'type'        => 'string',
								'description' => 'Filter results to URLs containing this string.',
							],
							'query_filter' => [
								'type'        => 'string',
								'description' => 'Filter results to queries containing this string.',
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
	 * Fetch stats from Google Search Console API.
	 *
	 * @param array $input Ability input.
	 * @return array Ability response.
	 */
	public static function fetchStats( array $input ): array {
		$action = sanitize_text_field( $input['action'] ?? '' );

		if ( empty( $action ) || ! isset( self::ACTION_DIMENSIONS[ $action ] ) ) {
			return [
				'success' => false,
				'error'   => 'Invalid action. Must be one of: ' . implode( ', ', array_keys( self::ACTION_DIMENSIONS ) ),
			];
		}

		$config = self::get_config();

		if ( empty( $config['service_account_json'] ) ) {
			return [
				'success' => false,
				'error'   => 'Google Search Console not configured. Add service account JSON in Settings.',
			];
		}

		$service_account = json_decode( $config['service_account_json'], true );

		if ( json_last_error() !== JSON_ERROR_NONE || empty( $service_account['client_email'] ) || empty( $service_account['private_key'] ) ) {
			return [
				'success' => false,
				'error'   => 'Invalid service account JSON. Ensure it contains client_email and private_key.',
			];
		}

		$access_token = self::get_access_token( $service_account );

		if ( is_wp_error( $access_token ) ) {
			return [
				'success' => false,
				'error'   => 'Failed to authenticate: ' . $access_token->get_error_message(),
			];
		}

		$site_url   = ! empty( $input['site_url'] ) ? sanitize_text_field( $input['site_url'] ) : ( $config['site_url'] ?? '' );
		$start_date = ! empty( $input['start_date'] ) ? sanitize_text_field( $input['start_date'] ) : gmdate( 'Y-m-d', strtotime( '-28 days' ) );
		$end_date   = ! empty( $input['end_date'] ) ? sanitize_text_field( $input['end_date'] ) : gmdate( 'Y-m-d', strtotime( '-3 days' ) );
		$limit      = ! empty( $input['limit'] ) ? min( (int) $input['limit'], self::MAX_LIMIT ) : self::DEFAULT_LIMIT;
		$dimensions = self::ACTION_DIMENSIONS[ $action ];

		if ( empty( $site_url ) ) {
			return [
				'success' => false,
				'error'   => 'No site URL configured or provided.',
			];
		}

		$request_body = [
			'startDate'  => $start_date,
			'endDate'    => $end_date,
			'dimensions' => $dimensions,
			'rowLimit'   => $limit,
			'dataState'  => 'final',
		];

		// Build dimension filter groups if filters provided.
		$filters = [];

		if ( ! empty( $input['url_filter'] ) ) {
			$filters[] = [
				'dimension'  => 'page',
				'operator'   => 'contains',
				'expression' => sanitize_text_field( $input['url_filter'] ),
			];
		}

		if ( ! empty( $input['query_filter'] ) ) {
			$filters[] = [
				'dimension'  => 'query',
				'operator'   => 'contains',
				'expression' => sanitize_text_field( $input['query_filter'] ),
			];
		}

		if ( ! empty( $filters ) ) {
			$request_body['dimensionFilterGroups'] = [
				[
					'groupType' => 'and',
					'filters'   => $filters,
				],
			];
		}

		$encoded_site_url = rawurlencode( $site_url );
		$api_url          = "https://www.googleapis.com/webmasters/v3/sites/{$encoded_site_url}/searchAnalytics/query";

		$result = HttpClient::post(
			$api_url,
			[
				'timeout' => 30,
				'headers' => [
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( $request_body ),
				'context' => 'Google Search Console Ability',
			]
		);

		if ( ! $result['success'] ) {
			return [
				'success' => false,
				'error'   => 'Failed to connect to Google Search Console API: ' . ( $result['error'] ?? 'Unknown error' ),
			];
		}

		$data = json_decode( $result['data'], true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return [
				'success' => false,
				'error'   => 'Failed to parse Google Search Console API response.',
			];
		}

		if ( ! empty( $data['error'] ) ) {
			$error_message = $data['error']['message'] ?? 'Unknown API error';
			return [
				'success' => false,
				'error'   => 'GSC API error: ' . $error_message,
			];
		}

		$rows = $data['rows'] ?? [];

		return [
			'success'       => true,
			'action'        => $action,
			'results_count' => count( $rows ),
			'results'       => $rows,
		];
	}

	/**
	 * Get an OAuth2 access token using service account JWT flow.
	 *
	 * @param array $service_account Parsed service account JSON.
	 * @return string|\WP_Error Access token or error.
	 */
	private static function get_access_token( array $service_account ) {
		$cached = get_transient( self::TOKEN_TRANSIENT );

		if ( ! empty( $cached ) ) {
			return $cached;
		}

		$header = self::base64url_encode( wp_json_encode( [ 'alg' => 'RS256', 'typ' => 'JWT' ] ) );
		$now    = time();
		$claims = self::base64url_encode( wp_json_encode( [
			'iss'   => $service_account['client_email'],
			'scope' => 'https://www.googleapis.com/auth/webmasters.readonly',
			'aud'   => 'https://oauth2.googleapis.com/token',
			'iat'   => $now,
			'exp'   => $now + 3600,
		] ) );

		$unsigned = $header . '.' . $claims;

		$sign_result = openssl_sign( $unsigned, $signature, $service_account['private_key'], 'SHA256' );

		if ( ! $sign_result ) {
			return new \WP_Error( 'gsc_jwt_sign_failed', 'Failed to sign JWT. Check private key in service account JSON.' );
		}

		$jwt = $unsigned . '.' . self::base64url_encode( $signature );

		$response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			[
				'timeout' => 15,
				'body'    => [
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $jwt,
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['access_token'] ) ) {
			$error_desc = $body['error_description'] ?? ( $body['error'] ?? 'Unknown token error' );
			return new \WP_Error( 'gsc_token_failed', 'Failed to get access token: ' . $error_desc );
		}

		set_transient( self::TOKEN_TRANSIENT, $body['access_token'], 3500 );

		return $body['access_token'];
	}

	/**
	 * Base64url encode (RFC 7515).
	 *
	 * @param string $data Data to encode.
	 * @return string Base64url encoded string.
	 */
	private static function base64url_encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Check if Google Search Console is configured.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		$config = self::get_config();
		return ! empty( $config['service_account_json'] );
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
