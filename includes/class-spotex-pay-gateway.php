<?php

defined('ABSPATH') || exit;

/**
 * The payment method: at checkout, a SpotEx Pay checkout session for the
 * order's total in the shop's currency, and the buyer is sent to pay it.
 * The order is marked paid by the signed webhook — never by the buyer coming
 * back — or, if the webhook is late, by asking SpotEx from the server when
 * they do.
 */
class SpotEx_Pay_Gateway extends WC_Payment_Gateway
{
    const ID = 'spotex_pay';

    public function __construct()
    {
        $this->id = self::ID;
        $this->method_title = __('SpotEx Pay', 'spotex-pay-for-woocommerce');
        $this->method_description = __('Crypto at checkout: buyers pay from any wallet or a SpotEx balance, and the money lands in your SpotEx merchant wallet.', 'spotex-pay-for-woocommerce');
        $this->has_fields = false;
        $this->supports = ['products'];
        $this->init_form_fields();
        $this->init_settings();
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_thankyou_' . $this->id, [$this, 'check_on_return']);
        add_action('admin_notices', [$this, 'admin_notices']);
    }

    public function init_form_fields()
    {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable', 'spotex-pay-for-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Take crypto with SpotEx Pay', 'spotex-pay-for-woocommerce'),
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Title', 'spotex-pay-for-woocommerce'),
                'type' => 'text',
                'default' => __('Crypto (SpotEx Pay)', 'spotex-pay-for-woocommerce'),
                'desc_tip' => __('What buyers see at checkout.', 'spotex-pay-for-woocommerce'),
            ],
            'description' => [
                'title' => __('Description', 'spotex-pay-for-woocommerce'),
                'type' => 'textarea',
                'default' => __('Pay with crypto from any wallet, or from your SpotEx balance.', 'spotex-pay-for-woocommerce'),
            ],
            'api_key' => [
                'title' => __('Secret key', 'spotex-pay-for-woocommerce'),
                'type' => 'password',
                'description' => __('From SpotEx Merchant Center → Developers: sk_live_… to take real payments, or sk_test_… to try the plugin (test payments never mark an order paid).', 'spotex-pay-for-woocommerce'),
            ],
            'webhook_secret' => [
                'title' => __('Webhook signing secret', 'spotex-pay-for-woocommerce'),
                'type' => 'password',
                'description' => sprintf(
                    /* translators: %s: the shop's webhook address */
                    __('In Merchant Center → Developers, set the webhook address to %s and paste the signing secret (whsec_…) here.', 'spotex-pay-for-woocommerce'),
                    '<code>' . esc_html(rest_url('spotex-pay/v1/webhook')) . '</code>'
                ),
            ],
            'debug' => [
                'title' => __('Log', 'spotex-pay-for-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Log SpotEx Pay events (WooCommerce → Status → Logs). Keys are never logged.', 'spotex-pay-for-woocommerce'),
                'default' => 'no',
            ],
        ];
    }

    private function key(): string
    {
        return trim((string) $this->get_option('api_key'));
    }

    private function client(): SpotEx_Pay_Client
    {
        return new SpotEx_Pay_Client($this->key());
    }

    private function log(string $message): void
    {
        if ($this->get_option('debug') === 'yes' && function_exists('wc_get_logger')) {
            wc_get_logger()->info($message, ['source' => 'spotex-pay']);
        }
    }

    public function is_available()
    {
        $key = $this->key();
        return parent::is_available()
            && (strpos($key, 'sk_live_') === 0 || strpos($key, 'sk_test_') === 0)
            && SpotEx_Pay_Orders::supports_currency(get_woocommerce_currency());
    }

    public function admin_notices()
    {
        if ($this->enabled !== 'yes' || !current_user_can('manage_woocommerce')) {
            return;
        }
        if (strpos($this->key(), 'sk_test_') === 0) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('SpotEx Pay is in test mode: buyers can only make test payments, which never mark an order paid. Use a live key (sk_live_…) to take real payments.', 'spotex-pay-for-woocommerce') . '</p></div>';
        }
        if (!SpotEx_Pay_Orders::supports_currency(get_woocommerce_currency())) {
            echo '<div class="notice notice-error"><p>' . esc_html(sprintf(__('SpotEx Pay does not take prices in %s, so it is hidden at checkout.', 'spotex-pay-for-woocommerce'), get_woocommerce_currency())) . '</p></div>';
        }
        if (trim((string) $this->get_option('webhook_secret')) === '') {
            echo '<div class="notice notice-warning"><p>' . esc_html__('SpotEx Pay has no webhook signing secret: orders are marked paid only when buyers come back to your shop. Set the webhook in Merchant Center → Developers.', 'spotex-pay-for-woocommerce') . '</p></div>';
        }
    }

    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        $currency = $order->get_currency();
        try {
            if (!SpotEx_Pay_Orders::supports_currency($currency)) {
                throw new InvalidArgumentException(sprintf('SpotEx Pay does not take %s.', $currency));
            }
            $price = SpotEx_Pay_Orders::price((string) $order->get_total(), $currency);
            $session = $this->client()->create_session(
                [
                    'price' => $price,
                    'currency' => $currency,
                    // The order's number only: a shop name can hold a web address, which SpotEx refuses here.
                    'item' => sprintf(__('Order %s', 'spotex-pay-for-woocommerce'), SpotEx_Pay_Orders::order_ref((string) $order->get_order_number())),
                    // The order's id, which the webhook finds it by; the number above is what people read.
                    'order_id' => (string) $order->get_id(),
                    'return_url' => $this->get_return_url($order),
                ],
                SpotEx_Pay_Orders::idempotency_key((int) $order->get_id(), $price, $currency)
            );
        } catch (SpotEx_Pay_Error $e) {
            $this->log("Order {$order_id}: could not make a checkout session: {$e->error_code} {$e->getMessage()}");
            wc_add_notice(sprintf(__('Crypto payment could not be started: %s', 'spotex-pay-for-woocommerce'), $e->getMessage()), 'error');
            return ['result' => 'failure'];
        } catch (InvalidArgumentException $e) {
            wc_add_notice(sprintf(__('Crypto payment could not be started: %s', 'spotex-pay-for-woocommerce'), $e->getMessage()), 'error');
            return ['result' => 'failure'];
        }
        $order->update_meta_data('_spotex_session_id', (string) $session['id']);
        $order->update_meta_data('_spotex_price', $price);
        $order->update_meta_data('_spotex_test', $this->client()->is_test_mode() ? 'yes' : 'no');
        $order->save();
        $order->add_order_note(sprintf(__('Sent to SpotEx Pay to pay %1$s %2$s (session %3$s).', 'spotex-pay-for-woocommerce'), $price, $currency, $session['id']));
        $this->log("Order {$order_id}: session {$session['id']}");
        return ['result' => 'success', 'redirect' => (string) $session['url']];
    }

    /** What an order keeps of its SpotEx session, for SpotEx_Pay_Orders::event_action. */
    private static function kept(WC_Order $order): array
    {
        return [
            'session_id' => (string) $order->get_meta('_spotex_session_id'),
            'price' => (string) $order->get_meta('_spotex_price'),
            'currency' => $order->get_currency(),
            'test' => $order->get_meta('_spotex_test') === 'yes',
        ];
    }

    /** Does what a webhook, or the check on return, decided for an order. Paid once: an order already paid is left alone. */
    private static function apply(WC_Order $order, array $decision, string $transaction_id): void
    {
        [$action, $note] = $decision;
        if ($action === 'complete') {
            if ($order->needs_payment()) {
                $order->payment_complete($transaction_id);
                $order->add_order_note($note);
            }
        } elseif ($action === 'test') {
            if ($order->needs_payment() && !$order->has_status('on-hold')) {
                $order->update_status('on-hold', $note);
            }
        } elseif ($action === 'note') {
            $order->add_order_note($note);
        }
    }

    /** POST /wp-json/spotex-pay/v1/webhook: SpotEx's signed events. 2xx once handled, so SpotEx stops retrying. */
    public static function handle_webhook(WP_REST_Request $request)
    {
        $gateways = function_exists('WC') ? WC()->payment_gateways()->payment_gateways() : [];
        $gateway = $gateways[self::ID] ?? null;
        if (!$gateway instanceof self) {
            return new WP_REST_Response(['error' => 'not configured'], 503);
        }
        try {
            $event = SpotEx_Pay_Signature::construct_event($request->get_body(), $request->get_header('spotex-signature'), trim((string) $gateway->get_option('webhook_secret')));
        } catch (Exception $e) {
            $gateway->log('Webhook refused: ' . $e->getMessage());
            return new WP_REST_Response(['error' => 'bad signature'], 400);
        }
        $order_id = (string) ($event['data']['checkout']['order_id'] ?? '');
        $order = preg_match('/\A\d{1,20}\z/', $order_id) === 1 ? wc_get_order((int) $order_id) : null;
        if (!$order instanceof WC_Order || $order->get_payment_method() !== self::ID) {
            // Not an order of this shop's paid this way — payouts, tests, another site: nothing to do here.
            return new WP_REST_Response(['ok' => true], 200);
        }
        // event_action matches the event's session to the one this order was sent to, so an order id alone decides nothing.
        $decision = SpotEx_Pay_Orders::event_action($event, self::kept($order));
        $gateway->log("Webhook {$event['type']} for order {$order->get_id()}: {$decision[0]}");
        self::apply($order, $decision, (string) ($event['data']['checkout']['id'] ?? ''));
        return new WP_REST_Response(['ok' => true], 200);
    }

    /**
     * The buyer is back on the order's thank-you page: if it is not paid yet
     * (a late or missing webhook), ask SpotEx — from the server, with the key
     * — and treat the answer as a webhook would be.
     */
    public function check_on_return($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order || !$order->needs_payment()) {
            return;
        }
        $kept = self::kept($order);
        if ($kept['session_id'] === '') {
            return;
        }
        try {
            $session = $this->client()->retrieve_session($kept['session_id']);
            if (($session['status'] ?? '') !== 'paid' || empty($session['checkout_id'])) {
                return;
            }
            $checkout = $this->client()->retrieve_checkout((string) $session['checkout_id']);
        } catch (SpotEx_Pay_Error $e) {
            $this->log("Order {$order_id}: could not ask SpotEx on return: {$e->error_code}");
            return;
        }
        $event = ['type' => 'checkout.paid', 'livemode' => (bool) ($checkout['livemode'] ?? false), 'data' => ['checkout' => $checkout]];
        self::apply($order, SpotEx_Pay_Orders::event_action($event, $kept), (string) ($checkout['id'] ?? ''));
    }
}
