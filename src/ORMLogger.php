<?php
namespace GCWorld\ORM;


use Monolog\Handler\NullHandler;
use Monolog\Logger;

/**
 * Class ORMLogger
 *
 * @package GCWorld\ORM
 */
class ORMLogger
{
    protected static $logger = null;

    /**
     * @param Logger $logger
     *
     * @return void
     */
    public static function setLogger(Logger $logger)
    {
        self::$logger = $logger;
    }

    /**
     * @return Logger
     */
    public static function getLogger(): Logger
    {
        if(self::$logger === null) {
            $cLogger = new Logger('orm_logger_empty');
            $cLogger->pushHandler(new NullHandler());

            return $cLogger;
        }

        return self::$logger;
    }

}