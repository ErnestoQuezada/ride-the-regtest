\# Regtest Control Panel



A plain-PHP/XAMPP control panel for a Bitcoin Core \*\*regtest\*\* node on Windows 11.

<img src="images/screenshot.png" width="400" alt="Ride The Regtest Screenshot">



\- PHP 8 + Apache + cURL

\- No Node.js

\- No Composer packages

\- No CDN assets

\- Browser never receives RPC credentials

\- Normal node operations use JSON-RPC, not `bitcoin-cli.exe`

\- `exec()` is used only to launch `bitcoind.exe` detached

\- RPC is bound to `127.0.0.1`; only Apache is exposed to the LAN



\## 1. Install the project



Copy the project to:



```text

C:\\xampp\\htdocs\\regtest-panel

```



Copy Bitcoin Core's executables into the same directory:



```text

C:\\xampp\\htdocs\\regtest-panel\\bitcoind.exe

C:\\xampp\\htdocs\\regtest-panel\\bitcoin-cli.exe

```



Use matching versions of `bitcoind.exe` and `bitcoin-cli.exe`.



\## 2. Run setup



Open Command Prompt and run:



```bat

cd /d C:\\xampp\\htdocs\\regtest-panel

C:\\xampp\\php\\php.exe setup.php

```



Save the printed admin password. Setup creates:



```text

C:\\xampp\\htdocs\\regtest-panel\\config\\config.php

C:\\xampp\\htdocs\\regtest-panel\\data\\bitcoin.conf

C:\\xampp\\regtest-panel-logs\\

```



The generated `bitcoin.conf` contains:



```ini

regtest=1

server=1

txindex=1

listen=0

fallbackfee=0.0001

rpcuser=\&lt;random\&gt;

rpcpassword=\&lt;random\&gt;

rpcbind=127.0.0.1

rpcallowip=127.0.0.1

```



`listen=0` prevents a regtest P2P listener from becoming another LAN-facing service.



To rotate the RPC/admin credentials later, stop the node and run:



```bat

C:\\xampp\\php\\php.exe C:\\xampp\\htdocs\\regtest-panel\\setup.php --force

```



\## 3. Start Bitcoin Core



Either:



\- open `start-node.bat`, or

\- open the panel and click \*\*Start node\*\*.



Then open:



```text

http://127.0.0.1/regtest-panel/

```



On a fresh chain, click:



```text

Regtest utilities → Fund the chain: mine 101

```



\### Why 101 blocks?



Regtest coinbase outputs require \*\*100 confirmations\*\*. At height 101, the block-1 reward has 100 confirmations and becomes spendable. The wallet card prominently displays the \*\*immature balance\*\* so classmates can see why a newly mined reward cannot yet be spent.



\## 4. Find your LAN IP



Run:



```bat

ipconfig

```



Look under your active Ethernet or Wi-Fi adapter for an address like:



```text

IPv4 Address. . . . . . . . . . . : 192.168.1.50

```



Classmates should open:



```text

http://192.168.1.50/regtest-panel/

```



The panel's \*\*Copy panel LAN URL\*\* button uses the detected address, but `ipconfig` is the authoritative check.



Tell classmates:



\&gt; This is a valueless Bitcoin regtest network. Use the mining buttons to create blocks. A send remains unconfirmed until someone mines a block. Do not use mainnet or testnet addresses.



Regtest addresses normally look like `bcrt1...`; regtest P2SH/script-hash addresses are also valid.



\## 5. Required 403 checks



From your machine and preferably from a classmate's machine, verify all of these return \*\*403 Forbidden\*\*:



```text

http://\&lt;LAN-IP\&gt;/regtest-panel/config/config.php

http://\&lt;LAN-IP\&gt;/regtest-panel/data/

http://\&lt;LAN-IP\&gt;/regtest-panel/bitcoind.exe

http://\&lt;LAN-IP\&gt;/regtest-panel/start-node.bat

```



You can also test locally:



```bat

curl.exe -I http://127.0.0.1/regtest-panel/config/config.php

curl.exe -I http://127.0.0.1/regtest-panel/data/

```



Both must return HTTP 403.



If they return 200, 404, or a blank page, Apache is probably ignoring `.htaccess`. Edit:



```text

C:\\xampp\\apache\\conf\\httpd.conf

```



Ensure the `htdocs` directory allows overrides:



```apache

\&lt;Directory "C:/xampp/htdocs"\&gt;

&#x20;   AllowOverride All

&#x20;   Require all granted

\&lt;/Directory\&gt;

```



Restart Apache afterward.



Also make sure the file is named exactly `.htaccess`, not `.htaccess.txt`.



\## 6. Windows Firewall and LAN access



The first time Apache accepts LAN traffic, Windows Defender Firewall may prompt. Allow \*\*Apache HTTP Server\*\* on \*\*Private networks\*\*.



If there is no prompt, open PowerShell as Administrator and allow only the Apache port on Private networks:



