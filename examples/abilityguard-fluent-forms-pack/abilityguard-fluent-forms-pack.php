<?php
/**
 * Plugin Name:       AbilityGuard Fluent Forms Pack (demo)
 * Plugin URI:        https://github.com/ibrahimhajjaj/abilityguard
 * Description:       Demo: a Fluent-Forms-style ability showing snapshot limitations on create operations. Requires AbilityGuard.
 * Version:           0.2.0-dev
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Author:            Ibrahim Hajjaj
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ag-ff-pack
 *
 * @package AgFfPack
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Register the acme-forms ability category.
 *
 * @param \WP_Ability_Categories_Registry $registry Categories registry instance.
 *
 * @return void
 */
function ag_ff_pack_register_category( \WP_Ability_Categories_Registry $registry ): void {
	if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( 'acme-forms' ) ) {
		return;
	}
	$registry->register(
		'acme-forms',
		array(
			'label'       => 'Acme Forms',
			'description' => 'Abilities that manage Acme Forms.',
		)
	);
}
add_action( 'wp_abilities_api_categories_init', 'ag_ff_pack_register_category' );

/**
 * Register the acme-forms/duplicate-form ability.
 *
 * @param \WP_Abilities_Registry $registry Abilities registry instance.
 *
 * @return void
 */
function ag_ff_pack_register_abilities( \WP_Abilities_Registry $registry ): void {
	$registry->register(
		'acme-forms/duplicate-form',
		array(
			'label'               => 'Duplicate form',
			'description'         => 'Clones an acme_form post and records the timestamp option.',
			'category'            => 'acme-forms',
			'permission_callback' => static function (): bool {
				return current_user_can( 'manage_options' );
			},
			'execute_callback'    => static function ( mixed $input ): mixed {
				$form_id = (int) ( $input['form_id'] ?? 0 );

				$source = get_post( $form_id );
				if ( ! $source instanceof \WP_Post ) {
					return new \WP_Error(
						'acme_forms_not_found',
						'Form post not found.',
						array( 'form_id' => $form_id )
					);
				}

				$new_id = wp_insert_post(
					array(
						'post_title'  => $source->post_title . ' (copy)',
						'post_type'   => $source->post_type,
						'post_status' => 'draft',
						'post_author' => (int) $source->post_author,
					),
					true
				);

				if ( is_wp_error( $new_id ) ) {
					return $new_id;
				}

				update_option( 'acme_forms_last_duplicated_at', gmdate( 'Y-m-d H:i:s' ) );

				return array(
					'original_id' => $form_id,
					'new_id'      => $new_id,
				);
			},
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'form_id' => array( 'type' => 'integer' ),
				),
				'required'   => array( 'form_id' ),
			),
			'safety'              => array(
				'destructive' => true,
				'snapshot'    => static function ( mixed $input ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
					// Capture the timestamp option so rollback can revert it.
					// We intentionally do NOT include the new post id in post_meta
					// because the post does not yet exist at snapshot time.
					// See README for the rollback limitation this creates.
					return array(
						'options'   => array( 'acme_forms_last_duplicated_at' ),
						'post_meta' => array(),
					);
				},
			),
		)
	);
}
add_action( 'wp_abilities_api_init', 'ag_ff_pack_register_abilities' );
