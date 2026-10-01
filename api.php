<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

function panel_json(
    bool $ok,
    mixed $data = null,
    ?string $error = null,
    int $status = 200,
    array $extra = []
): void {
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    if (!$ok && $status === 200) {
        $status = 400;
    }

    http_response_code($ok ? $status : $status);

    $response = array_merge([
        'ok' => $ok,
        'data' => $data,
        'error' => $error,
    ], $extra);

    $encoded = json_encode($response, JSON_UNESCAPED_SLASHES);

    if ($encoded === false) {
        $encoded = '{"ok":false,"data":null,"error":"JSON encoding failed."}';
    }

    echo $encoded;
    exit;
}

set_error_handler(
    function (int $errno, string $errstr, string $errfile, int $errline): bool {
        if (!(error_reporting() & $errno)) {
            return false;
        }

        throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
    }
);

set_exception_handler(
    function (Throwable $exception): void {
        panel_json(false, null, 'Server error: ' . $exception->getMessage(), 500);
    }
);

register_shutdown_function(function (): void {
    $last = error_get_last();

    if (
        is_array($last) &&
        in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)
    ) {
        panel_json(false, null, 'Fatal PHP error. Check the Apache/PHP error log.', 500);
    }
});

try {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'rpc.php';

    $configFile = __DIR__ . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php';
    if (!is_file($configFile)) {
        panel_json(false, null, 'Configuration is missing. Run php setup.php from the project root.', 500);
    }

    $config = require $configFile;
    if (!is_array($config)) {
        panel_json(false, null, 'Configuration did not return an array.', 500);
    }

    foreach (
        [
            'rpc_url',
            'rpc_user',
            'rpc_password',
            'admin_password',
            'miner_wallet',
            'root_dir',
            'data_dir',
            'bitcoin_conf',
            'bitcoind_path',
            'bitcoin_cli_path',
            'log_dir',
        ] as $requiredKey
    ) {
        if (empty($config[$requiredKey]) || !is_string($config[$requiredKey])) {
            panel_json(false, null, "Configuration key \"{$requiredKey}\" is missing or invalid.", 500);
        }
    }

    foreach (['rpc_user', 'rpc_password', 'admin_password'] as $credentialKey) {
        if (str_contains($config[$credentialKey], "\n") || str_contains($config[$credentialKey], "\r")) {
            panel_json(false, null, "Configuration key \"{$credentialKey}\" must not contain line breaks.", 500);
        }
    }

    if (
        $config['rpc_user'] === 'RUN_SETUP_PHP' ||
        $config['rpc_password'] === 'RUN_SETUP_PHP' ||
        $config['admin_password'] === 'RUN_SETUP_PHP'
    ) {
        panel_json(
            false,
            null,
            'Configuration has not been initialized. Run: C:\\xampp\\php\\php.exe C:\\xampp\\htdocs\\regtest-panel\\setup.php',
            500
        );
    }

    $config['rate_limits'] = is_array($config['rate_limits'] ?? null)
        ? $config['rate_limits']
        : default_rate_limits();

    $input = request_input();
    $rawAction = $input['action'] ?? ($_GET['action'] ?? '');
    $action = strtolower(trim(is_scalar($rawAction) ? (string)$rawAction : ''));

    $mutatingActions = [
        'mine',
        'mine_to',
        'send',
        'ensure_wallet',
        'fund_chain',
        'start_node',
        'stop_node',
        'nuke_reset',
        'update_credentials',
    ];

    if (in_array($action, $mutatingActions, true) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        panel_json(false, null, 'This action requires POST.', 405);
    }

    $rpc = new RpcClient($config);

    $result = match ($action) {
        'node_status' => action_node_status($config, $rpc),
        'mine' => action_mine($config, $rpc, $input),
        'mine_to' => action_mine_to($config, $rpc, $input),
        'recent_transactions' => action_recent_transactions($config, $rpc),
        'mempool' => action_mempool($config, $rpc),
        'send' => action_send($config, $rpc, $input),
        'ensure_wallet' => action_ensure_wallet($config, $rpc),
        'fund_chain' => action_fund_chain($config, $rpc),
        'start_node' => action_start_node($config, $rpc),
        'stop_node' => action_stop_node($config, $rpc, $input),
        'nuke_reset' => action_nuke_reset($config, $rpc, $input),
        'update_credentials' => action_update_credentials($config, $input),
        'activity' => action_activity($config),
        default => panel_json(false, null, 'Unknown action.', 404),
    };

    panel_json(true, $result);
} catch (Throwable $exception) {
    panel_json(false, null, 'Server error: ' . $exception->getMessage(), 500);
}

