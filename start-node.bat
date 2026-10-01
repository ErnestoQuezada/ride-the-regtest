@echo off
setlocal
set "ROOT=%~dp0"
set "LOGDIR=C:\xampp\regtest-panel-logs"

if not exist "%ROOT%bitcoind.exe" (
    echo bitcoind.exe was not found in %ROOT%
    exit /b 1
)

if not exist "%ROOT%data\bitcoin.conf" (
    echo Missing %ROOT%data\bitcoin.conf - run setup.php first.
    exit /b 1
)

if not exist "%LOGDIR%" mkdir "%LOGDIR%" >nul 2>&1

rem /B starts bitcoind detached so this batch does not stay attached to it.
start "" /B "%ROOT%bitcoind.exe" -regtest -datadir="%ROOT%data" -conf="%ROOT%data\bitcoin.conf" >> "%LOGDIR%\node.out" 2>&1
pause
exit /b %ERRORLEVEL%

