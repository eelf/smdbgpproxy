<?php

namespace SmDbgpProxy;

use Net\Connection;
use Net\Loop;
use Proto\IdeProto;
use Proto\Xdebug;

class Proxy {
    private static $ides;
    private static $mocks_cache;
    private static $project;
    private static $local_project;
    private static $do_not_translate_local;


    private $loop;
    /** @var Connection */
    private $xdebug_conn;
    /** @var Connection */
    private $ide_conn;

    /** @var IdeProto */
    private $Ide;

    public static function addIde($key, $host, $port) {
        self::$ides[$key] = ['host' => $host, 'port' => $port];
    }

    public static function setPaths($mocks_cache, $project, $local_project, $do_not_translate_local) {
        self::$mocks_cache = $mocks_cache;
        self::$project = $project;
        self::$local_project = $local_project;
        self::$do_not_translate_local = $do_not_translate_local;
    }

    public function __construct(Connection $conn, Loop $loop) {
        $this->xdebug_conn = $conn;
        $this->xdebug_conn->on(Connection::EVENT_CLOSE, function () {
            echo "xdebug disconnected\n";
            if ($this->ide_conn) $this->ide_conn->close();
        });

        $this->loop = $loop;

        $Xdebug = new Xdebug();
        $conn->on(Connection::EVENT_DATA, [$Xdebug, 'data']);
        $Xdebug->on(Xdebug::EVENT_XDEBUG_PKT, [$this, 'xdebugPacket']);
    }

    public function xdebugPacket(\SimpleXMLElement $doc) {
        if (!$this->ide_conn) {
            $idekey = (string)$doc->attributes()['idekey'];
            $ide = self::$ides[$idekey] ?? null;
            if (!$ide) {
                echo "no ide registereged with key:$idekey\n";
                $this->xdebug_conn->close();
                return;
            }
            $this->ide_conn = Connection::connect($ide['host'] . ':' . $ide['port'], $this->loop);
            $this->ide_conn->on(Connection::EVENT_CLOSE, function () {
                $this->xdebug_conn->close();
            });
            $this->Ide = new IdeProto();
            $this->ide_conn->on(Connection::EVENT_DATA, [$this->Ide, 'data']);
            $this->Ide->on(IdeProto::EVENT_IDE_PKT, [$this, 'idePacket']);
        } else {
            foreach ($doc->children() as $node) {
                if ($node->getName() == 'stack' && $node->attributes()['filename']) {
                    [$filename_project, $err] = $this->processFilename((string)$node->attributes()['filename']);
                    if ($err !== null) {
                        echo "from xdebug processFilename stack:$err\n";
                        continue;
                    }
                    $node->attributes()['filename'] = 'file://' . $filename_project;
                }
            }

            $ns = 'xdebug'; $pref = true;
            //$ns = 'http://xdebug.org/dbgp/xdebug'; $pref = false;
            foreach ($doc->children($ns, $pref) as $node) {
                if ($node->getName() == 'message' && $node->attributes()['filename']) {
                    [$filename_project, $err] = $this->processFilename((string)$node->attributes()['filename']);
                    if ($err != null) {
                        echo "from xdebug processFilename message:$err\n";
                        continue;
                    }
                    $node->attributes()['filename'] = 'file://' . $filename_project;
                }
            }
        }

        $this->ide_conn->write(Xdebug::ser($doc));
    }

    public function idePacket($cmd, $args) {
        if ($cmd == 'breakpoint_set') {
            [$args_parsed, $err] = IdeProto::parseIdeArgs($args);
            if ($err !== null || !isset($args_parsed['f'])) {
                echo "breakpoint_set err:$err or absent arg[f]:" . var_export($args, true) . "\n";
                $this->ide_conn->close();
                $this->xdebug_conn->close();
                return;
            }
            [$new_filename, $err] = $this->processIdeFilename($args_parsed['f']);
            if ($err !== null) {
                echo "processIdeFilename err:$err\n";
            } else {
                $args_parsed['f'] = $new_filename;
            }
            $args = IdeProto::buildArgs($args_parsed);
        }
        $this->xdebug_conn->write(IdeProto::ser($cmd, $args));
    }

    protected function processFilename($filename) {
        $comps = parse_url($filename);
        if ($comps['scheme'] != 'file') {
            return [null, "scheme is not file:" . $comps['scheme']];
        }
        if (strpos($comps['path'], self::$mocks_cache) !== 0) {
            return [null, "does not start with " . self::$mocks_cache . ":" . $comps['path']];
        }
        $p_file_path = substr($comps['path'], strlen(self::$mocks_cache) + 1/*directory separator*/);
        if (!preg_match('#(?P<name>.*)_(?P<hash>[0-9a-f]{32})\.php#', $p_file_path, $m)) {
            return [null, "does not look like hashed: $p_file_path"];
        }

        $local_file = self::$local_project . '/' . $m['name'];
        if (!is_file($local_file)) {
            return [null, "no local file:$local_file"];
        }
        $md5_file = md5_file($local_file);
        $md5_full = md5($m['name'] . ':' . $md5_file);
        if ($md5_full != $m['hash']) {
            return [null, "hash different:$md5_full($md5_file) expected:$m[hash]"];
        }
        return [self::$project . '/' . $m['name'], null];
    }

    protected function processIdeFilename($filename) {
        $comps = parse_url($filename);
        if ($comps['scheme'] != 'file') {
            return [null, "scheme is not file:" . $comps['scheme']];
        }
        if (strpos($comps['path'], self::$project) !== 0) {
            return [null, "does not start with " . self::$project . ":" . $comps['path']];
        }
        $rel_path = substr($comps['path'], strlen(self::$project) + 1);

        if (isset(self::$do_not_translate_local[$rel_path])) {
            return [null, "skipping translation:$rel_path"];
        }

        $local_path = self::$local_project . '/' . $rel_path;
        if (!is_file($local_path)) {
            return [null, "local file does not exist:$local_path"];
        }
        $md5_file = md5_file($local_path);

        $md5_full = md5($rel_path . ':' . $md5_file);
        return [self::$mocks_cache . '/' . $rel_path . '_' . $md5_full . '.php', null];
    }
}
