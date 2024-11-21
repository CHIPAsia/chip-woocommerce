<?php

/**
 * Plugin Name: CHIP for WooCommerce (CC Only)
 * Plugin URI: https://wordpress.org/plugins/chip-for-woocommerce/
 * Description: CHIP - Digital Finance Platform
 * Version: 999
 * Author: Chip In Sdn Bhd
 * Author URI: https://www.chip-in.asia
 * Requires PHP: 7.1
 * Requires at least: 4.7
 *
 * WC requires at least: 5.1
 * WC tested up to: 8.8
 * Requires Plugins: woocommerce
 *
 * Copyright: © 2024 CHIP
 * License: GNU General Public License v3.0
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

/**
 * Resources:
 * 
 * #1 https://woocommerce.com/document/woocommerce-payment-gateway-plugin-base/
 * #2 https://woocommerce.com/document/payment-gateway-api/
 * #3 https://woocommerce.com/document/subscriptions/develop/payment-gateway-integration/
 * #4 https://github.com/woocommerce/woocommerce/wiki/Payment-Token-API#adding-payment-token-api-support-to-your-gateway
 * #5 https://stackoverflow.com/questions/22843504/how-can-i-get-customer-details-from-an-order-in-woocommerce
 * #6 https://stackoverflow.com/questions/16813220/how-can-i-override-inline-styles-with-external-css
 * #7 https://developer.woocommerce.com/2022/07/07/exposing-payment-options-in-the-checkout-block/
 * #8 https://github.com/woocommerce/woocommerce-blocks/blob/trunk/docs/third-party-developers/extensibility/checkout-payment-methods/payment-method-integration.md
 * #9 https://github.com/woocommerce/woocommerce-gateway-dummy/issues/12#issuecomment-1464898655
 * #10 https://developer.woocommerce.com/2022/05/20/hiding-shipping-and-payment-options-in-the-cart-and-checkout-blocks/
 */

if ( ! defined( 'ABSPATH' ) ) {
	die;
} // Cannot access directly.

class Chip_Woocommerce {

	private static $_instance;

	public static function get_instance() {
		if ( static::$_instance == null ) {
			static::$_instance = new static();
		}

		return static::$_instance;
	}

	public function __construct() {
		$this->define();
		$this->includes();
		$this->add_filters();
		$this->add_actions();
	}

	public function define() {
		define( 'WC_CHIP_MODULE_VERSION', 'v1.6.5' );
		define( 'WC_CHIP_FILE', __FILE__ );
		define( 'WC_CHIP_BASENAME', plugin_basename( WC_CHIP_FILE ) );
		define( 'WC_CHIP_URL', plugin_dir_url( WC_CHIP_FILE ) );
	}

	public function includes() {
		$includes_dir = plugin_dir_path( WC_CHIP_FILE ) . 'includes/';
		include $includes_dir . 'class-wc-api.php';
		include $includes_dir . 'class-wc-api-fpx.php';
		include $includes_dir . 'class-wc-logger.php';
		include $includes_dir . 'class-wc-gateway-chip.php';
		include $includes_dir . 'class-wc-migration.php';
		include $includes_dir . 'class-wc-queue.php';

		if ( ! defined( 'DISABLE_CLONE_WC_GATEWAY_CHIP' ) ) {
			include $includes_dir . 'clone-wc-gateway-chip.php';
		}

		if ( is_admin() ) {
			include $includes_dir . 'class-wc-bulk-action.php';
			include $includes_dir . 'class-wc-receipt-link.php';
		}
	}

	public function add_filters() {
		add_filter( 'woocommerce_payment_gateways', array( $this, 'add_gateways' ) );
		add_filter( 'plugin_action_links_' . WC_CHIP_BASENAME, array( $this, 'setting_link' ) );
	}

	public function add_actions() {
		add_action( 'woocommerce_payment_token_deleted', array( $this, 'payment_token_deleted' ), 10, 2 );
		add_action( 'woocommerce_blocks_loaded', array( $this, 'block_support' ) );
	}

	public function payment_token_deleted( $token_id, $token ) {
		$wc_gateway_chip = static::get_chip_gateway_class( $token->get_gateway_id() );

		if ( ! $wc_gateway_chip ) {
			return;
		}

		$wc_gateway_chip->payment_token_deleted( $token_id, $token );
	}

	public static function get_chip_gateway_class( $gateway_id ) {
		$wc_payment_gateway = WC_Payment_Gateways::instance();

		$pgs = $wc_payment_gateway->payment_gateways();

		if ( isset( $pgs[ $gateway_id ] ) and is_a( $pgs[ $gateway_id ], 'WC_Gateway_Chip' ) ) {
			return $pgs[ $gateway_id ];
		}

		return false;
	}

	public function add_gateways( $methods ) {
		$methods[] = WC_Gateway_Chip::class;

		return $methods;
	}

	public function setting_link( $links ) {
		$url_params = array(
			'page' => 'wc-settings',
			'tab' => 'checkout',
		);

		if ( defined( 'DISABLE_CLONE_WC_GATEWAY_CHIP' ) ) {
			$url_params['section'] = 'wc_gateway_chip';
		}

		$url = add_query_arg( $url_params, admin_url( 'admin.php' ) );

		$new_links = array(
			'settings' => sprintf( '<a href="%1$s">%2$s</a>', $url, esc_html__( 'Settings', 'chip-for-woocommerce' ) )
		);

		return array_merge( $new_links, $links );
	}

	public static function load() {
		if ( ! class_exists( 'WooCommerce' ) or ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		static::get_instance();
	}

	public function block_support() {
		if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			include plugin_dir_path( WC_CHIP_FILE ) . 'includes/blocks/class-wc-gateway-chip-blocks.php';
			include plugin_dir_path( WC_CHIP_FILE ) . 'includes/blocks/clone-wc-gateway-chip-blocks.php';
			add_action(
				'woocommerce_blocks_payment_method_type_registration',
				function (Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
					$payment_method_registry->register( new WC_Gateway_Chip_Blocks_Support );
					$payment_method_registry->register( new WC_Gateway_Chip_2_Blocks_Support );
					$payment_method_registry->register( new WC_Gateway_Chip_3_Blocks_Support );
					$payment_method_registry->register( new WC_Gateway_Chip_4_Blocks_Support );
					$payment_method_registry->register( new WC_Gateway_Chip_5_Blocks_Support );
					$payment_method_registry->register( new WC_Gateway_Chip_6_Blocks_Support );
				}
			);
		}
	}
}

add_action( 'plugins_loaded', array( 'Chip_Woocommerce', 'load' ) );

/**
 * Declare plugin compatibility with WooCommerce HPOS.
 *
 * @since 1.3.2
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);
