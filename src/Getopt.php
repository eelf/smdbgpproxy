<?php
/**
 * Getopt parses arguments into options (flags with or without values) and non-options
 *
 * Option names and modality of value is set in the same way as in http://ru2.php.net/getopt
 * The result is options array and non-options array
 *
 * example (tail command)

$Getopt = new \TestLib\Getopt;
$formal_description = [
    'c' => [':', 'The location is number bytes.'],
    'f' => ['', 'The -f option causes tail to not stop when end of file is reached'],
];
$formal = array_map(function ($e) {return $e[0];}, $formal_description);
$e = null;
try {
    [$opts, $non_opts] = $Getopt->getOptsNonopts($argv, $formal);
} catch (\Exception $e) {}
if ($e || !$non_opts) {
    echo \TestLib\Getopt::usage($formal_description) . "\n";
    if ($e) echo $e->getMessage() . "\n";
    exit(1);
}
$fh = fopen($non_opts[0], 'r');
if ($opts['f'] ?? null) follow_file($non_opts[0]);
if ($opts['c'] ?? null) fseek($fh, -$opts['c'], SEEK_END);
echo stream_get_contents($fh);

 * example for case of unknown option names:
$Getopt = new \TestLib\Getopt;
$Getopt->setUnknownFlagExceptionStrategy([$Getopt, 'errUnknownFlagCollectStrategy']);
[$opts, $non_opts] = $Getopt->getOptsNonopts($argv);

 */

namespace SmDbgpProxy;

class Getopt {
    const
        CMD_NONOPT = 0,
        CMD_SET = 1,
        ERR_MUST_HAVE_VALUE = 2,
        ERR_UNKNONW_FLAG = 3,
        ERR_HAVE_FLAG_AFTER_NONOPT = 4;
    private $optStrategy, $errNoValueStrategy, $errUnknownFlagStrategy, $errFlagAfterNonoptStrategy;
    public function __construct() {
        $this->optStrategy = [$this, 'optReplaceStrategy'];
        $this->errNoValueStrategy = [$this, 'errNoValueExceptionStrategy'];
        $this->errUnknownFlagStrategy = [$this, 'errUnknownFlagExceptionStrategy'];
        $this->errFlagAfterNonoptStrategy = [$this, 'errFlagAfterNonoptExceptionStrategy'];
    }

    public static function usage($formal_description) {
        $out = "usage:\n";
        foreach ($formal_description as $name => [$modal, $description]) {
            $out .= "  -$name";
            if ($modal == ':') $out .= '=value';
            else if ($modal == '::') $out .= '[=value]';
            $out .= " $description\n";
        }
        return $out;
    }

    /**
     * Plumbing method that yields current case (i.e. encountered option, non-option, or error)
     * It's user's responsibility to throw exception or exit with or without printing usage in case of error
     *
     * @param array $args Arguments to parse
     * @param array $formal [flag => '' must not have value, flag => ':' must have value, flag => '::' may have value]
     * @return \Generator
     */
    public static function parse(array $args, array $formal = []) {
        array_shift($args);//program being launched
        // current_flag contains flag name which expects value, with modal may(2)/must(1) set respectively
        $expect_value = $current_flag = null;
        $was_nonopt = false;
        while ($args) {
            $arg = array_shift($args);
            $is_meta = $arg == '--';
            $is_value = empty($arg) || $arg == '-' || $arg[0] != '-';

            if ($expect_value && $is_value) {
                yield [self::CMD_SET, $arg, $current_flag, $arg];
                $expect_value = $current_flag = null;
                continue;
            }
            if ($expect_value & 1 && !$is_value) {
                yield [self::ERR_MUST_HAVE_VALUE, $arg, $current_flag, null];
                $expect_value = $current_flag = null;
            }
            if ($is_meta) {
                foreach ($args as $arg) yield [self::CMD_NONOPT, $arg];
                break;
            }
            if ($is_value) {
                $was_nonopt = true;
                yield [self::CMD_NONOPT, $arg, null, null];
                continue;
            }
            // is not empty, not equals to -, not equals to --, starts with - (i.e. is_option)

            if ($was_nonopt) yield [self::ERR_HAVE_FLAG_AFTER_NONOPT, $arg, null, null];

            // can start with as many minuses as it wants
            $name = ltrim($arg, '-');
            $name_arr = explode('=', $name, 2);
            $name = $name_arr[0];
            if (!isset($formal[$name])) {
                // it's iterator user responsibility to throw exception
                $res = yield [self::ERR_UNKNONW_FLAG, $arg, $name_arr[0], $name_arr[1] ?? null];
                if ($res)[$current_flag, $expect_value] = $res;
                continue;
            }
            if (isset($name_arr[1])) {
                array_unshift($args, $name_arr[1]);
            }

            if ($formal[$name] == '') {
                yield [self::CMD_SET, $arg, $name, true];
            } else {
                $current_flag = $name;
                $expect_value = $formal[$name] == ':' ? 1 : ($formal[$name] == '::' ? 2 : 0);
            }
        }
        if ($expect_value & 1) {
            // no more values but there must be one
            yield [self::ERR_MUST_HAVE_VALUE, null, $current_flag, null];
        }
        if ($expect_value & 2) {
            // no more values but there may be one
            yield [self::CMD_SET, $arg, $current_flag, null];
        }
    }

