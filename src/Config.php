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
            $config = $this->loadIni();
        } else {
            $config = Yaml::parse(file_get_contents($file));
            if(array_key_exists('config_path', $config)) {
                $config = Yaml::parse(file_get_contents($config['config_path']));
            }
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

    /**
     * @return array
     * @throws Exception
     */
    private function loadIni(): array
    {
        $file = rtrim(dirname(__FILE__), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR;
        $file .= 'config'.DIRECTORY_SEPARATOR.'config.ini';
        if (!file_exists($file)) {
            throw new Exception('Config File Not Found');
        }
        $config   = parse_ini_file($file, true);
        $redirect = '';
        if (isset($config['config_path'])) {
            $redirect = $file;
            $file     = $config['config_path'];
            $config   = parse_ini_file($file, true);
        }

        $overrides    = [];
        $return_types = [];
        foreach ($config as $k => $v) {
            if (substr($k, 0, 9) == 'override:') {
                $overrides[substr($k, 9)] = $v;
                unset($config[$k]);
            } elseif (substr($k, 0, 13) == 'return_types:') {
                $return_types[substr($k, 13)] = $v;
                unset($config[$k]);
            }
        }

        // New Config Array
        $config['tables'] = [];
        foreach ($overrides as $table => $override) {
            if (!array_key_exists($table, $config['tables'])) {
                $config['tables'][$table] = [];
            }
            $config['tables'][$table]['override'] = $override;
        }
        foreach ($return_types as $table => $return_type) {
            if (!array_key_exists($table, $config['tables'])) {
                $config['tables'][$table] = [];
            }
            $config['tables'][$table]['return_types'] = $return_type;
        }
        if (array_key_exists('audit_ignore', $config)) {
            foreach ($config['audit_ignore'] as $table => $ignores) {
                if (!array_key_exists($table, $config['tables'])) {
                    $config['tables'][$table] = [];
                }
                $config['tables'][$table]['audit_ignore'] = $ignores;
            }
        }
        unset($config['audit_ignore']);

        $newFile = str_replace($file, '.ini', '.yml');
        file_put_contents($newFile, Yaml::dump($config, 5));

        if ($redirect != '') {
            $newRedirect = str_replace($redirect, '.ini', '.yml');
            file_put_contents($newRedirect, Yaml::dump([
                'config_path' => $newFile
            ], 4));
        }

        return $config;
    }
}
