<?php
/**
 * Resolve Term Ability
 *
 * Single source of truth for taxonomy term resolution.
 * All code paths that need to find or create terms should use this ability.
 *
 * @package DataMachine\Abilities\Taxonomy
 */

namespace DataMachine\Abilities\Taxonomy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ResolveTermAbility {

	private const SYSTEM_TAXONOMIES = array( 'post_format', 'nav_menu', 'link_category' );

	public function __construct() {
		add_action( 'wp_abilities_init', array( $this, 'register' ) );
	}

	public function register(): void {
		wp_register_ability(
			'datamachine/resolve-term',
			array(
				'label'        => __( 'Resolve Term', 'data-machine' ),
				'description'  => __( 'Find or create a taxonomy term by ID, name, or slug. Single source of truth for term resolution.', 'data-machine' ),
				'category'     => 'datamachine',
				'callback'     => array( $this, 'execute' ),
				'input_schema' => array(
					'identifier' => array(
						'type'        => 'string',
						'required'    => true,
						'description' => 'Term identifier - can be numeric ID, name, or slug',
					),
					'taxonomy'   => array(
						'type'        => 'string',
						'required'    => true,
						'description' => 'Taxonomy name (category, post_tag, etc.)',
					),
					'create'     => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Create term if not found',
					),
				),
				'output_schema' => array(
					'success'  => array( 'type' => 'boolean' ),
					'term_id'  => array( 'type' => 'integer' ),
					'name'     => array( 'type' => 'string' ),
					'slug'     => array( 'type' => 'string' ),
					'taxonomy' => array( 'type' => 'string' ),
					'created'  => array( 'type' => 'boolean' ),
					'error'    => array( 'type' => 'string' ),
				),
			)
		);
	}

	/**
	 * Execute term resolution.
	 *
	 * Resolution order:
	 * 1. If numeric, try get_term() by ID
	 * 2. Try get_term_by('name')
	 * 3. Try get_term_by('slug')
	 * 4. If create=true and not found, create via wp_insert_term()
	 *
	 * @param array $input Input with identifier, taxonomy, create flag.
	 * @return array Success with term data or error.
	 */
	public function execute( array $input ): array {
		$identifier = trim( (string) ( $input['identifier'] ?? '' ) );
		$taxonomy   = trim( (string) ( $input['taxonomy'] ?? '' ) );
		$create     = (bool) ( $input['create'] ?? false );

		// Validate inputs.
		if ( empty( $identifier ) ) {
			return $this->error_response( 'identifier is required' );
		}

		if ( empty( $taxonomy ) ) {
			return $this->error_response( 'taxonomy is required' );
		}

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return $this->error_response( "Taxonomy '{$taxonomy}' does not exist" );
		}

		if ( in_array( $taxonomy, self::SYSTEM_TAXONOMIES, true ) ) {
			return $this->error_response( "Cannot resolve terms in system taxonomy '{$taxonomy}'" );
		}

		// 1. Check if numeric - try by ID first.
		if ( is_numeric( $identifier ) ) {
			$term = get_term( absint( $identifier ), $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				return $this->success_response( $term, false );
			}
		}

		// 2. Try by name (exact match).
		$term = get_term_by( 'name', $identifier, $taxonomy );
		if ( $term ) {
			return $this->success_response( $term, false );
		}

		// 3. Try by slug.
		$term = get_term_by( 'slug', sanitize_title( $identifier ), $taxonomy );
		if ( $term ) {
			return $this->success_response( $term, false );
		}

		// 4. Not found - create if requested.
		if ( $create ) {
			$result = wp_insert_term( $identifier, $taxonomy );
			if ( is_wp_error( $result ) ) {
				return $this->error_response( $result->get_error_message() );
			}
			$term = get_term( $result['term_id'], $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				do_action(
					'datamachine_log',
					'info',
					'Created new taxonomy term via resolve-term ability',
					array(
						'term_id'    => $term->term_id,
						'name'       => $term->name,
						'taxonomy'   => $taxonomy,
						'identifier' => $identifier,
					)
				);
				return $this->success_response( $term, true );
			}
		}

		return $this->error_response( "Term '{$identifier}' not found in taxonomy '{$taxonomy}'" );
	}

	/**
	 * Build success response.
	 *
	 * @param \WP_Term $term    Resolved term.
	 * @param bool     $created Whether term was created.
	 * @return array
	 */
	private function success_response( \WP_Term $term, bool $created ): array {
		return array(
			'success'  => true,
			'term_id'  => $term->term_id,
			'name'     => $term->name,
			'slug'     => $term->slug,
			'taxonomy' => $term->taxonomy,
			'created'  => $created,
		);
	}

	/**
	 * Build error response.
	 *
	 * @param string $message Error message.
	 * @return array
	 */
	private function error_response( string $message ): array {
		return array(
			'success' => false,
			'error'   => $message,
		);
	}

	/**
	 * Static helper for internal use without going through abilities API.
	 *
	 * This is the method all internal code should call.
	 *
	 * @param string $identifier Term identifier (ID, name, or slug).
	 * @param string $taxonomy   Taxonomy name.
	 * @param bool   $create     Create if not found.
	 * @return array Result with success, term data, or error.
	 */
	public static function resolve( string $identifier, string $taxonomy, bool $create = false ): array {
		$instance = new self();
		return $instance->execute(
			array(
				'identifier' => $identifier,
				'taxonomy'   => $taxonomy,
				'create'     => $create,
			)
		);
	}
}
