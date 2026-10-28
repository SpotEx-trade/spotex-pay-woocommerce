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

    /**
     * One checkout session per shop, order, total and try: asked again it is
     * the same one. The shop's own part keeps two shops on one SpotEx account
     * (or a copy of this one) from being handed each other's sessions.
     */
    public static function idempotency_key(string $site, int $order_id, string $price, string $currency): string
    {
        return 'wc-' . substr(hash('sha256', $site), 0, 8) . '-' . $order_id . '-' . substr(hash('sha256', $price . '|' . $currency), 0, 16);
    }

    /** A session SpotEx answered with is this order's own, for this total, and open to be paid. */
    public static function session_is_ours(array $session, string $order_id, string $price, string $currency): bool
    {
        return ($session['status'] ?? '') === 'open'
            && ($session['order_id'] ?? null) === $order_id
            && ($session['currency'] ?? null) === $currency
            && self::same_amount((string) ($session['price'] ?? ''), $price);
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
     * ['complete', note] to mark it paid, ['decline', note] for a payment
     * that never pays it (noted, and remembered so it never does later),
     * ['test', note] for a test payment (never paid: anyone with a test
     * page's link can press its buttons), ['note', note] to add a note, or
     * ['ignore', ''].
     *
     * @param array $order session_id, price, currency and test of the session it was sent to last; earlier (the
     *                     sessions it was sent to before, each id, price, currency, test); total and order_currency
     *                     (what the order asks now)
     */
    public static function event_action(array $event, array $order): array
    {
        $checkout = $event['data']['checkout'] ?? null;
        $session = is_array($checkout) ? ($checkout['session_id'] ?? null) : null;
        if (!is_string($session) || $session === '' || $order['session_id'] === '' || $order['price'] === '') {
            return ['ignore', ''];
        }
        // The session the money came through, as this order sent it: the last one, or one before it.
        $sent = $session === $order['session_id'] ? ['price' => $order['price'], 'currency' => $order['currency'], 'test' => (bool) $order['test']] : null;
        foreach ($sent === null ? ($order['earlier'] ?? []) : [] as $before) {
            if (is_array($before) && ($before['id'] ?? '') === $session) {
                $sent = ['price' => (string) ($before['price'] ?? ''), 'currency' => (string) ($before['currency'] ?? ''), 'test' => !empty($before['test'])];
            }
        }
        if ($sent === null) {
            return ['ignore', ''];
        }
        // A live order is paid only by live money; a test one only ever by tests.
        $live = ($event['livemode'] ?? null) === true;
        if ($live === $sent['test']) {
            return ['ignore', ''];
        }
        $what = trim((string) ($checkout['received'] ?? $checkout['amount'] ?? '') . ' ' . (string) ($checkout['coin'] ?? ''));
        $type = (string) ($event['type'] ?? '');
        if ($type === 'checkout.paid') {
            if (!empty($checkout['duplicate'])) {
                return ['decline', "A second payment ({$what}) arrived for this order. It is in your SpotEx merchant wallet: refund it from the Merchant Center if it isn't wanted."];
            }
            // Released by SpotEx rather than accepted: comes as checkout.released, but never trust the type alone.
            if (($checkout['status'] ?? '') !== 'paid' || in_array($checkout['accepted_by'] ?? null, ['operator', 'auto'], true)) {
                return ['ignore', ''];
            }
            $price = (string) ($checkout['price'] ?? '');
            if (($checkout['currency'] ?? '') !== $sent['currency'] || preg_match('/\A\d+(\.\d+)?\z/', $price) !== 1 || !self::same_amount($price, $sent['price'])) {
                return ['decline', 'SpotEx reported a payment of ' . ($price ?: '?') . ' ' . ($checkout['currency'] ?? '') . ", not the total it was sent for: check it before shipping."];
            }
            // Sent to pay one total; the order now asks another (changed by hand, by another plugin, or a currency switcher).
            if (!self::same_amount($sent['price'], (string) ($order['total'] ?? $order['price'])) || $sent['currency'] !== ($order['order_currency'] ?? $order['currency'])) {
                return ['decline', "Paid {$sent['price']} {$sent['currency']} with SpotEx Pay, but the order asks " . ($order['total'] ?? '?') . ' ' . ($order['order_currency'] ?? '') . " now: check it before shipping."];
            }
            $id = (string) ($checkout['id'] ?? '');
            if (!$live) {
                return ['test', "Test payment ({$what}, checkout {$id}): no real money. The order stays on hold."];
            }
            return ['complete', "Paid with SpotEx Pay: {$what} (checkout {$id})." . (!empty($checkout['accepted_short']) ? ' Accepted short, within your setting or by you.' : '')];
        }
        if ($type === 'checkout.partial') {
            if (!empty($checkout['duplicate'])) {
                return ['note', "A second payment ({$what}) arrived for this order, which was paid already. SpotEx holds it: accept it in the Merchant Center, then refund it or keep it."];
            }
            return ['note', "Part of the payment arrived ({$what}). SpotEx holds it until the rest does, or until you accept it in the Merchant Center."];
        }
        if ($type === 'checkout.released' || ($checkout['status'] ?? '') === 'released') {
            if (!empty($checkout['duplicate'])) {
                return ['note', "A second payment for this order ({$what}), held by SpotEx, was passed to your merchant wallet. The order was paid already: refund it from the Merchant Center if it isn't wanted."];
            }
            if (!empty($checkout['paid_late'])) {
                return ['note', "A late payment ({$what}), worth less than the order when it arrived, was passed to your merchant wallet without you accepting it. The order is NOT paid: mark it paid by hand if you accept it, or refund it."];
            }
            return ['note', "A part payment ({$what}) waited too long, and SpotEx passed it to your merchant wallet. The order is NOT paid: ask the buyer for the rest, or refund it from the Merchant Center. If the rest comes to the same address, the order is paid then."];
        }
        if ($type === 'checkout.extra') {
            return ['note', "More arrived after this order was paid ({$what}). It is in your SpotEx merchant wallet: refund the extra from the Merchant Center if it isn't wanted."];
        }
        if ($type === 'checkout.refunded') {
            return ['note', 'Refunded in part or in full from the SpotEx Merchant Center.'];
        }
        return ['ignore', ''];
    }
}