function default_rate_limits(): array
{
    return [
        'mine' => [
            ['window' => 60, 'limit' => 120],
            ['window' => 300, 'limit' => 300],
        ],
        'send' => [
            ['window' => 60, 'limit' => 10],
            ['window' => 300, 'limit' => 30],
        ],
    ];
}

function request_input(): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $contentType = (string)($_SERVER['CONTENT_TYPE'] ?? '');

        if (stripos($contentType, 'application/json') !== false) {
            $raw = file_get_contents('php://input');

            try {
                $decoded = json_decode($raw === false ? '' : $raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                panel_json(false, null, 'Invalid JSON request body.', 400);
            }

            if (!is_array($decoded)) {
                panel_json(false, null, 'JSON request body must be an object.', 400);
            }

            return $decoded;
        }

        return $_POST;
    }

    return $_GET;
}

function param_string(array $input, string $key, int $maxLength = 1000): string
{
    $value = $input[$key] ?? '';
    $value = is_scalar($value) ? (string)$value : '';

    return trim(substr($value, 0, $maxLength));
}

function param_int(array $input, string $key, int $min, int $max): ?int
{
    $value = $input[$key] ?? null;

    if (is_bool($value) || is_array($value) || is_object($value)) {
        return null;
    }

    $filtered = filter_var($value, FILTER_VALIDATE_INT, [
        'options' => [
            'min_range' => $min,
            'max_range' => $max,
        ],
    ]);

    return $filtered === false ? null : (int)$filtered;
}

function action_node_status(array $config, RpcClient $rpc): array
{
    $chainResponse = $rpc->call('getblockchaininfo');

    if (!$chainResponse['ok']) {
        return [
            'node_online' => false,
            'error' => $chainResponse['error'] ?? 'Node did not return blockchain information.',
            'transport_error' => (bool)($chainResponse['transport_error'] ?? false),
        ];
    }

    $heightResponse = $rpc->call('getblockcount');
    $bestHashResponse = $rpc->call('getbestblockhash');

    $ensureResponse = ensure_miner_wallet($config, $rpc);
    $walletsResponse = $rpc->call('listwallets');

    $walletInfoResponse = [
        'ok' => false,
        'error' => 'Miner wallet is unavailable.',
    ];

    if ($ensureResponse['ok']) {
        $walletInfoResponse = wallet_rpc($config, $rpc)->call('getwalletinfo');
    }

    $chainData = is_array($chainResponse['data']) ? $chainResponse['data'] : [];

    $height = $heightResponse['ok'] && is_scalar($heightResponse['data'])
        ? (int)$heightResponse['data']
        : (int)($chainData['blocks'] ?? 0);

    $bestHash = $bestHashResponse['ok'] && is_scalar($bestHashResponse['data'])
        ? (string)$bestHashResponse['data']
        : (string)($chainData['bestblockhash'] ?? '');

    $loadedWallets = is_array($walletsResponse['data']) ? $walletsResponse['data'] : [];

    return [
        'node_online' => true,
        'node' => [
            'chain' => (string)($chainData['chain'] ?? ''),
            'blocks' => (int)($chainData['blocks'] ?? 0),
            'headers' => (int)($chainData['headers'] ?? 0),
            'height' => $height,
            'bestblockhash' => $bestHash,
            'initialblockdownload' => (bool)($chainData['initialblockdownload'] ?? false),
        ],
        'wallet' => $walletInfoResponse['ok'] && is_array($walletInfoResponse['data'])
            ? $walletInfoResponse['data']
            : null,
        'wallet_error' => $walletInfoResponse['ok'] ? null : ($walletInfoResponse['error'] ?? null),
        'wallets' => array_values($loadedWallets),
        'miner_wallet' => $config['miner_wallet'],
        'miner_loaded' => in_array($config['miner_wallet'], $loadedWallets, true),
    ];
}

function wallet_rpc(array $config, RpcClient $rpc): RpcClient
{
    return $rpc->forWallet($config['miner_wallet']);
}

/**
 * Bitcoin Core does not automatically load every wallet at startup.
 * Wallet-capable actions therefore list wallets first, load the miner wallet
 * when present, and create it when absent.
 */
