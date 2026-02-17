<?php
/**
 * Handler for the datamachine_log_manage action (management operations).
 *
 * @package DataMachine\Engine\Actions\Handlers
 * @since   0.11.0
 */

namespace DataMachine\Engine\Actions\Handlers;

/**
 * Log management handler — clear, cleanup, and level management.
 *
 * Separated from write operations so callers can invoke management
 * directly via static methods or through the datamachine_log_manage action.
 */
class LogManageHandler {

	/**
	 * Handle a log management operation via action hook.
	 *
	 * @param string     $operation Management operation (clear_all, cleanup, set_level).
	 * @param mixed      $param2    First parameter (varies by operation).
	 * @param mixed      $param3    Second parameter (varies by operation).
	 * @param mixed|null $result    Reference for operation result.
	 * @return mixed
	 */
	public static function handle( $operation, $param2 = null, $param3 = null, &$result = null ) {
		switch ( $operation ) {
			case 'clear_all':
				$result = self::clearAll();
				return $result;

			case 'cleanup':
				$result = self::cleanup( $param2, $param3 );
				return $result;

			case 'set_level':
				$result = self::setLevel( $param2, $param3 );
				return $result;
		}

		return null;
	}

	/**
	 * Clear all log files.
	 *
	 * @return bool True on success, false on failure.
	 */
	public static function clearAll() {
		if ( class_exists( 'WP_Ability' ) ) {
			$ability        = wp_get_ability( 'datamachine/clear-logs' );
			$ability_result = $ability->execute( array( 'agent_type' => 'all' ) );
			return is_wp_error( $ability_result ) ? false : $ability_result['success'];
		}

		return datamachine_clear_all_log_files();
	}

	/**
	 * Clean up log files by size and age limits.
	 *
	 * @param int $max_size_mb  Maximum log file size in MB. Default 10.
	 * @param int $max_age_days Maximum log age in days. Default 30.
	 * @return mixed Cleanup result.
	 */
	public static function cleanup( $max_size_mb = 10, $max_age_days = 30 ) {
		$max_size_mb  = $max_size_mb ?? 10;
		$max_age_days = $max_age_days ?? 30;

		return datamachine_cleanup_log_files( $max_size_mb, $max_age_days );
	}

	/**
	 * Set the log level for an agent type.
	 *
	 * @param string $agent_type Agent type identifier.
	 * @param string $level      Log level to set.
	 * @return mixed Set-level result.
	 */
	public static function setLevel( $agent_type, $level ) {
		return datamachine_set_log_level( $agent_type, $level );
	}
}
