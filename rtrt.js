const express = require('express');
const path = require('path');
const fs = require('fs');
const crypto = require('crypto');
const { spawn, exec } = require('child_process');
const os = require('os');

const app = express();
app.use(express.json());
app.use(express.static(path.join(__dirname)));

const configDir = path.join(__dirname, 'config');
const dataDir = path.join(__dirname, 'data');
const logDir = path.join(dataDir, 'logs');
const configPath = path.join(configDir, 'config.json');
const confPath = path.join(dataDir, 'bitcoin.conf');

function ensureDir(dir) {
    if (!fs.existsSync(dir)) {
        fs.mkdirSync(dir, { recursive: true });
    }
}

function getLocalIp() {
    const interfaces = os.networkInterfaces();
    for (const devName in interfaces) {
        const iface = interfaces[devName];
        for (let i = 0; i < iface.length; i++) {
            const alias = iface[i];
            if (alias.family === 'IPv4' && alias.address !== '127.0.0.1' && !alias.internal) {
                return alias.address;
            }
        }
    }
    return '127.0.0.1';
}

// Setup logic equivalent to setup.php
function setup() {
    ensureDir(configDir);
    ensureDir(dataDir);
    ensureDir(logDir);

    if (fs.existsSync(configPath)) {
        return JSON.parse(fs.readFileSync(configPath, 'utf-8'));
    }

    console.log("Running initial setup...");
    const rpcUser = 'panel_' + crypto.randomBytes(4).toString('hex');
    const rpcPassword = crypto.randomBytes(24).toString('hex');
    const adminPassword = crypto.randomBytes(12).toString('hex');

    const config = {
        port: 8080,
        rpc_url: 'http://127.0.0.1:18443/',
        rpc_user: rpcUser,
        rpc_password: rpcPassword,
        admin_password: adminPassword,
        miner_wallet: 'miner',
        root_dir: __dirname,
        data_dir: dataDir,
        bitcoin_conf: confPath,
        bitcoind_path: path.join(__dirname, 'bitcoind.exe'),
        bitcoin_cli_path: path.join(__dirname, 'bitcoin-cli.exe'),
        log_dir: logDir
    };

    fs.writeFileSync(configPath, JSON.stringify(config, null, 2));

    const bitcoinConf = [
        "# Classroom regtest node. RPC is intentionally localhost-only.",
        "regtest=1",
        "server=1",
        "txindex=1",
        "listen=0",
        "fallbackfee=0.0001",
        "[regtest]",
        `rpcuser=${rpcUser}`,
        `rpcpassword=${rpcPassword}`,
        "rpcbind=127.0.0.1",
        "rpcallowip=127.0.0.1"
    ].join('\n') + '\n';

    fs.writeFileSync(confPath, bitcoinConf);
    console.log("Setup complete!");
    console.log(`Admin password: ${adminPassword}`);
    return config;
}

let config = setup();
const PORT = process.env.PORT || config.port || 8080;

class RpcClient {
    constructor(cfg, wallet = null) {
        this.url = cfg.rpc_url;
        this.user = cfg.rpc_user;
        this.password = cfg.rpc_password;
        this.wallet = wallet;
    }

    forWallet(wallet) {
        return new RpcClient(config, wallet);
    }

    async call(method, params = []) {
        let endpoint = this.url.replace(/\/$/, '');
        if (this.wallet) {
            endpoint += '/wallet/' + encodeURIComponent(this.wallet);
        }

        const auth = Buffer.from(`${this.user}:${this.password}`).toString('base64');
        const payload = {
            jsonrpc: '1.0',
            id: 'regtest-panel',
            method,
            params
        };

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'text/plain', // bitcoind usually prefers plain or json
                    'Authorization': `Basic ${auth}`
                },
                body: JSON.stringify(payload)
            });

            const text = await response.text();
            if (!response.ok && !text) {
                return { ok: false, error: `HTTP ${response.status}`, transport_error: true };
            }

            const data = JSON.parse(text);
            if (data.error) {
                const errMsg = typeof data.error === 'string' ? data.error : (data.error.message || JSON.stringify(data.error));
                return { ok: false, error: errMsg, transport_error: false };
            }

            return { ok: true, data: data.result };
        } catch (err) {
            return { ok: false, error: err.message, transport_error: true };
        }
    }
}

