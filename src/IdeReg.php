<?php

namespace SmDbgpProxy;

use Net\Connection;
use Net\TEvent;
use Proto\IdeProto;
use Proto\Xdebug;

class IdeReg {
    use TEvent;
    const EVENT_IDEREG = 1;
    /** @var Connection */
    private $conn;

    public function __construct(Connection $conn) {
        $this->conn = $conn;

        $conn->on(Connection::EVENT_CLOSE, function () {
            echo "idereg disconnected " . $this->conn->getAddress() . "\n";
        });

        $IdeProto = new IdeProto();
        $conn->on(Connection::EVENT_DATA, [$IdeProto, 'data']);
        $IdeProto->on(IdeProto::EVENT_IDE_PKT, [$this, 'ideRegData']);
    }

    public function ideRegData($type, $args) {
        if ($type == 'proxyinit') {
            [$args_parsed, $err] = IdeProto::parseIdeArgs($args);
            if ($err !== null || !isset($args_parsed['k']) || !isset($args_parsed['p'])) {
                echo "proxyinit err:$err or absent args[k, p]:" . var_export($args, true) . "\n";
                $this->conn->close();
                return;
            }
            $ide_host = explode(':', $this->conn->getAddress())[0];

            $this->emit(self::EVENT_IDEREG, $args_parsed['k'], $ide_host, $args_parsed['p']);

            $doc = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><proxyinit/>');
            $doc->addAttribute('success', 1);
            $doc->addAttribute('idekey', $args_parsed['k']);
            $doc->addAttribute('address', $ide_host);
            $doc->addAttribute('port', $args_parsed['p']);

            $this->conn->close(Xdebug::ser($doc));
        } else {
            echo "unknown idereg command:$type\n";
            $this->conn->close();
        }
    }
}
