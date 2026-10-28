<?php

defined('ABSPATH') || exit;

/**
 * The plugin's rules, apart from WordPress so they can be tested alone:
 * what SpotEx is asked for an order, and what a webhook means for one.
 */
final class SpotEx_Pay_Orders
{
    /** What SpotEx Pay takes prices in: spotex.trade/docs/merchant#currencies */
    const CURRENCIES = [
        'USD', 'EUR', 'GBP', 'JPY', 'CHF', 'CAD', 'AUD', 'NZD',
        'CNY', 'HKD', 'TWD', 'SGD', 'KRW', 'INR', 'IDR', 'PHP', 'THB', 'MYR', 'VND', 'PKR', 'BDT',
        'AED', 'SAR', 'TRY', 'ILS', 'ZAR', 'NGN', 'BRL', 'MXN',
        'PLN', 'CZK', 'HUF', 'RON', 'SEK', 'NOK', 'DKK', 'UAH',
    ];
    /** Priced in whole units, as SpotEx takes them. */
    const WHOLE_UNITS = ['JPY', 'KRW', 'VND', 'IDR', 'PKR', 'HUF'];

    public static function supports_currency(string $currency): bool
    {
        return in_array($currency, self::CURRENCIES, true);
    }

    /** The order number as SpotEx keeps it: letters, digits, - and _, up to 64. */
    public static function order_ref(string $number): string
    {
        $ref = substr(trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $number), '-'), 0, 64);
        return $ref !== '' ? $ref : 'order';
    }

    /**
     * The order's total as a SpotEx price: with its currency's decimals, and
     * never rounded down — a total with more decimals than that is refused.
     */
    public static function price(string $total, string $currency): string
    {
        if (preg_match('/\A(\d+)(?:\.(\d+))?\z/', trim($total), $m) !== 1) {
            throw new InvalidArgumentException('The order total is not a number.');
        }
        $whole = ltrim($m[1], '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = $m[2] ?? '';
        $places = in_array($currency, self::WHOLE_UNITS, true) ? 0 : 2;
        if (trim(substr($fraction, $places), '0') !== '') {
            throw new InvalidArgumentException("The order total has more decimals than {$currency} takes.");
        }
        $price = $places ? $whole . '.' . str_pad(substr($fraction, 0, $places), $places, '0') : $whole;
        if (preg_match('/[1-9]/', $price) !== 1) {
            throw new InvalidArgumentException('The order total is zero.');
        }
        return $price;
    }

    /** One checkout session per order and total: asked again it is the same one; a changed cart gets a new one. */
    public static function idempotency_key(int $order_id, string $price, string $currency): string
    {
        return 'wc-' . $order_id . '-' . substr(hash('sha256', $price . '|' . $currency), 0, 16);
    }

    /** Two decimal strings for the same amount: 25, 25.0 and 25.00. */
    public static function same_amount(string $a, string $b): bool
    {
        $norm = function (string $v): string {
            if (preg_match('/\A(\d+)(?:\.(\d*))?\z/', trim($v), $m) !== 1) {
                return 'x' . $v;
            }
            $whole = ltrim($m[1], '0');
            $fraction = rtrim($m[2] ?? '', '0');
            return ($whole === '' ? '0' : $whole) . ($fraction === '' ? '' : '.' . $fraction);
        };
        return $norm($a) === $norm($b);
    }

    /**
     * What a webhook means for the order it names, given what the order keeps:
     * ['complete', note] to mark it paid, ['test', note] for a test payment
     * (never paid: anyone with a test page's link can press its buttons),
     * ['note', note] to add a note, or ['ignore', ''].
     *
     * @param array $order session_id, earlier (sessions it was sent to before), price (as sent), total (its
     *                     total now, as a price), currency, and test (made with a test key)
     */
    public static function event_action(array $event, array $order): array
    {
        $checkout = $event['data']['checkout'] ?? null;
        $session = is_array($checkout) ? ($checkout['session_id'] ?? null) : null;
        if (!is_string($session) || $session === '' || $order['session_id'] === '' || $order['price'] === '') {
            return ['ignore', ''];
        }
        if ($session !== $order['session_id']) {
            // Paid on a checkout this order was sent to before its total changed: money arrived, but not for this total.
            if (in_array($session, $order['earlier'] ?? [], true) && ($event['type'] ?? '') === 'checkout.paid') {
                return ['note', 'A payment arrived for an earlier checkout of this order, made before its total changed. It is in your SpotEx merchant wallet: check the order, and refund it from the Merchant Center if needed.'];
            }
            return ['ignore', ''];
        }
        // A live order is paid only by live money; a test one only ever by tests.
        $live = ($event['livemode'] ?? null) === true;
        if ($live === (bool) $order['test']) {
            return ['ignore', ''];
        }
        $what = trim((string) ($checkout['received'] ?? $checkout['amount'] ?? '') . ' ' . (string) ($checkout['coin'] ?? ''));
        $type = (string) ($event['type'] ?? '');
        if ($type === 'checkout.paid') {
            if (!empty($checkout['duplicate'])) {
                return ['note', "A second payment ({$what}) arrived for this order. It is in your SpotEx merchant wallet: refund it from the Merchant Center if it isn't wanted."];
            }
            if (($checkout['status'] ?? '') !== 'paid') {
                return ['ignore', ''];
            }
            $price = (string) ($checkout['price'] ?? '');
            if (($checkout['currency'] ?? '') !== $order['currency'] || preg_match('/\A\d+(\.\d+)?\z/', $price) !== 1 || !self::same_amount($price, (string) $order['price'])) {
                return ['note', 'SpotEx reported a payment of ' . ($price ?: '?') . ' ' . ($checkout['currency'] ?? '') . ", not this order's total: check it before shipping."];
            }
            // Sent to pay one total; the order now asks another (changed by hand, or by another plugin).
            if (($order['total'] ?? $order['price']) !== $order['price']) {
                return ['note', "Paid {$order['price']} {$order['currency']} with SpotEx Pay, but the order's total has changed since: check it before shipping."];
            }
            $id = (string) ($checkout['id'] ?? '');
            if (!$live) {
                return ['test', "Test payment ({$what}, checkout {$id}): no real money. The order stays on hold."];
            }
            return ['complete', "Paid with SpotEx Pay: {$what} (checkout {$id})." . (!empty($checkout['accepted_short']) ? ' Accepted a little short, within your setting.' : '')];
        }
        if ($type === 'checkout.partial') {
            return ['note', "Part of the payment arrived ({$what}). SpotEx holds it until the rest does, or until you accept it in the Merchant Center."];
        }
        if ($type === 'checkout.refunded') {
            return ['note', 'Refunded in part or in full from the SpotEx Merchant Center.'];
        }
        return ['ignore', ''];
    }
}
