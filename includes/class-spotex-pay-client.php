<?php

defined('ABSPATH') || exit;

/** An answer from the SpotEx Pay API other than 2xx, or no answer at all (status 0, network_error). */
class SpotEx_Pay_Error extends RuntimeException
{
    /** @var int */
    public $status;
    /** @var string */
    public $error_code;

    public function __construct(int $status, string $error_code, string $message)
    {
        parent::__construct($message, $status);
        $this->status = $status;
        $this->error_code = $error_code;
    }
}

/** The few calls the plugin makes to the SpotEx Pay merchant API, over WordPress's own HTTP functions. */
final class SpotEx_Pay_Client
{
    const BASE_URL = 'https://spotex.trade/api/merchant/v1';

    /** @var string */
    private $key;
    /** @var callable */
    private $transport;

    /**
     * @param callable|null $transport For tests: function (string $method, string $url, array $headers, ?string $body): array [int $status, string $body]
     */
    public function __construct(string $key, ?callable $transport = null)
    {
        $this->key = $key;
        $this->transport = $transport ?? [self::class, 'wp_transport'];
    }

    public function is_test_mode(): bool
    {
        return strpos($this->key, 'sk_test_') === 0;
    }

    /** A checkout session: send the buyer to its 'url'. */
    public function create_session(array $params, string $idempotency_key): array
    {
        return $this->request('POST', '/checkout-sessions', $params, $idempotency_key);
    }

    public function retrieve_session(string $id): array
    {
        return $this->request('GET', '/checkout-sessions/' . rawurlencode($id));
    }

    public function retrieve_checkout(string $id): array
    {
        return $this->request('GET', '/checkouts/' . rawurlencode($id));
    }

    private function request(string $method, string $path, ?array $body = null, ?string $idempotency_key = null): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->key,
            'Accept' => 'application/json',
            'User-Agent' => 'spotex-pay-woocommerce/' . (defined('SPOTEX_PAY_WC_VERSION') ? SPOTEX_PAY_WC_VERSION : 'dev'),
        ];
        $json = null;
        if ($body !== null) {
            $json = json_encode($body);
            if ($json === false) {
                throw new SpotEx_Pay_Error(0, 'invalid_request', 'The order could not be sent as JSON.');
            }
            $headers['Content-Type'] = 'application/json';
        }
        if ($idempotency_key !== null) {
            $headers['Idempotency-Key'] = $idempotency_key;
        }
        $base = function_exists('apply_filters') ? (string) apply_filters('spotex_pay_api_base', self::BASE_URL) : self::BASE_URL;
        [$status, $raw] = call_user_func($this->transport, $method, rtrim($base, '/') . $path, $headers, $json);
        $data = json_decode((string) $raw, true);
        if ($status < 200 || $status >= 300) {
            $error = is_array($data) && isset($data['error']) && is_array($data['error']) ? $data['error'] : [];
            throw new SpotEx_Pay_Error((int) $status, (string) ($error['code'] ?? 'http_error'), (string) ($error['message'] ?? ('HTTP ' . $status)));
        }
        if (!is_array($data)) {
            throw new SpotEx_Pay_Error((int) $status, 'invalid_response', 'SpotEx Pay answered with something that is not JSON.');
        }
        return $data;
    }

    /** @internal WordPress's HTTP API. */
    public static function wp_transport(string $method, string $url, array $headers, ?string $body): array
    {
        $args = ['method' => $method, 'headers' => $headers, 'timeout' => 30];
        if ($body !== null) {
            $args['body'] = $body;
        }
        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            throw new SpotEx_Pay_Error(0, 'network_error', 'Could not reach SpotEx Pay: ' . $response->get_error_message());
        }
        return [(int) wp_remote_retrieve_response_code($response), (string) wp_remote_retrieve_body($response)];
    }
}