function ensure_miner_wallet(array $config, RpcClient $rpc): array
{
    $miner = $config['miner_wallet'];

    $walletsResponse = $rpc->call('listwallets');
    if (!$walletsResponse['ok']) {
        return $walletsResponse;
    }

    $loadedWallets = is_array($walletsResponse['data']) ? $walletsResponse['data'] : [];

    if (in_array($miner, $loadedWallets, true)) {
        return [
            'ok' => true,
            'data' => $miner,
            'error' => null,
        ];
    }

    $loadResponse = $rpc->call('loadwallet', [$miner, false]);
    if ($loadResponse['ok']) {
        return [
            'ok' => true,
            'data' => $miner,
            'error' => null,
        ];
    }

    $loadError = strtolower((string)($loadResponse['error'] ?? ''));

    if (str_contains($loadError, 'already loaded')) {
        return [
            'ok' => true,
            'data' => $miner,
            'error' => null,
        ];
    }

    if (!is_wallet_missing_error($loadError)) {
        return $loadResponse;
    }

    $createResponse = $rpc->call('createwallet', [
        $miner,
        false,
        false,
        '',
        false,
        true,
        false,
    ]);

    $createError = strtolower((string)($createResponse['error'] ?? ''));

    if (
        !$createResponse['ok'] &&
        (
            str_contains($createError, 'unknown parameter') ||
            str_contains($createError, 'wrong number')
        )
    ) {
        $legacyResponse = $rpc->call('createwallet', [
            $miner,
            false,
            false,
            '',
            false,
            false,
            false,
        ]);

        if ($legacyResponse['ok']) {
            return [
                'ok' => true,
                'data' => $miner,
                'error' => null,
            ];
        }

        $createResponse = $legacyResponse;
        $createError = strtolower((string)($createResponse['error'] ?? ''));
    }

    if (!$createResponse['ok'] && str_contains($createError, 'already exists')) {
        $retryLoad = $rpc->call('loadwallet', [$miner, false]);

        if ($retryLoad['ok']) {
            return [
                'ok' => true,
                'data' => $miner,
                'error' => null,
            ];
        }

        return $retryLoad;
    }

    if (!$createResponse['ok']) {
        return $createResponse;
    }

    return [
        'ok' => true,
        'data' => $miner,
        'error' => null,
    ];
}

function is_wallet_missing_error(string $message): bool
{
    foreach (['wallet file not found', 'not found', 'does not exist', 'no such file'] as $needle) {
        if (str_contains($message, $needle)) {
            return true;
        }
    }

    return false;
}

function rate_limit_consume(array $config, string $bucket, int $cost): array
{
    $limits = $config['rate_limits'][$bucket] ?? [];

    if (!$limits) {
        return ['allowed' => true];
    }

    $logDir = $config['log_dir'];
    ensure_dir($logDir);

    $stateFile = $logDir . DIRECTORY_SEPARATOR . 'ratelimit.json';
    $handle = fopen($stateFile, 'c+');

    if ($handle === false) {
        throw new RuntimeException('Could not open the rate-limit state file.');
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Could not lock the rate-limit state file.');
        }

        $rawState = stream_get_contents($handle);
        $state = json_decode($rawState === false || trim($rawState) === '' ? '{}' : $rawState, true);

        if (!is_array($state)) {
            $state = [];
        }

        $events = [];
        foreach (($state[$bucket] ?? []) as $event) {
            if (is_array($event) && isset($event['t'], $event['c'])) {
                $events[] = [
                    't' => (int)$event['t'],
                    'c' => max(1, (int)$event['c']),
                ];
            }
        }

        $now = time();
        $maxWindow = 0;

        foreach ($limits as $limit) {
            $window = max(1, (int)($limit['window'] ?? 60));
            $maxWindow = max($maxWindow, $window);
        }

        $events = array_values(array_filter(
            $events,
            static fn(array $event): bool => $event['t'] >= $now - $maxWindow
        ));

        foreach ($limits as $limit) {
            $window = max(1, (int)($limit['window'] ?? 60));
            $allowed = max(1, (int)($limit['limit'] ?? 1));

            $active = array_values(array_filter(
                $events,
                static fn(array $event): bool => $event['t'] >= $now - $window
            ));

            $used = array_sum(array_map(
                static fn(array $event): int => $event['c'],
                $active
            ));

            if ($used + $cost > $allowed) {
                $oldest = min(array_map(
                    static fn(array $event): int => $event['t'],
                    $active
                ));

                $retryAfter = max(1, $window - ($now - $oldest));

                flock($handle, LOCK_UN);

                return [
                    'allowed' => false,
                    'retry_after' => $retryAfter,
                ];
            }
        }

        $events[] = ['t' => $now, 'c' => $cost];
        $state[$bucket] = $events;

        $encodedState = json_encode($state, JSON_UNESCAPED_SLASHES);
        if ($encodedState === false) {
            throw new RuntimeException('Could not encode rate-limit state.');
        }

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, $encodedState);
        fflush($handle);
        flock($handle, LOCK_UN);

        return ['allowed' => true];
    } finally {
        fclose($handle);
    }
}

