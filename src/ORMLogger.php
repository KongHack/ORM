<?php
namespace GCWorld\ORM;

use Monolog\Handler\NullHandler;
use Monolog\Logger;

/**
 * Class ORMLogger.
 */
class ORMLogger
{
    protected static ?Logger $logger = null;

    /**
     * @param Logger $logger
     *
     * @return void
     */
    public static function setLogger(Logger $logger): void
    {
        self::$logger = $logger;
    }

    /**
     * @return Logger
     */
    public static function getLogger(): Logger
    {
        if (null === self::$logger) {
            $cLogger = new Logger('orm_logger_empty');
            $cLogger->pushHandler(new NullHandler());

            return $cLogger;
        }

        return self::$logger;
    }
}
