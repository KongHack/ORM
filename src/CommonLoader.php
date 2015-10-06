<?php
namespace GCWorld\ORM;

use Exception;
use GCWorld\Interfaces\Common;

class CommonLoader
{
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
            $file = rtrim(dirname(__FILE__), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR;
            $file .= 'config'.DIRECTORY_SEPARATOR.'config.ini';
            if (!file_exists($file)) {
                throw new Exception('Config File Not Found');
            }
            $config = parse_ini_file($file);
            if (!isset($config['common'])) {
                throw new Exception('Config does not contain "common" value!');
            }
            /** @var \GCWorld\Common\Common $class */
            $class = $config['common'];
            $obj = $class::getInstance();
            self::$common = $obj;
        }
        return self::$common;
    }
}