const rpc = new RpcClient(config);

function logActivity(action, message, ok) {
    const entry = { time: Math.floor(Date.now() / 1000), action, message, ok };
    fs.appendFileSync(path.join(config.log_dir, 'activity.jsonl'), JSON.stringify(entry) + '\n');
}

function panelJson(res, ok, data = null, error = null, status = 200, extra = {}) {
    if (!ok && status === 200) status = 400;
    res.status(status).json({ ok, data, error, ...extra });
}

async function ensureMinerWallet() {
    const walletsResp = await rpc.call('listwallets');
    if (!walletsResp.ok) return walletsResp;
    
    if (walletsResp.data.includes(config.miner_wallet)) {
        return { ok: true, data: config.miner_wallet };
    }

    const loadResp = await rpc.call('loadwallet', [config.miner_wallet, false]);
    if (loadResp.ok) return { ok: true, data: config.miner_wallet };
    
    const err = (loadResp.error || '').toLowerCase();
    if (err.includes('already loaded')) return { ok: true, data: config.miner_wallet };

    if (!err.includes('not found') && !err.includes('does not exist') && !err.includes('no such file')) {
        return loadResp;
    }

    let createResp = await rpc.call('createwallet', [config.miner_wallet, false, false, '', false, true, false]);
    if (!createResp.ok && (createResp.error.includes('unknown parameter') || createResp.error.includes('wrong number'))) {
        createResp = await rpc.call('createwallet', [config.miner_wallet, false, false, '', false, false, false]);
    }
    
    if (!createResp.ok && createResp.error.includes('already exists')) {
        const retryLoad = await rpc.call('loadwallet', [config.miner_wallet, false]);
        if (retryLoad.ok) return { ok: true, data: config.miner_wallet };
        return retryLoad;
    }
    
    if (!createResp.ok) return createResp;
    return { ok: true, data: config.miner_wallet };
}

app.get('/', (req, res) => {
    let html = fs.readFileSync(path.join(__dirname, 'index.html'), 'utf-8');
    const portSuffix = (PORT === 80) ? '' : `:${PORT}`;
    const lanUrl = `http://${getLocalIp()}${portSuffix}/`;
    const currentUrl = `http://${req.headers.host || 'localhost'}/`;
    
    html = html.replace(
        "window.PANEL_URLS = { lan: '', current: '' };", 
        `window.PANEL_URLS = ${JSON.stringify({ lan: lanUrl, current: currentUrl })};`
    );
    res.send(html);
});