function ensure_dir(string $directory): void
{
    if (is_dir($directory)) {
        return;
    }

    if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create directory: ' . $directory);
    }
}

function action_mine(array $config, RpcClient $rpc, array $input): array
{
    $blocks = param_int($input, 'blocks', 1, 100);

    if ($blocks === null || !in_array($blocks, [1, 6, 100], true)) {
        panel_json(false, null, 'Choose 1, 6, or 100 blocks.', 400);
    }

    $rate = rate_limit_consume($config, 'mine', $blocks);
    if (!$rate['allowed']) {
        panel_json(
            false,
            null,
            "Mining rate limit reached. Try again in {$rate['retry_after']} seconds.",
            429,
            ['retry_after' => $rate['retry_after']]
        );
    }

    $ensure = ensure_miner_wallet($config, $rpc);
    if (!$ensure['ok']) {
        panel_json(false, null, 'Could not prepare the miner wallet: ' . ($ensure['error'] ?? 'unknown error'), 502);
    }

    $addressResponse = wallet_rpc($config, $rpc)->call('getnewaddress', ['regtest-panel-miner']);
    if (
        !$addressResponse['ok'] ||
        !is_string($addressResponse['data']) ||
        $addressResponse['data'] === ''
    ) {
        panel_json(false, null, 'Could not create a miner address: ' . ($addressResponse['error'] ?? 'empty address'), 502);
    }

    $mineResponse = $rpc->call('generatetoaddress', [$blocks, $addressResponse['data']]);
    if (!$mineResponse['ok']) {
        log_activity($config, 'mine', "Failed to mine {$blocks} block(s): " . ($mineResponse['error'] ?? 'unknown error'), false);
        panel_json(false, null, 'generatetoaddress failed: ' . ($mineResponse['error'] ?? 'unknown error'), 502);
    }

    $hashes = is_array($mineResponse['data']) ? $mineResponse['data'] : [];
    $latest = $hashes !== [] ? (string)$hashes[count($hashes) - 1] : 'no hash returned';

    $heightResponse = $rpc->call('getblockcount');
    $height = $heightResponse['ok'] && is_scalar($heightResponse['data'])
        ? (int)$heightResponse['data']
        : null;

    log_activity($config, 'mine', "Mined {$blocks} block(s); latest hash {$latest}", true);

    return [
        'blocks' => $blocks,
        'address' => $addressResponse['data'],
        'hashes' => $hashes,
        'height' => $height,
    ];
}

function action_mine_to(array $config, RpcClient $rpc, array $input): array
{
    $address = param_string($input, 'address', 200);
    $blocks = param_int($input, 'blocks', 1, 1000);

    if ($address === '') {
        panel_json(false, null, 'Enter a destination address.', 400);
    }

    if ($blocks === null) {
        panel_json(false, null, 'Enter a block count between 1 and 1000.', 400);
    }

    $rate = rate_limit_consume($config, 'mine', $blocks);
    if (!$rate['allowed']) {
        panel_json(
            false,
            null,
            "Mining rate limit reached. Try again in {$rate['retry_after']} seconds.",
            429,
            ['retry_after' => $rate['retry_after']]
        );
    }

    $ensure = ensure_miner_wallet($config, $rpc);
    if (!$ensure['ok']) {
        panel_json(false, null, 'Could not prepare the miner wallet: ' . ($ensure['error'] ?? 'unknown error'), 502);
    }

    $validation = $rpc->call('validateaddress', [$address]);
    $validationData = is_array($validation['data']) ? $validation['data'] : [];

    if (
        !$validation['ok'] ||
        empty($validationData['isvalid'])
    ) {
        panel_json(
            false,
            null,
            'Invalid address, or the address is not valid on regtest. Regtest bech32/bech32m addresses normally start with bcrt1; regtest P2SH addresses are also supported.',
            400
        );
    }

    $mineResponse = $rpc->call('generatetoaddress', [$blocks, $address]);
    if (!$mineResponse['ok']) {
        log_activity($config, 'mine_to', "Failed to mine {$blocks} block(s) to {$address}: " . ($mineResponse['error'] ?? 'unknown error'), false);
        panel_json(false, null, 'generatetoaddress failed: ' . ($mineResponse['error'] ?? 'unknown error'), 502);
    }

    $hashes = is_array($mineResponse['data']) ? $mineResponse['data'] : [];
    $latest = $hashes !== [] ? (string)$hashes[count($hashes) - 1] : 'no hash returned';

    $heightResponse = $rpc->call('getblockcount');
    $height = $heightResponse['ok'] && is_scalar($heightResponse['data'])
        ? (int)$heightResponse['data']
        : null;

    log_activity($config, 'mine_to', "Mined {$blocks} block(s) to {$address}; latest hash {$latest}", true);

    return [
        'blocks' => $blocks,
        'address' => $address,
        'hashes' => $hashes,
        'height' => $height,
    ];
}

