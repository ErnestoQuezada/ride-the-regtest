<?php

declare(strict_types=1);

function valid_ipv4(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
}

$hostname = (string)gethostname();
$lanIp = valid_ipv4((string)gethostbyname($hostname))
    ? (string)gethostbyname($hostname)
    : '';

$serverIp = (string)($_SERVER['SERVER_ADDR'] ?? '');

if ($lanIp === '' && valid_ipv4($serverIp) && !in_array($serverIp, ['127.0.0.1', '::1'], true)) {
    $lanIp = $serverIp;
}

// Load Tailscale IP from .env
$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    $envVars = parse_ini_file($envPath);
    if (isset($envVars['TAILSCALE_IP'])) {
        $lanIp = $envVars['TAILSCALE_IP'];
    }
}

// Load config for credentials
$configFile = __DIR__ . '/config/config.php';
$config = is_file($configFile) ? require $configFile : [];

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$port = (int)($_SERVER['SERVER_PORT'] ?? ($scheme === 'https' ? 443 : 80));
$portSuffix = in_array($port, [80, 443], true) ? '' : ':' . $port;

$scriptDir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
if ($scriptDir === '/' || $scriptDir === '\\') {
    $scriptDir = '';
}

$lanUrl = $lanIp !== '' ? $scheme . '://' . $lanIp . $portSuffix . $scriptDir . '/' : '';
$currentUrl = $scheme . '://' . (string)($_SERVER['HTTP_HOST'] ?? 'localhost') . $scriptDir . '/';

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ride The Regtest</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<aside class="sidebar">
    <div class="logo-area">
        <img src="images/regtest.png" alt="RTRT Logo">
        <div class="brand">
            <h1>Ride The Regtest</h1>
            <div class="badge">REGTEST ONLY</div>
        </div>
    </div>
    <div class="nav-menu">
        <a class="nav-link active" data-target="page-mining">Mining & Wallet</a>
        <a class="nav-link" data-target="page-network">Network & Mempool</a>
        <a class="nav-link" data-target="page-activity">Activity Log</a>
        <a class="nav-link" data-target="page-utilities">Utilities</a>
    </div>
    <div class="sidebar-footer">
        <p class="small" style="margin-bottom: .5rem">Control Panel URL:</p>
        <code id="lan-url" class="lan-url" style="word-break: break-all; margin-bottom: 1rem; display: block;">—</code>
        <button id="copy-url" class="ghost full" type="button">Copy LAN URL</button>
    </div>
</aside>

