<?php
/**
 * Plugin Name:       AbilityGuard WooCommerce Pack (demo)
 * Plugin URI:        https://github.com/ibrahimhajjaj/abilityguard
 * Description:       Demo: a WooCommerce-style ability with a complete safety config. Requires AbilityGuard.
 * Version:           0.2.0-dev
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Author:            Ibrahim Hajjaj
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ag-woo-pack
 *
 * @package AgWooPack
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Register the acme-shop ability category.
 *
 * @param \WP_Ability_Categories_Registry $registry Categories registry instance.
 *
 * @return void
 */
function ag_woo_pack_register_category( \WP_Ability_Categories_Registry $registry ): void {
	if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( 'acme-shop' ) ) {
		return;
	}
	$registry->register(
		'acme-shop',
		array(
			'label'       => 'Acme Shop',
			'description' => 'Abilities that manage Acme Shop products.',
		)
	);
}
add_action( 'wp_abilities_api_categories_init', 'ag_woo_pack_register_category' );

/**
 * Register the acme-shop/update-product-price ability.
 *
 * @param \WP_Abilities_Registry $registry Abilities registry instance.
 *
 * @return void
 */
function ag_woo_pack_register_abilities( \WP_Abilities_Registry $registry ): void {
	$registry->register(
		'acme-shop/update-product-price',
		array(
			'label'               => 'Update product price',
			'description'         => 'Sets _regular_price and _price on a product post.',
			'category'            => 'acme-shop',
			'permission_callback' => static function (): bool {
				return current_user_can( 'manage_options' );
			},
			'execute_callback'    => static function ( mixed $input ): array {
				$product_id    = (int) ( $input['product_id'] ?? 0 );
				$regular_price = (string) ( $input['regular_price'] ?? '' );

				update_post_meta( $product_id, '_regular_price', $regular_price );
				update_post_meta( $product_id, '_price', $regular_price );

				return array(
					'success'    => true,
					'product_id' => $product_id,
				);
			},
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'product_id'    => array( 'type' => 'integer' ),
					'regular_price' => array( 'type' => 'string' ),
				),
				'required'   => array( 'product_id', 'regular_price' ),
			),
			'safety'              => array(
				'destructive' => true,
				'snapshot'    => static function ( mixed $input ): array {
					$product_id = (int) ( $input['product_id'] ?? 0 );
					return array(
						'post_meta' => array(
							$product_id => array( '_price', '_regular_price' ),
						),
					);
				},
			),
		)
	);
}
add_action( 'wp_abilities_api_init', 'ag_woo_pack_register_abilities' );
