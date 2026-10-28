<?php
/**
 * Plugin Name:          SpotEx Pay for WooCommerce
 * Plugin URI:           https://spotex.trade/merchants
 * Description:          Take crypto at checkout with SpotEx Pay. Buyers pay from any wallet or a SpotEx balance; the money lands in your SpotEx merchant wallet.
 * Version:              0.1.0
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * Author:               SpotEx
 * Author URI:           https://spotex.trade
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          spotex-pay-for-woocommerce
 * WC requires at least: 7.0
 * WC tested up to:      9.4
 */

defined('ABSPATH') || exit;

define('SPOTEX_PAY_WC_VERSION', '0.1.0');
define('SPOTEX_PAY_WC_FILE', __FILE__);

require_once __DIR__ . '/includes/class-spotex-pay-signature.php';
require_once __DIR__ . '/includes/class-spotex-pay-client.php';
require_once __DIR__ . '/includes/class-spotex-pay-orders.php';

// High-performance order storage and the block checkout: both supported.
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', SPOTEX_PAY_WC_FILE, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', SPOTEX_PAY_WC_FILE, true);
    }
});

add_action('plugins_loaded', function () {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }
    require_once __DIR__ . '/includes/class-spotex-pay-gateway.php';
    add_filter('woocommerce_payment_gateways', function ($gateways) {
        $gateways[] = 'SpotEx_Pay_Gateway';
        return $gateways;
    });
});

add_action('woocommerce_blocks_loaded', function () {
    if (!class_exists('\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        return;
    }
    require_once __DIR__ . '/includes/class-spotex-pay-blocks.php';
    add_action('woocommerce_blocks_payment_method_type_registration', function ($registry) {
        $registry->register(new SpotEx_Pay_Blocks());
    });
});

// Where SpotEx tells the shop an order was paid: https://your-shop/wp-json/spotex-pay/v1/webhook
add_action('rest_api_init', function () {
    if (!class_exists('SpotEx_Pay_Gateway')) {
        return; // WooCommerce is not active.
    }
    register_rest_route('spotex-pay/v1', '/webhook', [
        'methods' => 'POST',
        'callback' => ['SpotEx_Pay_Gateway', 'handle_webhook'],
        // Anyone may call it: the signature, checked inside, is what decides.
        'permission_callback' => '__return_true',
    ]);
});

add_filter('plugin_action_links_' . plugin_basename(SPOTEX_PAY_WC_FILE), function ($links) {
    $url = admin_url('admin.php?page=wc-settings&tab=checkout&section=spotex_pay');
    array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'spotex-pay-for-woocommerce') . '</a>');
    return $links;
});
