<?php

namespace GCWorld\ORM;

use GCWorld\Interfaces\CommonInterface;

/**
 * Class CommonLoader
 *
 * @package GCWorld\ORM
 */
class CommonLoader
{
    /** @var \GCWorld\Common\Common|CommonInterface|null */
    protected static $common = null;

    /**
     * Sets the common object
     * @param CommonInterface $common
     * @return void
     */
    public static function setCommonObject(CommonInterface $common)
    {
        self::$common = $common;
    }

    /**
     * @return \GCWorld\Common\Common|CommonInterface
     */
    public static function getCommon()
    {
        if (self::$common == null) {
            $cConfig = new Config();
            $config  = $cConfig->getConfig();

            /** @var \GCWorld\Common\Common|CommonInterface $class */
            $class        = $config['general']['common'];
            $obj          = $class::getInstance();
            self::$common = $obj;
        }

        return self::$common;
    }
}