app.post('/api', async (req, res) => {
    try {
        const input = req.body || {};
        const action = (input.action || '').toLowerCase();

        switch (action) {
            case 'node_status': {
                const chainResp = await rpc.call('getblockchaininfo');
                if (!chainResp.ok) {
                    return panelJson(res, true, {
                        node_online: false,
                        error: chainResp.error,
                        transport_error: chainResp.transport_error
                    });
                }
                const heightResp = await rpc.call('getblockcount');
                const hashResp = await rpc.call('getbestblockhash');
                const walletsResp = await rpc.call('listwallets');
                const ensureResp = await ensureMinerWallet();
                
                let walletInfo = null;
                let walletError = 'Miner wallet is unavailable.';
                if (ensureResp.ok) {
                    const infoResp = await rpc.forWallet(config.miner_wallet).call('getwalletinfo');
                    if (infoResp.ok) {
                        walletInfo = infoResp.data;
                        walletError = null;
                    } else {
                        walletError = infoResp.error;
                    }
                }

                const data = chainResp.data;
                const height = heightResp.ok ? parseInt(heightResp.data) : data.blocks;
                const hash = hashResp.ok ? hashResp.data : data.bestblockhash;

                return panelJson(res, true, {
                    node_online: true,
                    node: {
                        chain: data.chain,
                        blocks: data.blocks,
                        headers: data.headers,
                        height: height,
                        bestblockhash: hash,
                        initialblockdownload: data.initialblockdownload
                    },
                    wallet: walletInfo,
                    wallet_error: walletError,
                    wallets: walletsResp.ok ? walletsResp.data : [],
                    miner_wallet: config.miner_wallet,
                    miner_loaded: walletsResp.ok && walletsResp.data.includes(config.miner_wallet)
                });
            }

            case 'mine': {
                const blocks = parseInt(input.blocks) || 1;
                if (![1, 6, 100].includes(blocks)) return panelJson(res, false, null, 'Choose 1, 6, or 100 blocks.', 400);
                
                const ensureResp = await ensureMinerWallet();
                if (!ensureResp.ok) return panelJson(res, false, null, 'Could not prepare miner wallet: ' + ensureResp.error, 502);

                const addrResp = await rpc.forWallet(config.miner_wallet).call('getnewaddress', ['regtest-panel-miner']);
                if (!addrResp.ok) return panelJson(res, false, null, 'Could not create a miner address: ' + addrResp.error, 502);

                const mineResp = await rpc.call('generatetoaddress', [blocks, addrResp.data]);
                if (!mineResp.ok) {
                    logActivity('mine', `Failed to mine ${blocks} block(s): ${mineResp.error}`, false);
                    return panelJson(res, false, null, 'generatetoaddress failed: ' + mineResp.error, 502);
                }

                const hashes = mineResp.data || [];
                const latest = hashes.length > 0 ? hashes[hashes.length - 1] : 'no hash returned';
                const heightResp = await rpc.call('getblockcount');
                
                logActivity('mine', `Mined ${blocks} block(s); latest hash ${latest}`, true);
                return panelJson(res, true, { blocks, address: addrResp.data, hashes, height: heightResp.ok ? heightResp.data : null });
            }

            case 'mine_to': {
                const address = (input.address || '').trim();
                const blocks = parseInt(input.blocks);
                if (!address) return panelJson(res, false, null, 'Enter a destination address.', 400);
                if (!blocks || blocks < 1 || blocks > 1000) return panelJson(res, false, null, 'Enter a block count between 1 and 1000.', 400);

                const ensureResp = await ensureMinerWallet();
                if (!ensureResp.ok) return panelJson(res, false, null, 'Could not prepare miner wallet: ' + ensureResp.error, 502);

                const valResp = await rpc.call('validateaddress', [address]);
                if (!valResp.ok || !valResp.data.isvalid) {
                    return panelJson(res, false, null, 'Invalid address.', 400);
                }

                const mineResp = await rpc.call('generatetoaddress', [blocks, address]);
                if (!mineResp.ok) {
                    logActivity('mine_to', `Failed to mine ${blocks} block(s) to ${address}: ${mineResp.error}`, false);
                    return panelJson(res, false, null, 'generatetoaddress failed: ' + mineResp.error, 502);
                }

                const hashes = mineResp.data || [];
                logActivity('mine_to', `Mined ${blocks} block(s) to ${address}`, true);
                const heightResp = await rpc.call('getblockcount');
                return panelJson(res, true, { blocks, address, hashes, height: heightResp.ok ? heightResp.data : null });
            }

            case 'recent_transactions': {
                const ensureResp = await ensureMinerWallet();
                if (!ensureResp.ok) return panelJson(res, false, null, 'Could not prepare miner wallet: ' + ensureResp.error, 502);

                const listResp = await rpc.forWallet(config.miner_wallet).call('listtransactions', ['*', 20, 0, true]);
                if (!listResp.ok) return panelJson(res, false, null, 'listtransactions failed: ' + listResp.error, 502);

                const rows = (listResp.data || []).map(t => ({
                    txid: t.txid || '',
                    amount: t.amount !== undefined ? String(t.amount) : '',
                    confirmations: t.confirmations || 0,
                    category: t.category || '',
                    address: t.address || ''
                }));

                return panelJson(res, true, { transactions: rows, newest_first: true });
            }

            case 'mempool': {
                const ensureResp = await ensureMinerWallet();
                if (!ensureResp.ok) return panelJson(res, false, null, 'Could not prepare miner wallet: ' + ensureResp.error, 502);

                const memResp = await rpc.call('getrawmempool', [true]);
                if (!memResp.ok) return panelJson(res, false, null, 'getrawmempool failed: ' + memResp.error, 502);

                const now = Math.floor(Date.now() / 1000);
                const rows = [];
                for (const [txid, entry] of Object.entries(memResp.data || {})) {
                    const fee = entry.fees?.base || entry.fee || '';
                    const since = entry.time || 0;
                    rows.push({
                        txid,
                        vsize: entry.vsize || 0,
                        fee: String(fee),
                        time_in_mempool: since > 0 ? Math.max(0, now - since) : null,
                        since
                    });
                }
                rows.sort((a, b) => b.since - a.since);

                return panelJson(res, true, { transactions: rows, count: rows.length, empty: rows.length === 0 });
            }

            case 'send': {
                const dest = (input.address || '').trim();
                const amountSats = (input.amount_sats || '').trim();
                const label = (input.label || '').trim();

                if (!dest) return panelJson(res, false, null, 'Enter a destination address.', 400);
                if (!amountSats || !/^(0|[1-9][0-9]*)$/.test(amountSats)) {
                    return panelJson(res, false, null, 'Enter the amount as whole satoshis.', 400);
                }

                const ensureResp = await ensureMinerWallet();
                if (!ensureResp.ok) return panelJson(res, false, null, 'Could not prepare miner wallet: ' + ensureResp.error, 502);

                let satsStr = amountSats.replace(/^0+/, '');
                if (satsStr === '') satsStr = '0';
                let btcStr = '0.00000000';
                if (satsStr.length <= 8) {
                    btcStr = '0.' + satsStr.padStart(8, '0');
                } else {
                    btcStr = satsStr.slice(0, -8) + '.' + satsStr.slice(-8);
                }

                // Node.js floating point for BTC amounts is safe for RPC if passed as a string, but the node requires float sometimes.
                // We'll pass it as float for safety with bitcoin core JSON-RPC.
                const sendResp = await rpc.forWallet(config.miner_wallet).call('sendtoaddress', [dest, parseFloat(btcStr), label]);
                if (!sendResp.ok) {
                    logActivity('send', `Failed to send ${amountSats} sats to ${dest}: ${sendResp.error}`, false);
                    return panelJson(res, false, null, 'sendtoaddress failed: ' + sendResp.error, 502);
                }

                const txid = (typeof sendResp.data === 'object') ? sendResp.data.txid : sendResp.data;
                logActivity('send', `Sent ${amountSats} sats to ${dest}; txid ${txid}`, true);
                
                return panelJson(res, true, {
                    txid: txid,
                    amount_sats: amountSats,
                    amount_btc: btcStr,
                    destination: dest,
                    unconfirmed_notice: 'The transaction is unconfirmed until a block is mined.'
                });
            }

            case 'ensure_wallet': {
                const ensureResp = await ensureMinerWallet();
                if (!ensureResp.ok) {
                    logActivity('ensure_wallet', `Failed to prepare miner wallet: ${ensureResp.error}`, false);
                    return panelJson(res, false, null, 'Could not load or create miner wallet: ' + ensureResp.error, 502);
                }
                const wallets = await rpc.call('listwallets');
                const info = await rpc.forWallet(config.miner_wallet).call('getwalletinfo');
                logActivity('ensure_wallet', 'Miner wallet is loaded and ready.', true);

                return panelJson(res, true, {
                    wallet_name: config.miner_wallet,
                    wallets: wallets.ok ? wallets.data : [],
                    wallet: info.ok ? info.data : null
                });
            }

            case 'fund_chain': {
                const ensureResp = await ensureMinerWallet();
                if (!ensureResp.ok) return panelJson(res, false, null, 'Could not prepare miner wallet: ' + ensureResp.error, 502);
                
                const addrResp = await rpc.forWallet(config.miner_wallet).call('getnewaddress', ['regtest-panel-funding']);
                if (!addrResp.ok) return panelJson(res, false, null, 'Could not create miner address: ' + addrResp.error, 502);

                const mineResp = await rpc.call('generatetoaddress', [101, addrResp.data]);
                if (!mineResp.ok) {
                    logActivity('fund_chain', 'Failed to fund the chain: ' + mineResp.error, false);
                    return panelJson(res, false, null, 'Could not fund the chain: ' + mineResp.error, 502);
                }

                const hashes = mineResp.data || [];
                const heightResp = await rpc.call('getblockcount');
                logActivity('fund_chain', `Mined 101 funding blocks.`, true);

                return panelJson(res, true, {
                    blocks: 101,
                    address: addrResp.data,
                    hashes,
                    height: heightResp.ok ? heightResp.data : null,
                    message: 'Mined 101 blocks. The first coinbase reward now has 100 confirmations and is spendable.'
                });
            }

            case 'start_node': {
                const probe = await rpc.call('getblockchaininfo');
                if (probe.ok) {
                    logActivity('start_node', 'Start requested, but the node is already accepting RPC requests.', true);
                    return panelJson(res, true, { started: false, already_running: true, message: 'The node is already running.' });
                }
                if (!probe.transport_error) {
                    logActivity('start_node', `Start refused because RPC is reachable but not ready: ${probe.error}`, false);
                    return panelJson(res, false, null, `The node RPC endpoint is reachable but not ready: ${probe.error}`, 409);
                }

                ensureDir(config.data_dir);
                const outPath = path.join(config.log_dir, 'node.out');
                // Cross platform detachment
                const child = spawn(config.bitcoind_path, ['-regtest', `-datadir=${config.data_dir}`, `-conf=${config.bitcoin_conf}`], {
                    detached: true,
                    stdio: ['ignore', fs.openSync(outPath, 'a'), fs.openSync(outPath, 'a')]
                });
                child.unref();

                logActivity('start_node', 'Detached bitcoind start command sent.', true);
                return panelJson(res, true, { started: true, already_running: false, message: 'Node start command sent.' });
            }

            case 'stop_node': {
                exec(`"${config.root_dir}/stop-node.bat"`, (err) => {
                    // Let it run in background
                });
                logActivity('stop_node', 'Detached node stop command sent.', true);
                return panelJson(res, true, { stopped: true, message: 'Stop command sent.' });
            }

            case 'nuke_reset': {
                exec(`"${config.root_dir}/reset-chain.bat"`, (err) => {
                    // Let it run in background
                });
                logActivity('nuke_reset', 'Detached nuke reset command sent.', true);
                return panelJson(res, true, { nuked: true, message: 'Chain reset command sent. This will take a few seconds.' });
            }

            case 'update_credentials': {
                const newUser = (input.rpc_user || '').trim();
                const newPass = (input.rpc_password || '').trim();
                if (!newUser || !newPass) return panelJson(res, false, null, 'RPC user and password cannot be empty.');

                config.rpc_user = newUser;
                config.rpc_password = newPass;
                fs.writeFileSync(configPath, JSON.stringify(config, null, 2));

                if (fs.existsSync(config.bitcoin_conf)) {
                    let confText = fs.readFileSync(config.bitcoin_conf, 'utf-8');
                    confText = confText.replace(/^rpcuser=.*$/m, `rpcuser=${newUser}`);
                    confText = confText.replace(/^rpcpassword=.*$/m, `rpcpassword=${newPass}`);
                    if (!confText.includes(`rpcuser=${newUser}`)) confText += `\nrpcuser=${newUser}`;
                    if (!confText.includes(`rpcpassword=${newPass}`)) confText += `\nrpcpassword=${newPass}`;
                    fs.writeFileSync(config.bitcoin_conf, confText);
                }

                return panelJson(res, true, { message: 'Credentials updated successfully.' });
            }

            case 'activity': {
                const actPath = path.join(config.log_dir, 'activity.jsonl');
                if (!fs.existsSync(actPath)) return panelJson(res, true, []);
                
                const lines = fs.readFileSync(actPath, 'utf-8').split('\n').filter(l => l.trim() !== '');
                const acts = lines.map(l => {
                    try {
                        const d = JSON.parse(l);
                        return {
                            time: new Date((d.time || Date.now()/1000) * 1000).toISOString().replace('T', ' ').slice(0, 19),
                            action: d.action || '',
                            detail: d.message || '',
                            ok: d.ok || false
                        };
                    } catch (e) { return null; }
                }).filter(a => a !== null).reverse();
                
                return panelJson(res, true, acts);
            }

            default:
                return panelJson(res, false, null, 'Unknown action.', 404);
        }
    } catch (e) {
        return panelJson(res, false, null, 'Server error: ' + e.message, 500);
    }
});

app.listen(PORT, () => {
    console.log(`Server is running at http://localhost:${PORT}`);
});