    /**
     * Porcelain method that runs parse method and applies strategies in all cases
     * Strategies can be installed before calling this method
     */
    public function getOptsNonopts($args, $formal = []) {
        $o = $no = [];
        foreach ($generator = self::parse($args, $formal) as [$cmd, $arg, $flag_name, $flag_value]) {
            if ($cmd == self::CMD_NONOPT) $no[] = $arg;
            else if ($cmd == self::CMD_SET) ($this->optStrategy) ($o, $flag_name, $flag_value);
            else if ($cmd == self::ERR_MUST_HAVE_VALUE) ($this->errNoValueStrategy) ($flag_name, $arg);
            else if ($cmd == self::ERR_UNKNONW_FLAG) ($this->errUnknownFlagStrategy) ($o, $arg, $flag_name, $flag_value);
            else if ($cmd == self::ERR_HAVE_FLAG_AFTER_NONOPT) ($this->errFlagAfterNonoptStrategy) ($arg);
        }
        return [$o, $no];
    }

    public function optReplaceStrategy(&$opts, $flag_name, $flag_value) {
        $opts[$flag_name] = $flag_value ?? true;
    }

    public function optAppendStrategy(&$opts, $flag_name, $flag_value) {
        if (!isset($opts[$flag_name])) $opts[$flag_name] = [];
        $opts[$flag_name][] = $flag_value ?? true;
    }

    public function optMixedStrategy(&$opts, $flag_name, $flag_value) {
        $value = $flag_value ?? true;
        if (!isset($opts[$flag_name])) $opts[$flag_name] = $value;
        else if (is_array($opts[$flag_name])) $opts[$flag_name][] = $value;
        else $opts[$flag_name] = [$opts[$flag_name], $value];
    }

    public function setOptStrategy(callable $strategy) {
        $this->optStrategy = $strategy;
        return $this;
    }

    public function errNoValueExceptionStrategy($flag_name, $arg) {
        throw new \Exception("expected value for $flag_name but got " . var_export($arg, true));
    }

    public function errUnknownFlagExceptionStrategy(&$opts, $arg, $flag_name, $flag_value) {
        throw new \Exception("unknown flag name $flag_name as " . var_export($arg, true));
    }

    public function errUnknownFlagCollectStrategy(&$opts, $arg, $flag_name, $flag_value) {
        ($this->optStrategy) ($opts, $flag_name, $flag_value);
    }

    public function setUnknownFlagStrategy(callable $strategy) {
        $this->errUnknownFlagStrategy = $strategy;
        return $this;
    }

    public function errFlagAfterNonoptExceptionStrategy($arg) {
        throw new \Exception("have flag $arg after non-option");
    }

    public function errFlagAfterNonoptIgnoreStrategy($arg) {}

    public function setFlagAfterNonoptStrategy(callable $strategy) {
        $this->errFlagAfterNonoptStrategy = $strategy;
        return $this;
    }
}
