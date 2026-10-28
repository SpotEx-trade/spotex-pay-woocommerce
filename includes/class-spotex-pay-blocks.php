<?php

defined('ABSPATH') || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/** SpotEx Pay in WooCommerce's block checkout, the default since WooCommerce 8.3. */
final class SpotEx_Pay_Blocks extends AbstractPaymentMethodType
{
    protected $name = 'spotex_pay';

    public function initialize()
    {
        $this->settings = get_option('woocommerce_spotex_pay_settings', []);
    }

    public function is_active()
    {
        $gateways = WC()->payment_gateways()->payment_gateways();
        return isset($gateways['spotex_pay']) && $gateways['spotex_pay']->is_available();
    }

    public function get_payment_method_script_handles()
    {
        wp_register_script(
            'spotex-pay-blocks',
            plugins_url('assets/js/blocks.js', SPOTEX_PAY_WC_FILE),
            ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities'],
            SPOTEX_PAY_WC_VERSION,
            true
        );
        return ['spotex-pay-blocks'];
    }

    public function get_payment_method_data()
    {
        return [
            'title' => $this->get_setting('title'),
            'description' => $this->get_setting('description'),
            'supports' => ['products'],
        ];
    }
}
