<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$root = __DIR__;
$configDir = $root . DIRECTORY_SEPARATOR . 'config';
$dataDir = $root . DIRECTORY_SEPARATOR . 'data';
$logDir = 'C:\\xampp\\regtest-panel-logs';

$configPath = $configDir . DIRECTORY_SEPARATOR . 'config.php';
$confPath = $dataDir . DIRECTORY_SEPARATOR . 'bitcoin.conf';

$force = in_array('--force', $argv, true);

try {
    if (!is_dir($configDir) && !mkdir($configDir, 0755, true) && !is_dir($configDir)) {
        throw new RuntimeException('Could not create config directory.');
    }

    if (!is_dir($dataDir) && !mkdir($dataDir, 0755, true) && !is_dir($dataDir)) {
        throw new RuntimeException('Could not create data directory.');
    }

    if (!is_dir($logDir) && !mkdir($logDir, 0755, true) && !is_dir($logDir)) {
        throw new RuntimeException('Could not create log directory.');
    }

    $existingConfig = is_file($configPath) ? (string)file_get_contents($configPath) : '';
    $isPlaceholder = str_contains($existingConfig, 'RUN_SETUP_PHP');

    if (is_file($configPath) && !$force && !$isPlaceholder) {
        fwrite(STDERR, "config.php already exists. Use --force to replace it and rotate credentials.\n");
        exit(1);
    }

    if (is_file($configPath) && $force) {
        $backupPath = $configPath . '.bak-' . date('Ymd-His');
        copy($configPath, $backupPath);
        fwrite(STDOUT, "Backed up existing config to {$backupPath}\n");
    }

    $rpcUser = 'panel_' . bin2hex(random_bytes(4));
    $rpcPassword = bin2hex(random_bytes(24));
    $adminPassword = bin2hex(random_bytes(12));

    $config = [
        'rpc_url' => 'http://127.0.0.1:18443/',
        'rpc_user' => $rpcUser,
        'rpc_password' => $rpcPassword,
        'admin_password' => $adminPassword,
        'miner_wallet' => 'miner',

        'root_dir' => $root,
        'data_dir' => $dataDir,
        'bitcoin_conf' => $confPath,
        'bitcoind_path' => $root . DIRECTORY_SEPARATOR . 'bitcoind.exe',
        'bitcoin_cli_path' => $root . DIRECTORY_SEPARATOR . 'bitcoin-cli.exe',

        // Outside Apache's DocumentRoot on purpose.
        'log_dir' => $logDir,

        'rate_limits' => [
            'mine' => [
                ['window' => 60, 'limit' => 120],
                ['window' => 300, 'limit' => 300],
            ],
            'send' => [
                ['window' => 60, 'limit' => 10],
                ['window' => 300, 'limit' => 30],
            ],
        ],
    ];

    file_put_contents(
        $configPath,
        "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n",
        LOCK_EX
    );

    file_put_contents(
        $configDir . DIRECTORY_SEPARATOR . '.htaccess',
        "Require all denied\n",
        LOCK_EX
    );

    file_put_contents(
        $dataDir . DIRECTORY_SEPARATOR . '.htaccess',
        "Require all denied\n",
        LOCK_EX
    );

    $bitcoinConf =
        "# Classroom regtest node. RPC is intentionally localhost-only.\n" .
        "regtest=1\n" .
        "server=1\n" .
        "txindex=1\n" .
        "listen=0\n" .
        "fallbackfee=0.0001\n" .
        "rpcuser={$rpcUser}\n" .
        "rpcpassword={$rpcPassword}\n" .
        "rpcbind=127.0.0.1\n" .
        "rpcallowip=127.0.0.1\n";

    file_put_contents($confPath, $bitcoinConf, LOCK_EX);

    fwrite(STDOUT, "Setup complete.\n");
    fwrite(STDOUT, "Admin password: {$adminPassword}\n");
    fwrite(STDOUT, "Config: {$configPath}\n");
    fwrite(STDOUT, "Bitcoin config: {$confPath}\n");
    fwrite(STDOUT, "Private activity/rate-limit log directory: {$logDir}\n");

    if (!is_file($root . DIRECTORY_SEPARATOR . 'bitcoind.exe')) {
        fwrite(STDERR, "Warning: bitcoind.exe is not in {$root} yet.\n");
    }

    if (!is_file($root . DIRECTORY_SEPARATOR . 'bitcoin-cli.exe')) {
        fwrite(STDERR, "Warning: bitcoin-cli.exe is not in {$root} yet.\n");
    }

    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Setup failed: ' . $exception->getMessage() . "\n");
    exit(1);
}