<?php

namespace Swpider;

use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;
use Psr\Log\LogLevel;

/**
 * 日志封装类
 * @package Swpider
 */
class Log
{

    CONST DELETE = "\x7f";
    CONST BACKSPACE = "\x08";
    CONST LINE_HEAD = "\r";

    private static $_logger;
    private static $_output;
    private static $_level;

    public static function init($output = null, $verbosityLevelMap = [])
    {
        self::$_output = $output ? : new ConsoleOutput(ConsoleOutput::VERBOSITY_DEBUG);
        self::$_level = $verbosityLevelMap;
        self::$_logger = new ConsoleLogger(self::$_output,self::$_level);
    }


    public static function logger()
    {
        if(is_null(self::$_logger)){
            self::init();
        }

        return self::$_logger;
    }



    public static function __callStatic($name, $arguments)
    {
        if(isset(self::$_logger) && method_exists(self::$_logger, $name)){
            return call_user_func_array([self::$_logger, $name], $arguments);
        }

        throw new \BadMethodCallException("class ".__CLASS__ ." static method $name unsupported");
    }

}