function action_recent_transactions(array $config, RpcClient $rpc): array
{
    $ensure = ensure_miner_wallet($config, $rpc);
    if (!$ensure['ok']) {
        panel_json(false, null, 'Could not prepare the miner wallet: ' . ($ensure['error'] ?? 'unknown error'), 502);
    }

    $response = wallet_rpc($config, $rpc)->call('listtransactions', ['*', 20, 0, true]);
    if (!$response['ok']) {
        panel_json(false, null, 'listtransactions failed: ' . ($response['error'] ?? 'unknown error'), 502);
    }

    $rows = [];
    foreach (is_array($response['data']) ? array_slice($response['data'], 0, 20) : [] as $transaction) {
        if (!is_array($transaction)) {
            continue;
        }

        $rows[] = [
            'txid' => (string)($transaction['txid'] ?? ''),
            'amount' => isset($transaction['amount']) && is_scalar($transaction['amount'])
                ? (string)$transaction['amount']
                : '',
            'confirmations' => (int)($transaction['confirmations'] ?? 0),
            'category' => (string)($transaction['category'] ?? ''),
            'address' => (string)($transaction['address'] ?? ''),
        ];
    }

    // Core returns the most recent wallet transactions first.
    return [
        'transactions' => $rows,
        'newest_first' => true,
    ];
}

function action_mempool(array $config, RpcClient $rpc): array
{
    $ensure = ensure_miner_wallet($config, $rpc);
    if (!$ensure['ok']) {
        panel_json(false, null, 'Could not prepare the miner wallet: ' . ($ensure['error'] ?? 'unknown error'), 502);
    }

    $response = $rpc->call('getrawmempool', [true]);
    if (!$response['ok']) {
        panel_json(false, null, 'getrawmempool failed: ' . ($response['error'] ?? 'unknown error'), 502);
    }

    $rows = [];
    $now = time();

    foreach (is_array($response['data']) ? $response['data'] : [] as $txid => $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $fee = $entry['fees']['base'] ?? $entry['fee'] ?? '';
        $since = (int)($entry['time'] ?? 0);

        $rows[] = [
            'txid' => (string)$txid,
            'vsize' => (int)($entry['vsize'] ?? 0),
            'fee' => is_scalar($fee) ? (string)$fee : '',
            'time_in_mempool' => $since > 0 ? max(0, $now - $since) : null,
            'since' => $since,
        ];
    }

    usort(
        $rows,
        static fn(array $a, array $b): int => $b['since'] <=> $a['since']
    );

    return [
        'transactions' => $rows,
        'count' => count($rows),
        'empty' => count($rows) === 0,
    ];
}

