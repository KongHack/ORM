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
    protected static ?CommonInterface $common = null;

    /**
     * Sets the common object
     */
    public static function setCommonObject(CommonInterface $common): void
    {
        self::$common = $common;
    }

    /**
     * @return CommonInterface
     */
    public static function getCommon(): CommonInterface
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
