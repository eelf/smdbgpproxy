<?php
/**
 * 1) run individual proxy handling soft-mocks rewritings:
$ php bin/proxy.php -xdebug=0:9002 -project=/project -mocks=/tmp/mocks/ -prereg=idekey:host:9000 -preprocess=preprocess.php

 * 2) start debugging session
 * either by specifying all xdebug remote config settings in environment variable
$ env 'XDEBUG_CONFIG=idekey=idekey remote_host=localhost remote_port=9002 remote_enable=1' php -dzend_extension=xdebug.so (which phpunit) tests/DateTest.php
 * or by specifying all xdebug remote config settings in php options but XDEBUG_CONFIG environment variable is mandatory
$ env XDEBUG_CONFIG= php -dzend_extension=xdebug.so -dxdebug.remote_enable=1 -dxdebug.remote_host=localhost -dxdebug.remote_port=9002 -dxdebug.idekey=idekey (which phpunit) tests/DateTest.php


 * 1) run proxy without handling soft-mocks rewritings:
$ php bin/proxy.php -xdebug=0:9002 -idereg=0:9001

 * 2) register ide at proxy

 * 3) start debugging session
 */

use Badoo\SoftMocks;
use Net\Connection;
use Net\Listener;
use Net\Loop;
use SmDbgpProxy\Getopt;
use SmDbgpProxy\IdeReg;
use SmDbgpProxy\Proxy;

foreach ([__DIR__ . '/../../../autoload.php', __DIR__ . '/../vendor/autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require_once $autoload;
        break;
    }
}

(function (array $exts) {
    foreach ($exts as $ext) {
        if (!extension_loaded($ext) && !dl("$ext.so")) throw new \Exception("could not load extension $ext");
    }
})(['filter', 'simplexml']);

$getopt = new Getopt;

[$o, $no] = $getopt
    ->setOptStrategy([$getopt, 'optMixedStrategy'])
    ->getOptsNonopts($argv, ['project' => ':', 'mocks' => ':', 'prereg' => ':', 'idereg' => ':', 'xdebug' => ':', 'preprocess' => ':']);

if (isset($o['project'])) {
    if (!isset($o['mocks']) || !is_dir($o['mocks'])) {
        throw new \Exception("no mocks dir specified or exist");
    }
    foreach (['/vendor/nikic/php-parser/lib/bootstrap.php', '/vendor/badoo/soft-mocks/src/Badoo/SoftMocks.php'] as $file) {
        $filepath = $o['project'] . $file;
        if (!is_file($filepath)) {
            throw new \Exception("specified project does not contain parser or softmocks $filepath");
        }
        require_once $filepath;
    }
    echo "SoftMocks::setMocksCachePath($o[mocks])\n";
    SoftMocks::setMocksCachePath($o['mocks']);
    SoftMocks::init();

    $preprocess = function ($file) {
        return true;
    };
    if (isset($o['preprocess'])) {
        echo "using process filter function from $o[preprocess]\n";
        $preprocess = require $o['preprocess'];
    }

    $mocks_cache = dirname(SoftMocks::getRewrittenFilePath($o['project']));
    echo "project path $o[project]\n";
    echo "mocks path $mocks_cache\n";
    Proxy::setPaths($mocks_cache, $o['project'], $preprocess);
}

$loop = new Loop();

if (isset($o['idereg'])) {
    $idereg = Listener::listen($o['idereg'], $loop);
    $idereg->on(Listener::EVENT_CONNECTION, function (Connection $conn) {
        echo 'ideReg connected ' . $conn->getAddress() . "\n";
        $IdeReg = new IdeReg($conn);
        $IdeReg->on(IdeReg::EVENT_IDEREG, function ($key, $ide_host, $port) {
            Proxy::addIde($key, $ide_host, $port);
        });
    });
    echo 'idereg server at ' . $idereg->getAddress() . "\n";
}

if (isset($o['xdebug'])) {
    $xdebug = Listener::listen($o['xdebug'], $loop);
    $xdebug->on(Listener::EVENT_CONNECTION, function (Connection $conn) use ($loop) {
        echo 'xdebug connected ' . $conn->getAddress() . "\n";
        new Proxy($conn, $loop);
    });
    echo 'dbgp server at ' . $xdebug->getAddress() . "\n";
}

foreach ((array)($o['prereg'] ?? []) as $ide) {
    $ide = explode(':', $ide);
    $key = array_shift($ide);
    $port = array_pop($ide);
    $host = implode(':', $ide);
    Proxy::addIde($key, $host, $port);
}

$loop->run();
