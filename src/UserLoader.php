<?php
namespace GCWorld\ORM;

use Exception;
use GCWorld\Interfaces\User;

/**
 * Class UserLoader
 * @package GCWorld\ORM
 */
class UserLoader
{
    /**
     * @var User|null
     */
    private static ?User $user = null;

    /**
     * Sets the user object
     */
    public static function setUserObject(User $user): void
    {
        self::$user = $user;
    }

    /**
     * @return User
     * @throws Exception
     */
    public static function getUser(): User
    {
        if (self::$user == null) {
            // Attempt loading from a config.ini
            $file  = rtrim(dirname(__FILE__), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR;
            $file .= 'config'.DIRECTORY_SEPARATOR.'config.ini';
            if (!file_exists($file)) {
                throw new Exception('Config File Not Found');
            }
            $config = parse_ini_file($file, true);
            if (isset($config['config_path'])) {
                $config = parse_ini_file($config['config_path'], true);
            }
            if (!isset($config['general']['user'])) {
                throw new Exception('Config does not contain "user" value!');
            }
            /** @var \stdClass $class */
            $class = $config['general']['user'];
            if (!method_exists($class, 'getInstance')) {
                throw new Exception('getInstance method not found');
            }
            $obj        = $class::getInstance();
            self::$user = $obj;
        }
        return self::$user;
    }
}
