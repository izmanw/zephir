<?php

/*
 +--------------------------------------------------------------------------+
 | Zephir Language                                                          |
 +--------------------------------------------------------------------------+
 | Copyright (c) 2013-2016 Zephir Team and contributors                     |
 +--------------------------------------------------------------------------+
 | This source file is subject the MIT license, that is bundled with        |
 | this package in the file LICENSE, and is available through the           |
 | world-wide-web at the following url:                                     |
 | http://zephir-lang.com/license.html                                      |
 |                                                                          |
 | If you did not receive a copy of the MIT license and are unable          |
 | to obtain it through the world-wide-web, please send a note to           |
 | license@zephir-lang.com so we can mail you a copy immediately.           |
 +--------------------------------------------------------------------------+
*/

namespace Zephir;

use Zephir\Compiler;
use Zephir\Commands\CommandAbstract;

/**
 * Bootstrap
 *
 * Main compiler bootstrap
 */
class Bootstrap
{
    /**
     * @var CommandAbstract[]
     */
    protected static $commands = array();

    /**
     * Shows an exception opening the file and highlighting the wrong part
     *
     * @param \Exception $e
     * @param Config $config
     */
    protected static function showException(\Exception $e, Config $config = null)
    {
        $red = function ($line) {
            if (php_sapi_name() === 'cli' || (is_numeric($_SERVER['argc']) && $_SERVER['argc'] > 0)) {
                $line = str_replace("'", "'\"'\"'", $line);
                $line = '\033[31m' . $line . '\033[0m';
                $line = `echo -n '$line'`;
            }
            return $line;
        };

        $open = function ($file, $line) {
            exec("which pstorm", $output, $return);
            if ($return == 0) {
                $arg = escapeshellarg($file . ':' . $line);
                `pstorm $arg`;
            }
        };

        $traceLine = true;
        $output = get_class($e) . ': ' . $red($e->getMessage());

        if (method_exists($e, 'getExtra')) {
            $extra = $e->getExtra();
            if (is_array($extra)) {
                if (isset($extra['file'])) {
                    $lines = file($extra['file']);
                    if (isset($lines[$extra['line'] - 1])) {
                        $line = $lines[$extra['line'] - 1];
                        if (preg_match('~^(.*?)\sin\s(/[a-z0-9-._/]+\.zep)\son\sline\s([0-9]+)$~isxSX', trim($e->getMessage()), $match)) {
                            $traceLine = false;
                            $output =  $red($match[1]) . "\n";
                            $output .= 'in ' . $match[2] . ' on line ' . $match[3] . "\n";
                            $open($match[2], $match[3]);
                        }
                        $output .= PHP_EOL;
                        $line = $red($line);
                        $output .= /*"\t",*/ str_replace("\t", " ", $line);
                        if (($extra['char'] - 1) > 0) {
                            $output .= /*"\t",*/ str_repeat("-", $extra['char'] - 1) . "^";
                        }
                    }
                }
            }
        }

        echo $output, PHP_EOL;

        if ($traceLine) {
            foreach ($e->getTrace() as $trace) {
                if (!empty($trace['args']) && is_array($trace['args'])) {
                    foreach ($trace['args'] as $arg) {
                        if (is_object($arg) && method_exists($arg, 'getExpression')) {
                            $expr = $arg->getExpression();
                            if (is_array($expr) && isset($expr['parameters']) && is_array($expr['parameters'])) {
                                foreach ($expr['parameters'] as $param) {
                                    if (is_array($param) && isset($param['file']) && isset($param['line'])) {
                                        echo ' in ' . $param['file'] . ' on line ' . $param['line'], PHP_EOL;
                                        $open($param['file'], $param['line']);
                                        break 3;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        if (($config && $config->get('verbose'))) {
            echo 'at ', str_replace(ZEPHIRPATH, '', $e->getFile()), '(', $e->getLine(), ')', PHP_EOL;
            echo str_replace(ZEPHIRPATH, '', $e->getTraceAsString()), PHP_EOL;
        }

        exit(1);
    }


    /**
     * Returns the commands registered in the compiler
     *
     * @return CommandAbstract[]
     */
    public static function getCommands()
    {
        return self::$commands;
    }

    /**
     * Boots the compiler executing the specified action
     */
    public static function boot()
    {
        try {
            /**
             * Global config
             */
            $config = new Config();
            register_shutdown_function(array($config, 'saveOnExit'));

            /**
             * Global logger
             */
            $logger = new Logger($config);

            if (isset($_SERVER['argv'][1])) {
                $action = $_SERVER['argv'][1];
            } else {
                $action = 'help';
            }

            /**
             * Change configurations flags
             */
            if ($_SERVER['argc'] >= 2) {
                for ($i = 2; $i < $_SERVER['argc']; $i++) {
                    $parameter = $_SERVER['argv'][$i];

                    if (preg_match('/^-fno-([a-z0-9\-]+)$/', $parameter, $matches)) {
                        $config->set($matches[1], false, 'optimizations');
                        continue;
                    }

                    if (preg_match('/^-f([a-z0-9\-]+)$/', $parameter, $matches)) {
                        $config->set($matches[1], true, 'optimizations');
                    }

                    if (preg_match('/^-W([a-z0-9\-]+)$/', $parameter, $matches)) {
                        $logger->set($matches[1], false, 'warnings');
                        continue;
                    }

                    if (preg_match('/^-w([a-z0-9\-]+)$/', $parameter, $matches)) {
                        $logger->set($matches[1], true, 'warnings');
                        continue;
                    }

                    if (preg_match('/^--([a-z0-9\-]+)$/', $parameter, $matches)) {
                        $config->set($matches[1], true, 'extra');
                        continue;
                    }

                    if (preg_match('/^--([a-z0-9\-]+)=(.*)$/', $parameter, $matches)) {
                        $config->set($matches[1], $matches[2], 'extra');
                        continue;
                    }

                    switch ($parameter) {
                        case '-w':
                            $config->set('silent', true);
                            break;

                        case '-v':
                            $config->set('verbose', true);
                            break;

                        case '-V':
                            $config->set('verbose', false);
                            break;

                        default:
                            break;
                    }
                }
            }

            /**
             * Register built-in commands
             * @var $item \DirectoryIterator
             */
            foreach (new \DirectoryIterator(ZEPHIRPATH . 'Library/Commands') as $item) {
                if (!$item->isDir()) {
                    $className = 'Zephir\\Commands\\' . str_replace('.php', '', $item->getBaseName());
                    $class = new \ReflectionClass($className);

                    if (!$class->isAbstract() && !$class->isInterface()) {
                        /**
                         * @var $command CommandAbstract
                         */
                        $command = new $className();

                        if (!($command instanceof CommandAbstract)) {
                            throw new \Exception('Class ' . $class->name . ' must be instance of CommandAbstract');
                        }

                        self::$commands[$command->getCommand()] = $command;
                    }
                }
            }

            if (!isset(self::$commands[$action])) {
                $message = 'Unrecognized action "' . $action . '"';
                $metaphone = metaphone($action);
                foreach (self::$commands as $key => $command) {
                    if (metaphone($key) == $metaphone) {
                        $message .= PHP_EOL . PHP_EOL . 'Did you mean "' . $key . '"?';
                    }
                }

                throw new \Exception($message);
            }

            /**
             * Execute the command
             */
            self::$commands[$action]->execute($config, $logger);
        } catch (\Exception $e) {
            self::showException($e, isset($config) ? $config : null);
        }
    }
}