```powershell

New-NetFirewallRule `

&#x20; -DisplayName "XAMPP Apache LAN 80" `

&#x20; -Direction Inbound `

&#x20; -Protocol TCP `

&#x20; -LocalPort 80 `

&#x20; -Action Allow `

&#x20; -Profile Private

```



If Apache uses port 8080, replace both occurrences of `80` with `8080`.



Do not add an inbound firewall rule for TCP 18443. Bitcoin RPC must remain localhost-only.



If classmates still cannot connect:



1\. Confirm they are on the same network.

2\. Check that the network profile is Private, not Public.

3\. Check router/AP client isolation.

4\. Run:



&#x20;  ```bat

&#x20;  netstat -ano | findstr :80

&#x20;  ```



&#x20;  `0.0.0.0:80` or `\[::]:80` means Apache is listening on all interfaces. `127.0.0.1:80` means localhost-only; see the next section.



\## 7. If Apache is bound to localhost only



If `netstat` shows only:



```text

127.0.0.1:80

```



edit `C:\\xampp\\apache\\conf\\httpd.conf` and change:



```apache

Listen 127.0.0.1:80

```



to either:



```apache

Listen 0.0.0.0:80

```



or simply:



```apache

Listen 80

```



Restart Apache.



Keep the firewall rule restricted to Private networks.



\## 8. If port 80 is already taken



Common occupants include IIS/World Wide Web Publishing Service, Skype, or another web server.



Edit:



```text

C:\\xampp\\apache\\conf\\httpd.conf

```



Change:



```apache

Listen 80

ServerName localhost:80

```



to:



```apache

Listen 8080

ServerName localhost:8080

```



Restart Apache. Your local URL becomes:



```text

http://127.0.0.1:8080/regtest-panel/

```



The classmates' URL becomes:



```text

http://\&lt;LAN-IP\&gt;:8080/regtest-panel/

```



Allow TCP 8080 through the Private firewall profile.



To identify the process using port 80:



```bat

netstat -ano | findstr :80

tasklist /FI "PID eq \&lt;PID\&gt;"

```



\## 9. Access model



Mining, sending, and all read-only views are open.



Admin password is required only for:



\- Stop node

\- Nuke and restart



\*\*Nuke and restart\*\* requires typing `RESET`. It:



1\. stops bitcoind,

2\. deletes the complete data directory,

3\. recreates `bitcoin.conf`,

4\. restarts bitcoind,

5\. recreates the miner wallet,

6\. mines 101 blocks.



It destroys everyone's chain and wallet state.



Default global rate limits:



\- Mining: 120 blocks per 60 seconds; 300 blocks per 5 minutes

\- Sending: 10 sends per 60 seconds; 30 sends per 5 minutes



They are shared by everyone because the panel is a single classroom node. Adjust them in `config/config.php`.



\## 10. Logs and troubleshooting



Browser-visible state:



```text

http://\&lt;LAN-IP\&gt;/regtest-panel/

```



Private activity/rate-limit files:



```text

C:\\xampp\\regtest-panel-logs\\activity.log

C:\\xampp\\regtest-panel-logs\\ratelimit.json

C:\\xampp\\regtest-panel-logs\\node.out

```



Bitcoin Core log:



```text

C:\\xampp\\htdocs\\regtest-panel\\data\\regtest\\debug.log

```



Useful CLI test:



```bat

cd /d C:\\xampp\\htdocs\\regtest-panel

cli.bat getblockchaininfo

cli.bat getblockcount

cli.bat getwalletinfo

```



If the panel reports a cURL error, Bitcoin Core is stopped, still starting, or RPC credentials are wrong.



If PHP reports a missing cURL extension, enable it in `C:\\xampp\\php\\php.ini`:



```ini

extension=curl

```



Then restart Apache.



If the API returns a non-JSON response, check:



```text

C:\\xampp\\apache\\logs\\error.log

```



Make sure all PHP files were saved as \*\*UTF-8 without BOM\*\*. A BOM before `\&lt;?php` can break JSON headers.



\## 11. Security notes



\- `config/config.php` contains secrets and is denied by Apache.

\- `data/` contains wallet/chain data and is denied by Apache.

\- `.exe`, `.bat`, `.log`, and `.conf` downloads are denied.

\- RPC credentials never reach the browser.

\- RPC listens only on `127.0.0.1:18443`.

\- Regtest P2P listening is disabled with `listen=0`.

\- Only Apache is exposed to the classroom LAN.



Admin actions use HTTP on the LAN. For a trusted classroom network this is usually acceptable, but perform destructive admin operations from the local machine if you want to avoid sending the admin password across the network.



## Configuration & Environment Variables

- `.env`: Holds environment-specific overrides like `TAILSCALE_IP`. Create this file if you need to override the default IP logic.
- `regtest-seed.txt`: Ignored by Git to prevent accidentally pushing your generated seed words.
