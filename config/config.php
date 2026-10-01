<?php

declare(strict_types=1);

return array (
  'rpc_url' => 'http://127.0.0.1:18443/',
  'rpc_user' => 'user',
  'rpc_password' => 'password',
  'admin_password' => 'admin123',
  'miner_wallet' => 'miner',
  'root_dir' => 'C:\\xampp\\htdocs\\regtest-panel',
  'data_dir' => 'C:\\xampp\\htdocs\\regtest-panel\\data',
  'bitcoin_conf' => 'C:\\xampp\\htdocs\\regtest-panel\\data\\bitcoin.conf',
  'bitcoind_path' => 'C:\\xampp\\htdocs\\regtest-panel\\bitcoind.exe',
  'bitcoin_cli_path' => 'C:\\xampp\\htdocs\\regtest-panel\\bitcoin-cli.exe',
  'log_dir' => 'C:\\xampp\\regtest-panel-logs',
  'rate_limits' => 
  array (
    'mine' => 
    array (
      0 => 
      array (
        'window' => 60,
        'limit' => 120,
      ),
      1 => 
      array (
        'window' => 300,
        'limit' => 300,
      ),
    ),
    'send' => 
    array (
      0 => 
      array (
        'window' => 60,
        'limit' => 10,
      ),
      1 => 
      array (
        'window' => 300,
        'limit' => 30,
      ),
    ),
  ),
);