<div class="main-container">
    <div class="overview-header">
        <div class="stats" aria-label="Network status">
            <div class="stat">
                <span>Current height</span>
                <strong id="stat-height">—</strong>
            </div>
            <div class="stat">
                <span>Best block hash</span>
                <strong>
                    <span id="best-hash">—</span>
                    <button id="copy-best-hash" class="ghost" type="button" style="padding:.1rem .3rem;margin-left:.25rem;font-size:0.65rem">Copy</button>
                </strong>
            </div>
            <div class="stat">
                <span>Mempool txs</span>
                <strong id="stat-mempool">—</strong>
            </div>
            <div class="stat">
                <span>Spendable balance</span>
                <strong id="stat-balance">—</strong>
            </div>
            <div class="stat" id="immature-card">
                <span>Immature coinbase</span>
                <strong id="stat-immature">—</strong>
            </div>
        </div>
        <div class="header-controls">
            <button id="header-node-toggle" class="primary btn-large" type="button">Start Node</button>
            <button id="start-node" class="hidden" type="button">dummy start</button>
            <button id="stop-node" class="hidden" type="button">dummy stop</button>
        </div>
    </div>

    <div id="node-banner" class="banner hidden">
        <div>
            <strong class="danger-text">Node offline or unreachable.</strong>
            <span id="node-banner-message"></span>
        </div>
        <button id="start-node-banner" class="primary" type="button">Start node</button>
    </div>

    <div class="pages-container">
        
        <!-- PAGE: MINING & WALLET -->
        <div id="page-mining" class="page active">
            <div class="grid-2">
                <section id="mining" class="card">
                    <h2>Mining</h2>
                    <div class="button-row">
                        <button class="primary" type="button" data-mine="1">Mine 1 block</button>
                        <button class="primary" type="button" data-mine="6">Mine 6 blocks</button>
                        <button class="primary" type="button" data-mine="100">Mine 100 blocks</button>
                    </div>

                    <form id="mine-to-form" style="margin-top: 1.5rem">
                        <label>
                            Mine to designated address
                            <input id="mine-to-address" required maxlength="200" placeholder="bcrt1... or P2SH address">
                        </label>
                        <label>
                            Number of blocks
                            <input id="mine-to-blocks" required inputmode="numeric" pattern="[0-9]*" placeholder="1–1000" value="1">
                        </label>
                        <div class="button-row">
                            <button class="primary" type="submit">Mine to address</button>
                        </div>
                    </form>
                    <p class="hint" style="margin-top:1rem">
                        Regtest coinbase rewards become spendable only after 100 confirmations. Use <strong>Fund the chain</strong> in Utilities to mine 101 blocks on a fresh chain.
                    </p>
                </section>

                <section id="wallet" class="card">
                    <h2>Send Payment</h2>
                    <p class="hint">
                        Spendable balance:
                        <strong id="send-balance">—</strong>
                    </p>

                    <form id="send-form" style="margin-top: 1rem">
                        <label>
                            Destination address
                            <input id="send-address" required maxlength="200" placeholder="bcrt1..., P2SH, P2WSH">
                        </label>
                        <label>
                            Amount in satoshis
                            <input id="send-sats" required inputmode="numeric" pattern="[0-9]*" placeholder="1000000">
                        </label>
                        <label>
                            Optional label
                            <input id="send-label" maxlength="256" placeholder="classroom test">
                        </label>
                        <div class="button-row">
                            <button class="primary" type="submit">Send payment</button>
                        </div>
                    </form>
                    <p class="notice">
                        The transaction remains unconfirmed until someone mines a block.
                    </p>
                </section>
            </div>
        </div>

        <!-- PAGE: NETWORK & MEMPOOL -->
        <div id="page-network" class="page">
            <div class="grid-2">
                <section id="transactions" class="card">
                    <h2>Recent Transactions</h2>
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>TXID</th>
                                <th>Amount</th>
                                <th>Conf.</th>
                                <th>Category</th>
                                <th>Address</th>
                            </tr>
                            </thead>
                            <tbody id="tx-body"></tbody>
                        </table>
                    </div>
                </section>

                <section id="mempool" class="card">
                    <h2>Mempool</h2>
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>TXID</th>
                                <th>Vsize</th>
                                <th>Fee</th>
                                <th>Time in mempool</th>
                            </tr>
                            </thead>
                            <tbody id="mempool-body"></tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>

        <!-- PAGE: ACTIVITY LOG -->
        <div id="page-activity" class="page">
            <section id="activity" class="card">
                <h2>Activity Log</h2>
                <div id="activity-list" class="activity-list"></div>
            </section>
        </div>

        <!-- PAGE: UTILITIES -->
        <div id="page-utilities" class="page">
            <div class="grid-2">
                <section id="utilities" class="card">
                    <h2>Network & Wallet Diagnostics</h2>
                    <dl class="info-grid">
                        <dt>Chain</dt><dd id="node-chain">—</dd>
                        <dt>Blocks / headers</dt><dd id="node-blocks">—</dd>
                        <dt>IBD</dt><dd id="node-ibd">—</dd>
                        <dt>Wallet</dt><dd id="wallet-name">—</dd>
                        <dt>Unconfirmed</dt><dd id="wallet-unconfirmed">—</dd>
                    </dl>

                    <p id="wallet-status" class="hint" style="margin-top:.6rem">—</p>
                    <div id="loaded-wallets" class="chips">—</div>

                    <div class="button-row" style="margin-top: 1rem">
                        <button id="ensure-wallet" type="button">Load/create wallet</button>
                        <button id="fund-chain" class="primary" type="button">Fund chain (101 blocks)</button>
                    </div>

                    <div class="admin-box" style="margin-top: 2rem;">
                        <h3 class="small" style="margin-bottom:.5rem;color:var(--danger)">Destructive actions</h3>
                        <label>
                            Admin password
                            <input id="admin-password" type="password" autocomplete="off">
                        </label>
                        <label>
                            Type RESET to confirm
                            <input id="reset-confirm" autocomplete="off" placeholder="RESET">
                        </label>
                        <div class="button-row">
                            <button id="nuke-reset" class="danger" type="button">Nuke and restart chain</button>
                        </div>
                    </div>
                </section>
                
                <section class="card">
                    <h2>Help & Connection Info</h2>
                    <form id="update-cred-form">
                        <label>
                            RPC User
                            <input id="new-rpc-user" value="<?= htmlspecialchars($config['rpc_user'] ?? '') ?>" placeholder="user">
                        </label>
                        <label>
                            RPC Password
                            <div class="password-wrapper">
                                <input id="new-rpc-pass" value="<?= htmlspecialchars($config['rpc_password'] ?? '') ?>" type="password" placeholder="password">
                                <button type="button" id="toggle-rpc-pass" class="toggle-password" aria-label="Toggle password visibility">
                                    <svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" class="eye-icon"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                </button>
                            </div>
                        </label>
                        <div class="button-row">
                            <button class="primary" type="submit">Update Credentials</button>
                        </div>
                    </form>
                    <p class="notice" style="margin-top: 1rem">
                        <strong>Tip:</strong> You can mine blocks to instantly confirm your transactions. Regtest allows you to generate blocks on demand without any hashing power.
                    </p>
                    <p class="notice" style="margin-top: 1rem">
                        <strong>Warning:</strong> Nuking the chain will erase all wallets and blockchain history in this regtest environment. Use carefully!
                    </p>
                </section>
            </div>
        </div>

    </div>
</div>

<div id="toast-root" aria-live="polite"></div>

<script>
window.PANEL_URLS = <?= json_encode(
        ['lan' => $lanUrl, 'current' => $currentUrl],
        JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ) ?>;
</script>
<script src="script.js"></script>
</body>
</html>
