<?php
namespace GCWorld\ORM;

use GCWorld\Interfaces\Common;

/**
 * Class CommonLoader
 * @package GCWorld\ORM
 */
class CommonLoader
{
    /**
     * @var Common
     */
    private static $common = null;

    /**
     * Sets the common object
     * @param \GCWorld\Interfaces\Common $common
     */
    public static function setCommonObject(Common $common)
    {
        self::$common = $common;
    }

    /**
     * @return \GCWorld\Common\Common
     * @throws \Exception
     */
    public static function getCommon()
    {
        if (self::$common == null) {
            // Attempt loading from a config.ini
            $cConfig = new Config();
            $config  = $cConfig->getConfig();

            /** @var \GCWorld\Common\Common $class */
            $class        = $config['general']['common'];
            $obj          = $class::getInstance();
            self::$common = $obj;
        }

        return self::$common;
    }
}
