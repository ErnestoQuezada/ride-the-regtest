const $ = (id) => document.getElementById(id);
    const shareUrl = window.PANEL_URLS.lan || window.PANEL_URLS.current || window.location.href;
    let refreshing = false;
    let nodeIsOnline = false;

    // Tab Navigation Logic
    document.querySelectorAll('.nav-link').forEach(link => {
        link.addEventListener('click', (e) => {
            document.querySelectorAll('.nav-link').forEach(l => l.classList.remove('active'));
            e.target.classList.add('active');
            
            const targetId = e.target.getAttribute('data-target');
            document.querySelectorAll('.page').forEach(page => {
                page.classList.remove('active');
            });
            $(targetId).classList.add('active');
        });
    });

    // Node Toggle logic
    $('header-node-toggle').addEventListener('click', () => {
        if (nodeIsOnline) {
            void withBusy($('header-node-toggle'), async () => {
                const data = await api('stop_node');
                toast(data.message || 'Bitcoin Core stopping.', 'info');
                window.setTimeout(() => void refreshAll(), 3000);
            });
        } else {
            startNode($('header-node-toggle'));
        }
    });

    function setText(id, value) {
        $(id).textContent = value;
    }

    function toast(message, type = 'info', duration = 6500) {
        const element = document.createElement('div');
        element.className = `toast ${type}`;
        element.textContent = String(message);
        $('toast-root').appendChild(element);

        window.setTimeout(() => {
            element.remove();
        }, duration);
    }

    async function api(action, payload = {}) {
        const response = await fetch('api.php', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ action, ...payload })
        });

        let result;
        try {
            result = await response.json();
        } catch {
            throw new Error(`API returned a non-JSON response (HTTP ${response.status}). Check the Apache/PHP error log.`);
        }

        if (!result || typeof result.ok === 'undefined') {
            throw new Error('API returned an unexpected response shape.');
        }

        if (!result.ok) {
            const error = new Error(result.error || `Request failed with HTTP ${response.status}.`);
            error.retryAfter = result.retry_after || null;
            throw error;
        }

        return result.data;
    }

    async function withBusy(button, work) {
        if (!button || button.disabled) {
            return;
        }

        const originalLabel = button.textContent;
        button.disabled = true;
        button.textContent = 'Working…';

        try {
            await work();
        } catch (error) {
            toast(error.message || String(error), 'error', 9000);
        } finally {
            button.disabled = false;
            button.textContent = originalLabel;
        }
    }

    async function refreshAll() {
        await refreshDashboard();
        await loadActivity();
    }

    async function refreshDashboard() {
        if (refreshing) {
            return;
        }

        refreshing = true;

        try {
            const status = await api('node_status');
            renderNodeStatus(status);

            if (!status || status.node_online === false) {
                renderTransactions({ transactions: [] });
                renderMempool({
                    transactions: [],
                    unavailable: status && status.error ? status.error : 'Node is offline.'
                });
                return;
            }

            try {
                renderTransactions(await api('recent_transactions'));
            } catch {
                renderTransactions({ transactions: [], unavailable: 'Recent transactions unavailable.' });
            }

            try {
                renderMempool(await api('mempool'));
            } catch {
                renderMempool({ transactions: [], unavailable: 'Mempool unavailable.' });
            }
        } catch (error) {
            showNodeBanner(error.message || String(error));
        } finally {
            refreshing = false;
        }
    }

    function showNodeBanner(message) {
        setText('node-banner-message', message || 'Unknown node error.');
        $('node-banner').classList.remove('hidden');
    }

    function hideNodeBanner() {
        $('node-banner').classList.add('hidden');
    }

    function isZeroAmount(value) {
        return value === null || value === undefined || /^(?:0(?:\.0+)?)?$/.test(String(value).trim());
    }

    function btcText(value) {
        return value === null || value === undefined || value === '' ? '—' : `${value} BTC`;
    }

    function renderNodeStatus(status) {
        const toggleBtn = $('header-node-toggle');

        if (!status || status.node_online === false) {
            nodeIsOnline = false;
            toggleBtn.textContent = 'Start Node';
            toggleBtn.className = 'primary btn-large';

            showNodeBanner(status && status.error ? status.error : 'Node is offline.');
            setText('stat-height', '—');
            setText('best-hash', '—');
            setText('stat-mempool', '—');
            setText('stat-balance', '—');
            setText('stat-immature', '—');
            setText('send-balance', '—');
            setText('node-chain', '—');
            setText('node-blocks', '—');
            setText('node-ibd', '—');
            setText('wallet-name', '—');
            setText('wallet-unconfirmed', '—');
            setText('wallet-status', 'Node offline');
            renderWallets([]);
                        return;
        }

        nodeIsOnline = true;
        toggleBtn.textContent = 'Stop Node';
        toggleBtn.className = 'danger btn-large';

        hideNodeBanner();

        const node = status.node || {};
        const wallet = status.wallet || null;

        setText('stat-height', String(node.height ?? node.blocks ?? '—'));
        setText('best-hash', node.bestblockhash || '—');
        setText('node-chain', node.chain || '—');
        setText('node-blocks', `${node.blocks ?? '—'} / ${node.headers ?? '—'}`);
        setText('node-ibd', node.initialblockdownload ? 'Yes' : 'No');

        if (wallet) {
            setText('stat-balance', btcText(wallet.balance));
            setText('send-balance', btcText(wallet.balance));
            setText('stat-immature', btcText(wallet.immature_balance));
            setText('wallet-name', status.miner_wallet || '—');
            setText('wallet-unconfirmed', btcText(wallet.unconfirmed_balance));
                    } else {
            setText('stat-balance', '—');
            setText('send-balance', '—');
            setText('stat-immature', '—');
            setText('wallet-name', status.miner_wallet || '—');
            setText('wallet-unconfirmed', '—');
                    }

        setText(
            'wallet-status',
            status.wallet_error
                ? `Wallet error: ${status.wallet_error}`
                : `Miner wallet "${status.miner_wallet}" is ready.`
        );

        renderWallets(Array.isArray(status.wallets) ? status.wallets : []);
    }

    function renderWallets(wallets) {
        const container = $('loaded-wallets');
        container.replaceChildren();

        if (!wallets.length) {
            container.textContent = 'No wallets loaded';
            return;
        }

        wallets.forEach((wallet) => {
            const chip = document.createElement('span');
            chip.className = 'chip';
            chip.textContent = String(wallet);
            container.appendChild(chip);
        });
    }

    function createCell(text) {
        const cell = document.createElement('td');
        cell.textContent = text;
        return cell;
    }

    function renderTransactions(payload) {
        const body = $('tx-body');
        body.replaceChildren();

        const rows = Array.isArray(payload && payload.transactions) ? payload.transactions : [];

        if (payload && payload.unavailable) {
            const row = document.createElement('tr');
            const cell = createCell(payload.unavailable);
            cell.colSpan = 5;
            cell.className = 'empty';
            row.appendChild(cell);
            body.appendChild(row);
            return;
        }

        if (!rows.length) {
            const row = document.createElement('tr');
            const cell = createCell('No wallet transactions yet.');
            cell.colSpan = 5;
            cell.className = 'empty';
            row.appendChild(cell);
            body.appendChild(row);
            return;
        }

        rows.forEach((transaction) => {
            const row = document.createElement('tr');
            row.appendChild(createCell(transaction.txid || ''));
            row.appendChild(createCell(transaction.amount === '' ? '' : `${transaction.amount} BTC`));
            row.appendChild(createCell(String(transaction.confirmations ?? 0)));
            row.appendChild(createCell(transaction.category || ''));
            row.appendChild(createCell(transaction.address || ''));
            body.appendChild(row);
        });
    }

    function ageText(seconds) {
        if (seconds === null || seconds === undefined) {
            return '—';
        }

        const value = Number(seconds);
        if (!Number.isFinite(value) || value < 0) {
            return '—';
        }

        const whole = Math.floor(value);

        if (whole < 60) {
            return `${whole}s`;
        }

        const minutes = Math.floor(whole / 60);
        if (minutes < 60) {
            return `${minutes}m ${whole % 60}s`;
        }

        const hours = Math.floor(minutes / 60);
        return `${hours}h ${minutes % 60}m`;
    }

    function renderMempool(payload) {
        const body = $('mempool-body');
        body.replaceChildren();

        const rows = Array.isArray(payload && payload.transactions) ? payload.transactions : [];
        setText('stat-mempool', String(rows.length));

        if (payload && payload.unavailable) {
            const row = document.createElement('tr');
            const cell = createCell(payload.unavailable);
            cell.colSpan = 4;
            cell.className = 'empty';
            row.appendChild(cell);
            body.appendChild(row);
            return;
        }

        if (!rows.length) {
            const row = document.createElement('tr');
            const cell = createCell('Mempool is empty.');
            cell.colSpan = 4;
            cell.className = 'empty';
            row.appendChild(cell);
            body.appendChild(row);
            return;
        }

        rows.forEach((transaction) => {
            const row = document.createElement('tr');
            row.appendChild(createCell(transaction.txid || ''));
            row.appendChild(createCell(transaction.vsize === undefined ? '' : `${transaction.vsize} vB`));
            row.appendChild(createCell(transaction.fee === '' ? '' : `${transaction.fee} BTC`));
            row.appendChild(createCell(ageText(transaction.time_in_mempool)));
            body.appendChild(row);
        });
    }

    async function loadActivity() {
        try {
            const entries = await api('activity');
            renderActivity(Array.isArray(entries) ? entries : []);
        } catch (error) {
            console.error(error);
        }
    }

    function renderActivity(entries) {
        const container = $('activity-list');
        container.replaceChildren();

        if (!entries.length) {
            const empty = document.createElement('div');
            empty.className = 'empty';
            empty.textContent = 'No activity yet.';
            container.appendChild(empty);
            return;
        }

        entries.forEach((entry) => {
            const line = document.createElement('div');
            line.className = 'log-line';

            const time = document.createElement('span');
            time.className = 'log-time';
            time.textContent = entry.time || '';

            const action = document.createElement('span');
            action.textContent = entry.action || '';

            const result = document.createElement('span');
            result.textContent = entry.ok ? 'OK' : 'FAIL';
            result.className = entry.ok ? 'ok-text' : 'danger-text';

            const detail = document.createElement('span');
            detail.className = 'log-detail';
            detail.textContent = entry.detail || '';

            line.append(time, action, result, detail);
            container.appendChild(line);
        });
    }

    async function copyText(text) {
        if (!text || text === '—') {
            throw new Error('There is nothing to copy yet.');
        }

        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
            return;
        }

        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'fixed';
        textarea.style.left = '-9999px';
        document.body.appendChild(textarea);
        textarea.focus();
        textarea.select();

        let copied = false;
        try {
            copied = document.execCommand('copy');
        } finally {
            textarea.remove();
        }

        if (!copied) {
            throw new Error('Copy failed. Select the text manually.');
        }
    }

    document.querySelectorAll('[data-mine]').forEach((button) => {
        button.addEventListener('click', () => {
            void withBusy(button, async () => {
                const data = await api('mine', { blocks: Number(button.dataset.mine) });
                const hashes = Array.isArray(data.hashes) ? data.hashes : [];
                const latest = hashes.length ? hashes[hashes.length - 1] : 'no hash returned';
                toast(`Mined ${data.blocks} block(s). Height: ${data.height}. Latest hash: ${latest}`, 'success', 9000);
                await refreshAll();
            });
        });
    });

    $('mine-to-form').addEventListener('submit', (event) => {
        event.preventDefault();

        const button = $('mine-to-form').querySelector('button[type="submit"]');
        void withBusy(button, async () => {
            const blocks = Number($('mine-to-blocks').value.trim());

            if (!Number.isInteger(blocks) || blocks < 1 || blocks > 1000) {
                throw new Error('Enter a block count between 1 and 1000.');
            }

            const data = await api('mine_to', {
                address: $('mine-to-address').value.trim(),
                blocks
            });

            const hashes = Array.isArray(data.hashes) ? data.hashes : [];
            const latest = hashes.length ? hashes[hashes.length - 1] : 'no hash returned';
            toast(`Mined ${data.blocks} block(s) to ${data.address}. Latest hash: ${latest}`, 'success', 9000);
            await refreshAll();
        });
    });

    $('send-form').addEventListener('submit', (event) => {
        event.preventDefault();

        const button = $('send-form').querySelector('button[type="submit"]');
        void withBusy(button, async () => {
            const sats = $('send-sats').value.trim();

            if (!/^(?:0|[1-9]\d*)$/.test(sats)) {
                throw new Error('Enter the amount as whole satoshis, without decimals or spaces.');
            }

            const data = await api('send', {
                address: $('send-address').value.trim(),
                amount_sats: sats,
                label: $('send-label').value.trim()
            });

            toast(
                `Sent ${data.amount_sats} sats. Tx: ${data.txid}. ${data.unconfirmed_notice}`,
                'success',
                11000
            );
            $('send-form').reset();
            await refreshAll();
        });
    });

    $('ensure-wallet').addEventListener('click', () => {
        void withBusy($('ensure-wallet'), async () => {
            const data = await api('ensure_wallet');
            toast(`Miner wallet "${data.wallet_name}" is ready.`, 'success');
            await refreshAll();
        });
    });

    $('fund-chain').addEventListener('click', () => {
        if (!window.confirm('Mine 101 blocks to the miner wallet and make the first reward spendable?')) {
            return;
        }

        void withBusy($('fund-chain'), async () => {
            const data = await api('fund_chain');
            toast(data.message || 'Funded the chain.', 'success', 10000);
            await refreshAll();
        });
    });

    async function startNode(button) {
        await withBusy(button, async () => {
            const data = await api('start_node');
            toast(data.message || 'Start command sent.', data.already_running ? 'info' : 'success');
            window.setTimeout(() => void refreshAll(), 1500);
        });
    }

    $('start-node-banner').addEventListener('click', () => void startNode($('start-node-banner')));

    $('nuke-reset').addEventListener('click', () => {
        void withBusy($('nuke-reset'), async () => {
            const password = $('admin-password').value;
            const confirmation = $('reset-confirm').value.trim();

            if (!password) {
                throw new Error('Enter the admin password.');
            }

            if (confirmation !== 'RESET') {
                throw new Error('Type RESET exactly to confirm.');
            }

            if (!window.confirm('This permanently deletes the regtest chain and every classroom wallet. Continue?')) {
                return;
            }

            const data = await api('nuke_reset', {
                admin_password: password,
                confirm: confirmation
            });

            $('reset-confirm').value = '';
            toast(data.message || 'Reset complete.', 'success', 12000);
            await refreshAll();
        });
    });

    $('copy-url').addEventListener('click', async () => {
        try {
            await copyText(shareUrl);
            toast('Panel URL copied.', 'success');
        } catch (error) {
            toast(error.message || String(error), 'error');
        }
    });

    $('copy-best-hash').addEventListener('click', async () => {
        try {
            await copyText($('best-hash').textContent.trim());
            toast('Block hash copied.', 'success');
        } catch (error) {
            toast(error.message || String(error), 'error');
        }
    });

    setText('lan-url', shareUrl);

    window.setInterval(() => {
        void refreshAll().catch((error) => console.error(error));
    }, 5000);

    void refreshAll().catch((error) => console.error(error));

