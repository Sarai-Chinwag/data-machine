<?php
/**
 * Settings Admin Page Filter Registration
 *
 * WordPress Settings API integration for Data Machine configuration.
 *
 * @package DataMachine\Core\Admin\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function datamachine_register_settings_admin_page_filters() {
	add_action( 'admin_init', 'datamachine_register_settings' );

	add_filter(
		'datamachine_admin_pages',
		function ( $pages ) {
			$pages['settings'] = array(
				'page_title' => __( 'Data Machine Settings', 'data-machine' ),
				'menu_title' => __( 'Settings', 'data-machine' ),
				'capability' => 'manage_options',
				'position'   => 100,
				'templates'  => DATAMACHINE_PATH . 'inc/Core/Admin/Settings/templates/',
				'assets'     => array(
					'css' => array(
						'datamachine-settings-page' => array(
							'file'  => 'inc/Core/Admin/Settings/assets/css/settings-page.css',
							'deps'  => array(),
							'media' => 'all',
						),
					),
					'js'  => array(
						'datamachine-settings-react' => array(
							'file'      => 'inc/Core/Admin/assets/build/settings-react.js',
							'deps'      => array( 'wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch' ),
							'in_footer' => true,
							'localize'  => array(
								'object' => 'dataMachineSettingsConfig',
								'data'   => array(
									'restNamespace' => 'datamachine/v1',
									'restNonce'     => wp_create_nonce( 'wp_rest' ),
									'adminUrl'      => admin_url(),
								),
							),
						),
					),
				),
			);
			return $pages;
		}
	);

	add_filter(
		'datamachine_render_template',
		function ( $content, $template_name, $data = array() ) {
			$settings_template_path = DATAMACHINE_PATH . 'inc/Core/Admin/Settings/templates/' . $template_name . '.php';
			if ( file_exists( $settings_template_path ) ) {
				ob_start();
				include $settings_template_path;
				return ob_get_clean();
			}
			return $content;
		},
		15,
		3
	);
}

function datamachine_register_settings() {
	register_setting(
		'datamachine_settings',
		'datamachine_settings',
		array(
			'sanitize_callback' => 'datamachine_sanitize_settings',
		)
	);
}

function datamachine_sanitize_settings( $input ) {
	// Seed with existing settings so fields not present in the current form
	// submission (e.g. agent_soul when saving from General tab) are preserved.
	$sanitized = get_option( 'datamachine_settings', array() );
	if ( ! is_array( $sanitized ) ) {
		$sanitized = array();
	}

	if ( ! isset( $input['enabled_tools'] ) || ! is_array( $input['enabled_tools'] ) ) {
		$input['enabled_tools'] = array();
	}

	if ( ! isset( $input['ai_provider_keys'] ) || ! is_array( $input['ai_provider_keys'] ) ) {
		$input['ai_provider_keys'] = array();
	}

	// Enabled tools (array-safe) — only update when saving from a tab that manages tools.
	// Use default_provider as sentinel for the agent tab.
	if ( isset( $input['enabled_tools'] ) || isset( $input['default_provider'] ) ) {
		$sanitized['enabled_tools'] = array();
		if ( ! empty( $input['enabled_tools'] ) && is_array( $input['enabled_tools'] ) ) {
			foreach ( $input['enabled_tools'] as $tool_id => $value ) {
				if ( $value ) {
					$sanitized['enabled_tools'][ sanitize_key( $tool_id ) ] = true;
				}
			}
		}

		// Auto-enable newly configured tools (opt-out pattern maintenance)
		$tool_manager     = new \DataMachine\Engine\AI\Tools\ToolManager();
		$opt_out_defaults = $tool_manager->get_opt_out_defaults();
		foreach ( $opt_out_defaults as $tool_id ) {
			if ( ! isset( $sanitized['enabled_tools'][ $tool_id ] ) ) {
				$sanitized['enabled_tools'][ $tool_id ] = true;
			}
		}
	}

	// Cleanup flag
	// Checkbox: use a hidden field sentinel to distinguish "unchecked" from "not on this tab".
	// The admin-tab.php form includes this field, so it's only set when saving from that tab.
	if ( isset( $input['cleanup_job_data_on_failure'] ) || isset( $input['file_retention_days'] ) ) {
		$sanitized['cleanup_job_data_on_failure'] = ! empty( $input['cleanup_job_data_on_failure'] );
	}

	// Agent Soul (structured identity sections).
	// Only overwrite when the form actually includes agent_soul fields,
	// so saving from other tabs doesn't wipe the soul.
	if ( isset( $input['agent_soul'] ) && is_array( $input['agent_soul'] ) ) {
		$soul_keys = array( 'identity', 'voice', 'rules', 'context' );
		$sanitized['agent_soul'] = array();
		foreach ( $soul_keys as $soul_key ) {
			$sanitized['agent_soul'][ $soul_key ] = '';
			if ( isset( $input['agent_soul'][ $soul_key ] ) ) {
				$sanitized['agent_soul'][ $soul_key ] = wp_kses_post( wp_unslash( $input['agent_soul'][ $soul_key ] ) );
			}
		}
	}

	// Legacy global system prompt (backward compat — read by AgentSoulDirective as fallback).
	if ( isset( $input['global_system_prompt'] ) ) {
		$sanitized['global_system_prompt'] = wp_kses_post( wp_unslash( $input['global_system_prompt'] ) );
	}

	// Site context toggle — only update if field is present in form submission.
	if ( isset( $input['site_context_enabled'] ) ) {
		$sanitized['site_context_enabled'] = ! empty( $input['site_context_enabled'] );
	}

	// Default AI provider and model — only update if fields are present.
	if ( isset( $input['default_provider'] ) ) {
		$sanitized['default_provider'] = sanitize_text_field( $input['default_provider'] );
	}

	if ( isset( $input['default_model'] ) ) {
		$sanitized['default_model'] = sanitize_text_field( $input['default_model'] );
	}

	// Handle AI provider API keys
	if ( ! empty( $input['ai_provider_keys'] ) && is_array( $input['ai_provider_keys'] ) ) {
		$provider_keys = array();
		foreach ( $input['ai_provider_keys'] as $provider => $key ) {
			$provider_keys[ sanitize_key( $provider ) ] = sanitize_text_field( $key );
		}

		// Save to AI HTTP Client library's storage
		if ( ! empty( $provider_keys ) ) {
			$all_keys     = apply_filters( 'chubes_ai_provider_api_keys', null );
			$updated_keys = array_merge( $all_keys, $provider_keys );
			apply_filters( 'chubes_ai_provider_api_keys', $updated_keys );
		}
	}

	// Sanitize file retention days (1-90 days range)
	if ( isset( $input['file_retention_days'] ) ) {
		$retention_days = absint( $input['file_retention_days'] );
		$sanitized['file_retention_days'] = ( $retention_days >= 1 && $retention_days <= 90 )
			? $retention_days
			: 7;
	}

	// Sanitize max turns (1-50 turns range)
	if ( isset( $input['max_turns'] ) ) {
		$max_turns = absint( $input['max_turns'] );
		$sanitized['max_turns'] = ( $max_turns >= 1 && $max_turns <= 50 )
			? $max_turns
			: 12;
	}

	return $sanitized;
}

add_action( 'init', 'datamachine_register_settings_admin_page_filters' );
