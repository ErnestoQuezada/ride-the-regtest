@echo off
setlocal
set "ROOT=%~dp0"

if not exist "%ROOT%bitcoin-cli.exe" (
    echo bitcoin-cli.exe was not found in %ROOT%
    exit /b 1
)

"%ROOT%bitcoin-cli.exe" -regtest -datadir="%ROOT%data" -conf="%ROOT%data\bitcoin.conf" %*
exit /b %ERRORLEVEL%