const credForm = $('update-cred-form');
if (credForm) {
    credForm.addEventListener('submit', (event) => {
        event.preventDefault();
        const btn = credForm.querySelector('button[type="submit"]');
        void withBusy(btn, async () => {
            const rpcUser = $('new-rpc-user').value.trim();
            const rpcPass = $('new-rpc-pass').value.trim();
            const data = await api('update_credentials', { rpc_user: rpcUser, rpc_password: rpcPass });
            toast(data.message || 'Credentials updated successfully. Node must be restarted to take effect.', 'success', 8000);
            
            // Optionally, we could ask to restart the node automatically
            if (window.confirm("Credentials updated! Do you want to restart the node now to apply changes?")) {
                await api('stop_node');
                window.setTimeout(() => {
                    startNode($('header-node-toggle'));
                }, 4000);
            }
        });
    });
}

const toggleRpcPass = $('toggle-rpc-pass');
if (toggleRpcPass) {
    toggleRpcPass.addEventListener('click', () => {
        const passInput = $('new-rpc-pass');
        if (passInput.type === 'password') {
            passInput.type = 'text';
            toggleRpcPass.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" class="eye-off-icon"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>';
        } else {
            passInput.type = 'password';
            toggleRpcPass.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" class="eye-icon"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>';
        }
    });
}
