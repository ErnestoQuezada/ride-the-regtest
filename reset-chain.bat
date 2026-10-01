@echo off
setlocal EnableExtensions
set "ROOT=%~dp0"
set "MINER=miner"
set "LOGDIR=C:\xampp\regtest-panel-logs"
set "CONF_BAK=%TEMP%\regtest-panel-bitcoin.conf"

if not exist "%ROOT%bitcoind.exe" (
    echo bitcoind.exe was not found in %ROOT%
    exit /b 1
)

if not exist "%ROOT%bitcoin-cli.exe" (
    echo bitcoin-cli.exe was not found in %ROOT%
    exit /b 1
)

if not exist "%ROOT%data\bitcoin.conf" (
    echo Missing %ROOT%data\bitcoin.conf - run setup.php first.
    exit /b 1
)

if not exist "%LOGDIR%" mkdir "%LOGDIR%" >nul 2>&1

echo Stopping bitcoind...
"%ROOT%bitcoin-cli.exe" -regtest -datadir="%ROOT%data" -conf="%ROOT%data\bitcoin.conf" stop >nul 2>&1

set /a TRIES=0
:WAIT_STOP
"%ROOT%bitcoin-cli.exe" -regtest -datadir="%ROOT%data" -conf="%ROOT%data\bitcoin.conf" getblockchaininfo >nul 2>&1
if errorlevel 1 goto NODE_STOPPED
set /a TRIES+=1
if %TRIES% GEQ 30 (
    echo ERROR: bitcoind did not stop in time. Data was not deleted.
    exit /b 1
)
timeout /t 1 /nobreak >nul
goto WAIT_STOP

:NODE_STOPPED
copy /y "%ROOT%data\bitcoin.conf" "%CONF_BAK%" >nul
if errorlevel 1 (
    echo ERROR: Could not back up bitcoin.conf. Data was not deleted.
    exit /b 1
)

rmdir /s /q "%ROOT%data"
if exist "%ROOT%data" (
    del /f /q "%CONF_BAK%" >nul 2>&1
    echo ERROR: Could not delete the data directory. Is bitcoind still running?
    exit /b 1
)

mkdir "%ROOT%data"
copy /y "%CONF_BAK%" "%ROOT%data\bitcoin.conf" >nul
del /f /q "%CONF_BAK%" >nul 2>&1
echo Require all denied > "%ROOT%data\.htaccess"

echo Starting bitcoind...
start "" /B "%ROOT%bitcoind.exe" -regtest -datadir="%ROOT%data" -conf="%ROOT%data\bitcoin.conf" >> "%LOGDIR%\node.out" 2>&1

set /a TRIES=0
:WAIT_START
"%ROOT%bitcoin-cli.exe" -regtest -datadir="%ROOT%data" -conf="%ROOT%data\bitcoin.conf" getblockchaininfo >nul 2>&1
if errorlevel 1 goto STILL_WAITING
goto NODE_STARTED

:STILL_WAITING
set /a TRIES+=1
if %TRIES% GEQ 90 (
    echo ERROR: bitcoind started but RPC did not become ready. Check node.out and data\regtest\debug.log.
    exit /b 1
)
timeout /t 1 /nobreak >nul
goto WAIT_START

:NODE_STARTED
echo Creating miner wallet...
"%ROOT%bitcoin-cli.exe" -regtest -datadir="%ROOT%data" -conf="%ROOT%data\bitcoin.conf" createwallet "%MINER%" false false "" false true false
if errorlevel 1 (
    echo ERROR: Could not create the miner wallet.
    exit /b 1
)

set "ADDR="
for /f "usebackq delims=" %%A in (`"%ROOT%bitcoin-cli.exe" -regtest -datadir="%ROOT%data" -conf="%ROOT%data\bitcoin.conf" -rpcwallet="%MINER%" getnewaddress`) do set "ADDR=%%A"

if not defined ADDR (
    echo ERROR: Could not create a miner address.
    exit /b 1
)

echo Mining 101 initial blocks...
"%ROOT%bitcoin-cli.exe" -regtest -datadir="%ROOT%data" -conf="%ROOT%data\bitcoin.conf" -rpcwallet="%MINER%" generatetoaddress 101 "%ADDR%"
exit /b %ERRORLEVEL%