function action_send(array $config, RpcClient $rpc, array $input): array
{
    $destination = param_string($input, 'address', 200);
    $amountSats = trim((string)($input['amount_sats'] ?? ''));
    $label = param_string($input, 'label', 256);

    if ($destination === '') {
        panel_json(false, null, 'Enter a destination address.', 400);
    }

    if (
        $amountSats === '' ||
        !preg_match('/^(?:0|[1-9][0-9]*)$/D', $amountSats)
    ) {
        panel_json(false, null, 'Enter the amount as whole satoshis, for example 1000000.', 400);
    }

    // Consensus maximum: 21,000,000 BTC = 2,100,000,000,000,000 sats.
    $maxSats = '2100000000000000';
    if (
        strlen($amountSats) > strlen($maxSats) ||
        (
            strlen($amountSats) === strlen($maxSats) &&
            strcmp($amountSats, $maxSats) > 0
        )
    ) {
        panel_json(false, null, 'Amount exceeds the maximum possible supply.', 400);
    }

    $rate = rate_limit_consume($config, 'send', 1);
    if (!$rate['allowed']) {
        panel_json(
            false,
            null,
            "Send rate limit reached. Try again in {$rate['retry_after']} seconds.",
            429,
            ['retry_after' => $rate['retry_after']]
        );
    }

    $ensure = ensure_miner_wallet($config, $rpc);
    if (!$ensure['ok']) {
        panel_json(false, null, 'Could not prepare the miner wallet: ' . ($ensure['error'] ?? 'unknown error'), 502);
    }

    $validation = $rpc->call('validateaddress', [$destination]);
    $validationData = is_array($validation['data']) ? $validation['data'] : [];

    if (!$validation['ok'] || empty($validationData['isvalid'])) {
        panel_json(
            false,
            null,
            'Invalid address, or the address is not valid on regtest. Bech32/bech32m and P2SH/script-hash regtest addresses are supported.',
            400
        );
    }

    $walletRpc = wallet_rpc($config, $rpc);
    $walletInfoResponse = $walletRpc->call('getwalletinfo');

    if (!$walletInfoResponse['ok'] || !is_array($walletInfoResponse['data'])) {
        panel_json(false, null, 'Could not read the miner wallet balance: ' . ($walletInfoResponse['error'] ?? 'unknown error'), 502);
    }

    $spendableBalance = normalize_btc(
        is_scalar($walletInfoResponse['data']['balance'] ?? null)
            ? (string)$walletInfoResponse['data']['balance']
            : '0'
    );

    if ($spendableBalance === null) {
        panel_json(false, null, 'Bitcoin Core returned an unreadable spendable balance.', 502);
    }

    /**
     * Exact integer-string conversion. No PHP floats are involved:
     *
     *   1 satoshi          -> 0.00000001 BTC
     *   100000000 satoshis -> 1.00000000 BTC
     */
    $amountBtc = sats_to_btc($amountSats);

    if (compare_btc($amountBtc, $spendableBalance) > 0) {
        log_activity(
            $config,
            'send',
            "Blocked send of {$amountSats} sats to {$destination}: exceeds spendable balance {$spendableBalance} BTC",
            false
        );

        panel_json(
            false,
            null,
            "Amount exceeds the miner wallet's spendable balance of {$spendableBalance} BTC. Remember that coinbase rewards need 100 confirmations before becoming spendable.",
            400
        );
    }

    $sendResponse = $walletRpc->call('sendtoaddress', [
        $destination,
        $amountBtc,
        $label,
    ]);

    if (!$sendResponse['ok']) {
        log_activity($config, 'send', "Failed to send {$amountSats} sats to {$destination}: " . ($sendResponse['error'] ?? 'unknown error'), false);
        panel_json(false, null, 'sendtoaddress failed: ' . ($sendResponse['error'] ?? 'unknown error'), 502);
    }

    $txid = '';
    if (is_array($sendResponse['data'])) {
        $txid = (string)($sendResponse['data']['txid'] ?? '');
    } elseif (is_scalar($sendResponse['data'])) {
        $txid = (string)$sendResponse['data'];
    }

    log_activity($config, 'send', "Sent {$amountSats} sats ({$amountBtc} BTC) to {$destination}; txid {$txid}", true);

    return [
        'txid' => $txid,
        'amount_sats' => $amountSats,
        'amount_btc' => $amountBtc,
        'destination' => $destination,
        'unconfirmed_notice' => 'The transaction is unconfirmed until a block is mined.',
    ];
}

function sats_to_btc(string $sats): string
{
    $sats = ltrim($sats, '0');

    if ($sats === '') {
        return '0.00000000';
    }

    if (strlen($sats) <= 8) {
        return '0.' . str_pad($sats, 8, '0', STR_PAD_LEFT);
    }

    return substr($sats, 0, -8) . '.' . substr($sats, -8);
}

function normalize_btc(string $btc): ?string
{
    if (!preg_match('/^(\d+)(?:\.(\d{1,8}))?$/D', trim($btc), $matches)) {
        return null;
    }

    $whole = ltrim($matches[1], '0');
    $whole = $whole === '' ? '0' : $whole;
    $fraction = str_pad($matches[2] ?? '', 8, '0', STR_PAD_RIGHT);

    return $whole . '.' . $fraction;
}

function compare_btc(string $a, string $b): int
{
    $a = normalize_btc($a);
    $b = normalize_btc($b);

    if ($a === null || $b === null) {
        throw new InvalidArgumentException('Cannot compare malformed BTC amounts.');
    }

    [$aWhole, $aFraction] = explode('.', $a, 2);
    [$bWhole, $bFraction] = explode('.', $b, 2);

    if (strlen($aWhole) !== strlen($bWhole)) {
        return strlen($aWhole) <=> strlen($bWhole);
    }

    $wholeComparison = strcmp($aWhole, $bWhole);
    if ($wholeComparison !== 0) {
        return $wholeComparison <=> 0;
    }

    return strcmp($aFraction, $bFraction) <=> 0;
}

