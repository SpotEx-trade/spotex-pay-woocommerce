<?php
// Plain tests of the plugin's rules, no WordPress needed: php tests/run.php

error_reporting(E_ALL);
set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

define('ABSPATH', __DIR__);
require __DIR__ . '/../includes/class-spotex-pay-signature.php';
require __DIR__ . '/../includes/class-spotex-pay-client.php';
require __DIR__ . '/../includes/class-spotex-pay-orders.php';

$passed = 0;
$failed = 0;
function check(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        echo "  PASS  $name\n";
        $passed++;
    } catch (Throwable $e) {
        echo "  FAIL  $name\n        " . get_class($e) . ': ' . $e->getMessage() . "\n";
        $failed++;
    }
}
function same($a, $b, string $what = ''): void
{
    if ($a !== $b) {
        throw new Exception(($what ? "$what: " : '') . var_export($a, true) . ' !== ' . var_export($b, true));
    }
}
function throws(callable $fn, string $class, string $match = ''): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if (!($e instanceof $class)) {
            throw new Exception("expected $class, got " . get_class($e) . ': ' . $e->getMessage());
        }
        if ($match !== '' && strpos($e->getMessage(), $match) === false) {
            throw new Exception("message '" . $e->getMessage() . "' lacks '$match'");
        }
        return;
    }
    throw new Exception("expected $class, nothing thrown");
}

// The vector every SpotEx Pay SDK is tested against, signed by the server's own code.
const SECRET = 'whsec_test_123';
const BODY = '{"id":"evt_1","type":"checkout.paid","created":1793071234,"livemode":true,"data":{"checkout":{"id":"cm1","order_id":"1043","status":"paid"}}}';
const HEADER = 't=1793071234,v1=18217f112e2f8d2f3115929e1be70e8f16f429eb28d429c6221caa60f1476863';

check('webhook signatures: the shared vector, and what is refused', function () {
    same(SpotEx_Pay_Signature::construct_event(BODY, HEADER, SECRET, 0)['data']['checkout']['order_id'], '1043');
    throws(function () {
        SpotEx_Pay_Signature::construct_event(str_replace('1043', '9999', BODY), HEADER, SECRET, 0);
    }, UnexpectedValueException::class, 'does not match');
    throws(function () {
        SpotEx_Pay_Signature::construct_event(BODY, HEADER, SECRET);
    }, UnexpectedValueException::class, 'too old');
    throws(function () {
        SpotEx_Pay_Signature::construct_event(BODY, null, SECRET, 0);
    }, UnexpectedValueException::class, 'No SpotEx-Signature');
    // No secret set: an empty key is one anyone can sign with.
    $forged = 't=1793071234,v1=' . hash_hmac('sha256', '1793071234.' . BODY, '');
    throws(function () use ($forged) {
        SpotEx_Pay_Signature::construct_event(BODY, $forged, '', 0);
    }, InvalidArgumentException::class, 'No webhook signing secret');
});

check('an order total becomes a price in its currency, never rounded down', function () {
    same(SpotEx_Pay_Orders::price('25', 'EUR'), '25.00');
    same(SpotEx_Pay_Orders::price('25.5', 'USD'), '25.50');
    same(SpotEx_Pay_Orders::price('0025.990', 'USD'), '25.99', 'trailing zeros are fine');
    same(SpotEx_Pay_Orders::price('3000', 'JPY'), '3000');
    same(SpotEx_Pay_Orders::price('3000.00', 'JPY'), '3000');
    throws(function () {
        SpotEx_Pay_Orders::price('25.999', 'USD');
    }, InvalidArgumentException::class, 'more decimals');
    throws(function () {
        SpotEx_Pay_Orders::price('3000.5', 'JPY');
    }, InvalidArgumentException::class, 'more decimals');
    throws(function () {
        SpotEx_Pay_Orders::price('0.00', 'USD');
    }, InvalidArgumentException::class, 'zero');
    throws(function () {
        SpotEx_Pay_Orders::price('-5', 'USD');
    }, InvalidArgumentException::class, 'not a number');
});

check('order numbers, currencies and one session per order and total', function () {
    same(SpotEx_Pay_Orders::order_ref('#1043'), '1043');
    same(SpotEx_Pay_Orders::order_ref('WC 2026/1043'), 'WC-2026-1043');
    same(SpotEx_Pay_Orders::order_ref('###'), 'order');
    same(SpotEx_Pay_Orders::supports_currency('EUR'), true);
    same(SpotEx_Pay_Orders::supports_currency('ARS'), false);
    $a = SpotEx_Pay_Orders::idempotency_key(42, '25.00', 'EUR');
    same(preg_match('/\A[A-Za-z0-9_-]{8,100}\z/', $a), 1, 'a key SpotEx takes');
    same(SpotEx_Pay_Orders::idempotency_key(42, '25.00', 'EUR'), $a, 'the same order asked again: the same session');
    same(SpotEx_Pay_Orders::idempotency_key(42, '30.00', 'EUR') === $a, false, 'a changed cart: a new one');
    same(SpotEx_Pay_Orders::same_amount('25', '25.00'), true);
    same(SpotEx_Pay_Orders::same_amount('25.01', '25.1'), false);
});

