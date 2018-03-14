<?php

use Net\Connection;
use Net\Listener;
use Net\Loop;
use SmDbgpProxy\IdeReg;
use SmDbgpProxy\Proxy;

foreach ([__DIR__ . '/../../../autoload.php', __DIR__ . '/../vendor/autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require_once $autoload;
        break;
    }
}

$ensure_extensions = function (array $exts) {
    foreach ($exts as $ext) {
        if (!extension_loaded($ext) && !dl("$ext.so")) throw new \Exception("could not load extension $ext");
    }
};
$ensure_extensions(['filter', 'simplexml']);


$config = require __DIR__ . '/../config.php';

$softmocks_path = $config['mocks_cache_path'] . $config['phpversion'] . $config['parser_version'] . $config['softmocks_hash'];

Proxy::setPaths($softmocks_path, $config['project_path'], $config['project_path_local'], $config['do_not_translate_local']);

$loop = new Loop();

$idereg = Listener::listen('0.0.0.0:9001', $loop);
$idereg->on(Listener::EVENT_CONNECTION, function (Connection $conn) {
    echo 'ideReg connected ' . $conn->getAddress() . "\n";
    $IdeReg = new IdeReg($conn);
    $IdeReg->on(IdeReg::EVENT_IDEREG, function ($key, $ide_host, $port) {
        echo "new ide reg key:$key $ide_host:$port\n";
        Proxy::addIde($key, $ide_host, $port);
    });

});
echo 'idereg server at ' . $idereg->getAddress() . "\n";

$xdebug = Listener::listen('0.0.0.0:9002', $loop);
$xdebug->on(Listener::EVENT_CONNECTION, function (Connection $conn) use ($loop) {
    echo 'xdebug connected ' . $conn->getAddress() . "\n";
    new Proxy($conn, $loop);
});
echo 'dbgp server at ' . $xdebug->getAddress() . "\n";

foreach ($config['prereg'] as $ide) {
    Proxy::addIde($ide['key'], $ide['host'], $ide['port']);
}

$loop->run();
