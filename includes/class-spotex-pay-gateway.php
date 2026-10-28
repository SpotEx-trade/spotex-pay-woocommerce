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
        $this->enabled = $this->get_option('enabled', 'no') === 'yes' ? 'yes' : 'no';
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

    private function client(int $timeout = 30): SpotEx_Pay_Client
    {
        return new SpotEx_Pay_Client($this->key(), null, $timeout);
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
            $kept = self::kept($order);
            // Made with the other kind of key (test or live), it can't be asked about: a new one follows.
            if ($kept['session_id'] !== '' && $kept['test'] === $this->client()->is_test_mode()) {
                try {
                    $last = $this->client(10)->retrieve_session($kept['session_id']);
                } catch (SpotEx_Pay_Error $e) {
                    if ($e->status !== 404) {
                        throw $e;
                    }
                    $last = [];
                }
                // Sent before for this total and still open: back to the same page.
                if (($last['status'] ?? '') === 'open' && $kept['price'] === $price && $kept['currency'] === $currency && $order->get_meta('_spotex_session_url') !== '') {
                    return ['result' => 'success', 'redirect' => (string) $order->get_meta('_spotex_session_url')];
                }
                // Paid already, and the webhook not here yet: book it, rather than ask for the money again.
                if (($last['status'] ?? '') === 'paid' && !empty($last['checkout_id'])) {
                    $checkout = $this->client(10)->retrieve_checkout((string) $last['checkout_id']);
                    $event = ['type' => 'checkout.paid', 'livemode' => ($checkout['livemode'] ?? false) === true, 'data' => ['checkout' => $checkout]];
                    self::apply($order, SpotEx_Pay_Orders::event_action($event, $kept), (string) ($checkout['id'] ?? ''));
                    $order = wc_get_order($order_id) ?: $order;
                    if (!$order->needs_payment()) {
                        return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
                    }
                }
            }
            // Otherwise a new session: a new try's key, so an expired one is never handed back.
            $attempt = (int) $order->get_meta('_spotex_attempt');
            $session = null;
            for ($tries = 0; $tries < 3 && $session === null; $tries++) {
                $attempt++;
                $answer = $this->client()->create_session(
                    [
                        'price' => $price,
                        'currency' => $currency,
                        // The order's number only: a shop name can hold a web address, which SpotEx refuses here.
                        'item' => sprintf(__('Order %s', 'spotex-pay-for-woocommerce'), SpotEx_Pay_Orders::order_ref((string) $order->get_order_number())),
                        // The order's id, which the webhook finds it by; the number above is what people read.
                        'order_id' => (string) $order->get_id(),
                        'return_url' => $this->get_return_url($order),
                    ],
                    SpotEx_Pay_Orders::idempotency_key(home_url(), (int) $order->get_id(), $price, $currency) . '-' . $attempt
                );
                // A key used before (a restored backup, a copy of the shop) hands back that session: never take another order's.
                $session = SpotEx_Pay_Orders::session_is_ours($answer, (string) $order->get_id(), $price, $currency) ? $answer : null;
            }
            $url = (string) ($session['url'] ?? '');
            $host = (string) parse_url(SpotEx_Pay_Client::BASE_URL, PHP_URL_HOST);
            if ($session === null || empty($session['id']) || strpos($url, 'https://' . $host . '/') !== 0) {
                // Keep the tries counted, so the next go asks with new keys, not the same refused ones.
                $order->update_meta_data('_spotex_attempt', $attempt);
                $order->save();
                throw new SpotEx_Pay_Error(0, 'invalid_response', 'SpotEx Pay answered without a payment page for this order.');
            }
        } catch (SpotEx_Pay_Error $e) {
            $this->log("Order {$order_id}: could not make a checkout session: {$e->error_code} {$e->getMessage()}");
            wc_add_notice(sprintf(__('Crypto payment could not be started: %s', 'spotex-pay-for-woocommerce'), $e->getMessage()), 'error');
            return ['result' => 'failure'];
        } catch (InvalidArgumentException $e) {
            wc_add_notice(sprintf(__('Crypto payment could not be started: %s', 'spotex-pay-for-woocommerce'), $e->getMessage()), 'error');
            return ['result' => 'failure'];
        }
        // The sessions it was sent to before, each as it was sent: a payment on one of them still counts, or gets a note.
        $earlier = self::earlier($order);
        if ($kept['session_id'] !== '') {
            $earlier[] = ['id' => $kept['session_id'], 'price' => $kept['price'], 'currency' => $kept['currency'], 'test' => $kept['test']];
        }
        $test = $this->client()->is_test_mode();
        $order->update_meta_data('_spotex_earlier_sessions', array_slice($earlier, -10));
        $order->update_meta_data('_spotex_session_id', (string) $session['id']);
        $order->update_meta_data('_spotex_session_url', $url);
        $order->update_meta_data('_spotex_attempt', $attempt);
        $order->update_meta_data('_spotex_price', $price);
        $order->update_meta_data('_spotex_currency', $currency);
        $order->update_meta_data('_spotex_test', $test ? 'yes' : 'no');
        $order->save();
        $order->add_order_note(sprintf(__('Sent to SpotEx Pay to pay %1$s %2$s (session %3$s).', 'spotex-pay-for-woocommerce'), $price, $currency, $session['id']));
        $this->log("Order {$order_id}: session {$session['id']}");
        return ['result' => 'success', 'redirect' => $url];
    }

    /** The sessions an order was sent to before its last: id, price, currency, test. */
    private static function earlier(WC_Order $order): array
    {
        return array_values(array_filter((array) $order->get_meta('_spotex_earlier_sessions'), function ($before) {
            return is_array($before) && is_string($before['id'] ?? null) && $before['id'] !== '';
        }));
    }

    /** What an order keeps of its SpotEx sessions, for SpotEx_Pay_Orders::event_action. */
    private static function kept(WC_Order $order): array
    {
        try {
            $total = SpotEx_Pay_Orders::price((string) $order->get_total(), $order->get_currency());
        } catch (InvalidArgumentException $e) {
            $total = '';
        }
        $currency = (string) $order->get_meta('_spotex_currency');
        return [
            'session_id' => (string) $order->get_meta('_spotex_session_id'),
            'earlier' => self::earlier($order),
            'price' => (string) $order->get_meta('_spotex_price'),
            'total' => $total,
            'currency' => $currency !== '' ? $currency : $order->get_currency(),
            'order_currency' => $order->get_currency(),
            'test' => $order->get_meta('_spotex_test') === 'yes',
        ];
    }

    /** Keeps a checkout among the order's handled ones: booked or declined, it never completes the order later. */
    private static function handled(WC_Order $order, string $transaction_id): void
    {
        $handled = array_map('strval', (array) $order->get_meta('_spotex_handled_checkouts'));
        $order->update_meta_data('_spotex_handled_checkouts', array_slice(array_values(array_unique(array_merge($handled, [$transaction_id]))), -20));
        $order->save();
    }

    /**
     * Does what a webhook, or a check with SpotEx, decided for an order. A
     * checkout is handled once: booked or declined, never again, even on an
     * order reopened since (refunded and set back to pending, say).
     */
    private static function apply(WC_Order $order, array $decision, string $transaction_id): void
    {
        [$action, $note] = $decision;
        if ($action === 'complete' || $action === 'decline') {
            // Read again just before: a webhook and the thank-you check can arrive together.
            $order = wc_get_order($order->get_id()) ?: $order;
            $handled = array_map('strval', (array) $order->get_meta('_spotex_handled_checkouts'));
            if ($transaction_id === '' || in_array($transaction_id, $handled, true)) {
                return;
            }
            if ($action === 'decline') {
                $order->add_order_note($note);
                self::handled($order, $transaction_id);
            } elseif ($order->needs_payment()) {
                $order->payment_complete($transaction_id);
                self::handled($order, $transaction_id);
                $order->add_order_note($note);
            } elseif ($order->get_transaction_id() !== $transaction_id) {
                self::handled($order, $transaction_id);
                $order->add_order_note($order->is_paid()
                    // Paid twice: two checkouts of the order, each in full.
                    ? $note . ' ' . __('The order was paid already, so this is a second payment: refund it from the SpotEx Merchant Center if it isn\'t wanted.', 'spotex-pay-for-woocommerce')
                    // Paid in full, but the order no longer takes payment — cancelled when stock was held too long, say.
                    : $note . ' ' . sprintf(__('The order is %s, so it was not marked paid: restore it, or refund the payment from the SpotEx Merchant Center.', 'spotex-pay-for-woocommerce'), wc_get_order_status_name($order->get_status())));
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
        if (!$order instanceof WC_Order) {
            // Not an order of this shop's — payouts, tests, another site: nothing to do here.
            return new WP_REST_Response(['ok' => true], 200);
        }
        // event_action matches the event's session to the one this order was sent to, so an order id alone decides nothing.
        $decision = SpotEx_Pay_Orders::event_action($event, self::kept($order));
        $gateway->log('Webhook ' . (string) ($event['type'] ?? '') . " for order {$order->get_id()}: {$decision[0]}");
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
            // A buyer is waiting on this page: a short wait at most.
            $session = $this->client(10)->retrieve_session($kept['session_id']);
            if (($session['status'] ?? '') !== 'paid' || empty($session['checkout_id'])) {
                return;
            }
            $checkout = $this->client(10)->retrieve_checkout((string) $session['checkout_id']);
        } catch (SpotEx_Pay_Error $e) {
            $this->log("Order {$order_id}: could not ask SpotEx on return: {$e->error_code}");
            return;
        }
        $event = ['type' => 'checkout.paid', 'livemode' => ($checkout['livemode'] ?? false) === true, 'data' => ['checkout' => $checkout]];
        self::apply($order, SpotEx_Pay_Orders::event_action($event, $kept), (string) ($checkout['id'] ?? ''));
    }
}
