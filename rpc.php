<?php

declare(strict_types=1);

if (!extension_loaded('curl')) {
    throw new RuntimeException('The PHP cURL extension is required.');
}

final class RpcClient
{
    private string $url;
    private string $user;
    private string $password;
    private ?string $wallet;
    private int $timeout;

    /**
     * Bitcoin Core returns BTC amounts as JSON numbers. PHP would decode those
     * as floats, which is unsafe for exact 8-decimal money values. The small
     * pre-processing step below quotes known decimal fields before json_decode,
     * so amounts remain exact strings.
     */
    private const DECIMAL_FIELDS = [
        'amount',
        'fee',
        'balance',
        'immature_balance',
        'unconfirmed_balance',
        'paytxfee',
        'base',
        'modified',
        'ancestor',
        'descendant',
        'modifiedfee',
    ];

    public function __construct(array $config, ?string $wallet = null, int $timeout = 10)
    {
        $this->url = rtrim((string)$config['rpc_url'], '/');
        $this->user = (string)$config['rpc_user'];
        $this->password = (string)$config['rpc_password'];
        $this->wallet = $wallet;
        $this->timeout = $timeout;
    }

    public function forWallet(string $wallet): self
    {
        $client = clone $this;
        $client->wallet = $wallet;
        return $client;
    }

    public function call(string $method, array $params = []): array
    {
        $url = $this->url;
        if ($this->wallet !== null && $this->wallet !== '') {
            $url .= '/wallet/' . rawurlencode($this->wallet);
        }

        $payload = json_encode([
            'jsonrpc' => '1.0',
            'id' => 'regtest-panel',
            'method' => $method,
            'params' => $params,
        ], JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            throw new RuntimeException('Could not encode the JSON-RPC request.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Could not initialize cURL.');
        }

        $options = [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_NOBODY => false,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $this->user . ':' . $this->password,
            CURLOPT_CONNECTTIMEOUT => min(5, $this->timeout),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_FAILONERROR => false,
        ];

        if (!curl_setopt_array($ch, $options)) {
            curl_close($ch);
            throw new RuntimeException('Could not configure the cURL request.');
        }

        $rawBody = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($rawBody === false || $rawBody === '') {
            return [
                'ok' => false,
                'data' => null,
                'error' => sprintf(
                    'Node offline or RPC request failed: cURL error %d (%s)',
                    $curlErrno,
                    $curlError !== '' ? $curlError : 'empty response'
                ),
                'transport_error' => true,
                'curl_errno' => $curlErrno,
                'http_status' => $httpStatus,
            ];
        }

        $decoded = json_decode(
            $this->preserveDecimalFields((string)$rawBody),
            true,
            512,
            JSON_BIGINT_AS_STRING
        );

        if (
            !is_array($decoded) ||
            !array_key_exists('result', $decoded) ||
            !array_key_exists('error', $decoded)
        ) {
            return [
                'ok' => false,
                'data' => null,
                'error' => sprintf(
                    'Invalid JSON-RPC response (HTTP %d): %s',
                    $httpStatus,
                    json_last_error_msg()
                ),
                'transport_error' => false,
                'curl_errno' => $curlErrno,
                'http_status' => $httpStatus,
            ];
        }

        if ($decoded['error'] !== null) {
            return [
                'ok' => false,
                'data' => $decoded['result'],
                'error' => self::formatRpcError($decoded['error']),
                'transport_error' => false,
                'curl_errno' => $curlErrno,
                'http_status' => $httpStatus,
            ];
        }

        return [
            'ok' => true,
            'data' => $decoded['result'],
            'error' => null,
            'transport_error' => false,
            'curl_errno' => $curlErrno,
            'http_status' => $httpStatus,
        ];
    }

    private function preserveDecimalFields(string $json): string
    {
        $keys = implode(
            '|',
            array_map(
                static fn(string $field): string => preg_quote($field, '/'),
                self::DECIMAL_FIELDS
            )
        );

        $converted = preg_replace_callback(
            '/"(' . $keys . ')"\s*:\s*(-?(?:0|[1-9][0-9]*)\.[0-9]+)/',
            static fn(array $match): string => '"' . $match[1] . '":"' . $match[2] . '"',
            $json
        );

        return is_string($converted) ? $converted : $json;
    }

    private static function formatRpcError(mixed $error): string
    {
        if (is_array($error)) {
            $message = is_scalar($error['message'] ?? null)
                ? (string)$error['message']
                : json_encode($error, JSON_UNESCAPED_SLASHES);

            $code = $error['code'] ?? null;

            return is_scalar($code) && (string)$code !== ''
                ? sprintf('RPC error %s: %s', (string)$code, $message)
                : 'RPC error: ' . $message;
        }

        if (is_scalar($error)) {
            return 'RPC error: ' . (string)$error;
        }

        return 'Unknown RPC error.';
    }
}