$order = ['session_id' => 'cs1', 'earlier' => ['cs0'], 'price' => '25.00', 'total' => '25.00', 'currency' => 'EUR', 'test' => false];
$paid = function (array $checkout = [], bool $live = true): array {
    return ['type' => 'checkout.paid', 'livemode' => $live, 'data' => ['checkout' => $checkout + [
        'id' => 'cm1', 'session_id' => 'cs1', 'status' => 'paid', 'price' => '25.00', 'currency' => 'EUR', 'received' => '12.5', 'coin' => 'PPC', 'duplicate' => false,
    ]]];
};

check('a paid checkout for this order, its total and its mode completes it', function () use ($order, $paid) {
    $d = SpotEx_Pay_Orders::event_action($paid(), $order);
    same($d[0], 'complete');
    same(strpos($d[1], '12.5 PPC') !== false, true, 'the note says what was paid');
});

check('nothing else completes an order', function () use ($order, $paid) {
    same(SpotEx_Pay_Orders::event_action($paid(['session_id' => 'cs-other']), $order)[0], 'ignore', 'another session');
    same(SpotEx_Pay_Orders::event_action($paid([], false), $order)[0], 'ignore', 'a test event for a live order');
    same(SpotEx_Pay_Orders::event_action($paid(['price' => '2.50']), $order)[0], 'note', 'a different amount');
    same(SpotEx_Pay_Orders::event_action($paid(['currency' => 'USD']), $order)[0], 'note', 'a different currency');
    same(SpotEx_Pay_Orders::event_action($paid(['duplicate' => true]), $order)[0], 'note', 'a second payment');
    same(SpotEx_Pay_Orders::event_action($paid(['status' => 'pending']), $order)[0], 'ignore', 'not paid');
    same(SpotEx_Pay_Orders::event_action(['type' => 'checkout.partial', 'livemode' => true, 'data' => ['checkout' => ['session_id' => 'cs1']]], $order)[0], 'note');
    same(SpotEx_Pay_Orders::event_action(['type' => 'payout.transfer.completed', 'livemode' => true, 'data' => []], $order)[0], 'ignore');
    same(SpotEx_Pay_Orders::event_action(['type' => 'checkout.paid', 'data' => ['checkout' => ['session_id' => 'cs1', 'status' => 'paid']]], $order)[0], 'ignore', 'no livemode: not live');
    same(SpotEx_Pay_Orders::event_action($paid(['price' => '']), ['price' => ''] + $order)[0], 'ignore', 'empty prices never match');
    same(SpotEx_Pay_Orders::event_action($paid(['session_id' => '']), ['session_id' => ''] + $order)[0], 'ignore', 'nor empty sessions');
    same(SpotEx_Pay_Orders::event_action($paid(), ['total' => '30.00'] + $order)[0], 'note', 'the total changed since it was sent');
    same(SpotEx_Pay_Orders::event_action($paid(['session_id' => 'cs0']), $order)[0], 'note', 'paid on an earlier checkout of the order');
});

check('a test key: test payments only ever put an order on hold, never pay it', function () use ($order, $paid) {
    $test = ['test' => true] + $order;
    same(SpotEx_Pay_Orders::event_action($paid([], false), $test)[0], 'test');
    same(SpotEx_Pay_Orders::event_action($paid([], true), $test)[0], 'ignore', 'a live event for a test order');
});

check('the API client: bearer key, JSON body, idempotency key, errors', function () {
    $seen = [];
    $client = new SpotEx_Pay_Client('sk_live_abc', function ($method, $url, $headers, $body, $timeout) use (&$seen) {
        $seen[] = [$method, $url, $headers, $body, $timeout];
        return strpos($url, '/nope') !== false ? [403, '{"error":{"code":"test_mode","message":"No."}}'] : [201, '{"id":"cs1","url":"https://spotex.trade/m/cs1"}'];
    });
    $s = $client->create_session(['price' => '25.00', 'currency' => 'EUR', 'item' => 'Order 1043'], 'wc-42-abc');
    same($s['url'], 'https://spotex.trade/m/cs1');
    same($seen[0][0], 'POST');
    same($seen[0][1], 'https://spotex.trade/api/merchant/v1/checkout-sessions');
    same($seen[0][2]['Authorization'], 'Bearer sk_live_abc');
    same($seen[0][2]['Idempotency-Key'], 'wc-42-abc');
    same(json_decode($seen[0][3], true), ['price' => '25.00', 'currency' => 'EUR', 'item' => 'Order 1043']);
    same($client->is_test_mode(), false);
    same($seen[0][4], 30, 'the default timeout');
    $client->retrieve_session('cs/1');
    same($seen[1][1], 'https://spotex.trade/api/merchant/v1/checkout-sessions/cs%2F1');
    throws(function () use ($client) {
        $client->retrieve_checkout('nope');
    }, SpotEx_Pay_Error::class, 'No.');
});

echo $failed === 0 ? "\n$passed passed, 0 failed\n" : "\n$passed passed, $failed FAILED\n";
exit($failed === 0 ? 0 : 1);
