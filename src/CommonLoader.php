<?php
namespace GCWorld\ORM;

use GCWorld\Interfaces\Common;

/**
 * Class CommonLoader
 * @package GCWorld\ORM
 */
class CommonLoader
{
    protected static $common = null;

    /**
     * Sets the common object
     * @param \GCWorld\Interfaces\Common $common
     * @return void
     */
    public static function setCommonObject(Common $common)
    {
        self::$common = $common;
    }

    /**
     * @return \GCWorld\Common\Common
     */
    public static function getCommon()
    {
        if (self::$common == null) {
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
