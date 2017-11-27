<?php
namespace GCWorld\ORM;

use Exception;
use Symfony\Component\Yaml\Yaml;

/**
 * Class Config
 * @package GCWorld\ORM
 */
class Config
{
    /**
     * @var array
     */
    protected $config = [];

    /**
     * Config constructor.
     * @throws Exception
     */
    public function __construct()
    {
        // Test for yml.  If not found, test for ini
        $file = rtrim(dirname(__FILE__), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR;
        $file .= 'config'.DIRECTORY_SEPARATOR.'config.yml';
        if (!file_exists($file)) {
            throw new \Exception('YML Config File Not Found');
        }
        $config = Yaml::parse(file_get_contents($file));
        if(array_key_exists('config_path', $config)) {
            $file = $config['config_path'];
            $config = Yaml::parse(file_get_contents($file));
        }

        if (!array_key_exists('common', $config['general'])) {
            throw new \Exception('Missing Common Variable In General');
        }
        if (!array_key_exists('user', $config['general'])) {
            throw new \Exception('Missing User Variable In General');
        }

        $this->config = $config;
    }

    /**
     * @return array
     */
    public function getConfig()
    {
        return $this->config;
    }
}