function action_ensure_wallet(array $config, RpcClient $rpc): array
{
    $ensure = ensure_miner_wallet($config, $rpc);
    if (!$ensure['ok']) {
        log_activity($config, 'ensure_wallet', 'Failed to prepare miner wallet: ' . ($ensure['error'] ?? 'unknown error'), false);
        panel_json(false, null, 'Could not load or create the miner wallet: ' . ($ensure['error'] ?? 'unknown error'), 502);
    }

    $wallets = $rpc->call('listwallets');
    $walletInfo = wallet_rpc($config, $rpc)->call('getwalletinfo');

    log_activity($config, 'ensure_wallet', 'Miner wallet is loaded and ready.', true);

    return [
        'wallet_name' => $config['miner_wallet'],
        'wallets' => is_array($wallets['data']) ? array_values($wallets['data']) : [],
        'wallet' => $walletInfo['ok'] && is_array($walletInfo['data']) ? $walletInfo['data'] : null,
    ];
}

function action_fund_chain(array $config, RpcClient $rpc): array
{
    $ensure = ensure_miner_wallet($config, $rpc);
    if (!$ensure['ok']) {
        panel_json(false, null, 'Could not prepare the miner wallet: ' . ($ensure['error'] ?? 'unknown error'), 502);
    }

    $rate = rate_limit_consume($config, 'mine', 101);
    if (!$rate['allowed']) {
        panel_json(
            false,
            null,
            "Mining rate limit reached. Try again in {$rate['retry_after']} seconds.",
            429,
            ['retry_after' => $rate['retry_after']]
        );
    }

    $addressResponse = wallet_rpc($config, $rpc)->call('getnewaddress', ['regtest-panel-funding']);
    if (
        !$addressResponse['ok'] ||
        !is_string($addressResponse['data']) ||
        $addressResponse['data'] === ''
    ) {
        panel_json(false, null, 'Could not create a miner address: ' . ($addressResponse['error'] ?? 'empty address'), 502);
    }

    /**
     * Regtest coinbase outputs require 100 confirmations. A new chain must
     * reach height 101 before the first reward has 100 confirmations and the
     * miner's balance becomes spendable. This mines exactly 101 blocks.
     */
    $mineResponse = $rpc->call('generatetoaddress', [101, $addressResponse['data']]);
    if (!$mineResponse['ok']) {
        log_activity($config, 'fund_chain', 'Failed to fund the chain: ' . ($mineResponse['error'] ?? 'unknown error'), false);
        panel_json(false, null, 'Could not fund the chain: ' . ($mineResponse['error'] ?? 'unknown error'), 502);
    }

    $hashes = is_array($mineResponse['data']) ? $mineResponse['data'] : [];
    $latest = $hashes !== [] ? (string)$hashes[count($hashes) - 1] : 'no hash returned';

    $heightResponse = $rpc->call('getblockcount');
    $height = $heightResponse['ok'] && is_scalar($heightResponse['data'])
        ? (int)$heightResponse['data']
        : null;

    log_activity($config, 'fund_chain', "Mined 101 funding blocks; latest hash {$latest}", true);

    return [
        'blocks' => 101,
        'address' => $addressResponse['data'],
        'hashes' => $hashes,
        'height' => $height,
        'message' => 'Mined 101 blocks. The first coinbase reward now has 100 confirmations and is spendable.',
    ];
}

function action_start_node(array $config, RpcClient $rpc): array
{
    if (!is_file($config['bitcoind_path'])) {
        panel_json(false, null, 'bitcoind.exe was not found at ' . $config['bitcoind_path'], 500);
    }

    $probe = $rpc->call('getblockchaininfo');
    if ($probe['ok']) {
        log_activity($config, 'start_node', 'Start requested, but the node is already accepting RPC requests.', true);
        return [
            'started' => false,
            'already_running' => true,
            'message' => 'The node is already running.',
        ];
    }

    if (!($probe['transport_error'] ?? false)) {
        log_activity($config, 'start_node', 'Start refused because RPC is reachable but not ready: ' . ($probe['error'] ?? 'unknown error'), false);
        panel_json(false, null, 'The node RPC endpoint is reachable but not ready: ' . ($probe['error'] ?? 'unknown error'), 409);
    }

    ensure_data_dir($config);
    $launch = launch_bitcoind($config);

    log_activity(
        $config,
        'start_node',
        $launch['ok']
            ? 'Detached bitcoind start command sent.'
            : 'Failed to start bitcoind.',
        $launch['ok']
    );

    return [
        'started' => $launch['ok'],
        'already_running' => false,
        'message' => $launch['ok'] ? 'Node start command sent.' : 'Failed to start node.',
    ];
}

