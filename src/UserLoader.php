<?php
namespace GCWorld\ORM;

use Exception;
use stdClass;

/**
 * Class UserLoader.
 */
class UserLoader
{
    /**
     * @var mixed
     */
    private static mixed $user = null;

    /**
     * Sets the user object.
     *
     * @param mixed $user
     *
     * @return void
     */
    public static function setUserObject(mixed $user)
    {
        self::$user = $user;
    }

    /**
     * @throws Exception
     *
     * @return mixed
     */
    public static function getUser(): mixed
    {
        if (null == self::$user) {
            // Intentionally bootstrap from the lightweight project config.ini here: this path is used at runtime
            // and only needs enough information to locate the ORM-specific config generated during installation.
            $file  = \rtrim(\dirname(__FILE__), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR;
            $file .= 'config'.DIRECTORY_SEPARATOR.'config.ini';
            if (!\file_exists($file)) {
                throw new Exception('Config File Not Found');
            }
            $config = \parse_ini_file($file, true);
            if (isset($config['config_path'])) {
                $config = \parse_ini_file($config['config_path'], true);
            }
            if (!isset($config['general']['user'])) {
                throw new Exception('Config does not contain "user" value!');
            }
            /** @var stdClass $class */
            $class = $config['general']['user'];
            if (!\method_exists($class, 'getInstance')) {
                throw new Exception('getInstance method not found');
            }
            // @phpstan-ignore-next-line
            $obj        = $class::getInstance();
            self::$user = $obj;
        }

        return self::$user;
    }
}