function launch_bitcoind(array $config): array
{
    $cmd = sprintf(
        'start "" /B "%s" -regtest -datadir="%s" -conf="%s" >> "%s" 2>&1',
        $config['bitcoind_path'],
        $config['data_dir'],
        $config['bitcoin_conf'],
        $config['log_dir'] . DIRECTORY_SEPARATOR . 'node.out'
    );
    
    pclose(popen($cmd, 'r'));
    return ['ok' => true];
}

function ensure_data_dir(array $config): void
{
    ensure_dir($config['data_dir']);
}

function action_stop_node(array $config, RpcClient $rpc, array $input): array
{
    $cmd = sprintf('start "" /B "%s"', $config['root_dir'] . DIRECTORY_SEPARATOR . 'stop-node.bat');
    pclose(popen($cmd, 'r'));
    log_activity($config, 'stop_node', 'Detached node stop command sent.', true);
    return [
        'stopped' => true,
        'message' => 'Stop command sent.',
    ];
}

function action_nuke_reset(array $config, RpcClient $rpc, array $input): array
{
    $cmd = sprintf('start "" /B "%s"', $config['root_dir'] . DIRECTORY_SEPARATOR . 'reset-chain.bat');
    pclose(popen($cmd, 'r'));
    log_activity($config, 'nuke_reset',
        'update_credentials', 'Detached nuke reset command sent.', true);
    return [
        'nuked' => true,
        'message' => 'Chain reset command sent. This will take a few seconds.',
    ];
}

function action_activity(array $config): array
{
    $logFile = $config['log_dir'] . DIRECTORY_SEPARATOR . 'activity.jsonl';
    $activities = [];
    if (is_file($logFile)) {
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $activities[] = [
                    'time' => date('Y-m-d H:i:s', $decoded['time'] ?? time()),
                    'action' => $decoded['action'] ?? '',
                    'detail' => $decoded['message'] ?? '',
                    'ok' => $decoded['ok'] ?? false,
                ];
            }
        }
    }
    return array_reverse($activities);
}

function log_activity(array $config, string $action, string $message, bool $ok): void
{
    ensure_dir($config['log_dir']);
    $logFile = $config['log_dir'] . DIRECTORY_SEPARATOR . 'activity.jsonl';
    
    $entry = [
        'time' => time(),
        'action' => $action,
        'message' => $message,
        'ok' => $ok,
    ];
    
    file_put_contents($logFile, json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}
function action_update_credentials(array $config, array $input): array
{
    $newUser = trim((string)($input['rpc_user'] ?? ''));
    $newPass = trim((string)($input['rpc_password'] ?? ''));
    
    if ($newUser === '' || $newPass === '') {
        panel_json(false, null, 'RPC user and password cannot be empty.');
    }
    
    $configPath = $config['root_dir'] . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php';
    if (!is_file($configPath)) {
        panel_json(false, null, 'Config file not found.');
    }
    
    $currentConfig = require $configPath;
    $currentConfig['rpc_user'] = $newUser;
    $currentConfig['rpc_password'] = $newPass;
    
    file_put_contents(
        $configPath,
        "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($currentConfig, true) . ";\n",
        LOCK_EX
    );
    
    $confPath = $currentConfig['bitcoin_conf'] ?? ($config['data_dir'] . DIRECTORY_SEPARATOR . 'bitcoin.conf');
    if (is_file($confPath)) {
        $confContents = file_get_contents($confPath);
        if (strpos($confContents, 'rpcuser=') !== false) {
            $confContents = preg_replace('/^rpcuser=.*$/m', 'rpcuser=' . $newUser, $confContents);
        } else {
            $confContents .= "\nrpcuser=" . $newUser;
        }
        
        if (strpos($confContents, 'rpcpassword=') !== false) {
            $confContents = preg_replace('/^rpcpassword=.*$/m', 'rpcpassword=' . $newPass, $confContents);
        } else {
            $confContents .= "\nrpcpassword=" . $newPass;
        }
        
        file_put_contents($confPath, $confContents, LOCK_EX);
    }
    
    return ['message' => 'Credentials updated successfully.'];
}
