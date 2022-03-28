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
    public const VERSION = 5;

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
        $file  = rtrim(dirname(__FILE__), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR;
        $file .= 'config'.DIRECTORY_SEPARATOR.'config.yml';

        $config = [];
        $cache  = str_replace('.yml', '.php', $file);
        if (file_exists($cache)) {
            if (filemtime($file) < filemtime($cache)) {
                $config = require $cache;
            }
        }

        if (empty($config) || !is_array($config)) {
            if (!file_exists($file)) {
                throw new Exception('YML Config File Not Found');
            }
            $config = Yaml::parseFile($file);
            file_put_contents($cache, '<?php'.PHP_EOL.PHP_EOL.'return '.var_export($config, true).';'.PHP_EOL.PHP_EOL);
        }

        if (isset($config['config_path'])) {
            $file  = __DIR__.DIRECTORY_SEPARATOR.$config['config_path'];
            $cache = str_replace('.yml', '.php', $file);
            if (file_exists($cache) && filemtime($file) < filemtime($cache)) {
                $config = require $cache;
            } else {
                $config = Yaml::parseFile($file);
                file_put_contents($cache, '<?php'.PHP_EOL.PHP_EOL.'return '.var_export($config, true).';'.PHP_EOL);
            }
        }

        if (!isset($config['version'])) {
            $config['version'] = 0;
        }

        if ($config['version'] < self::VERSION) {
            $this->upgradeConfig($config);
        }
        if (isset($config['sort']) && $config['sort']) {
            $this->sortConfig($config);
            $new = Yaml::dump($config, 6);
            file_put_contents($file, $new);
        }

        if (!array_key_exists('common', $config['general'])) {
            throw new Exception('Missing Common Variable In General');
        }
        if (!array_key_exists('user', $config['general'])) {
            throw new Exception('Missing User Variable In General');
        }

        if (!isset($config['audit_handler']) || !is_string($config['audit_handler'])) {
            $config['audit_handler'] = Audit::class;
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
     * @param array $config
     * @return void
     */
    protected function upgradeConfig(array &$config)
    {
        if ($config['version'] < 4) {
            $config['version'] = 4;
            $config['sort']    = true;

            foreach ($config['tables'] as &$table) {
                $visibility           = [];
                $audit_ignore_fields  = [];
                $getter_ignore_fields = [];
                $setter_ignore_fields = [];
                $type_hints           = [];
                $uuid_fields          = [];
                $fields               = [];

                if (isset($table['overrides'])) {
                    if (isset($table['overrides']['constructor'])) {
                        $table['constructor'] = $table['overrides']['constructor'];
                        unset($table['overrides']['constructor']);
                    }
                    foreach ($table['overrides'] as $k => $v) {
                        $visibility[$k] = $v;
                        $fields[]       = $k;
                    }
                    unset($table['overrides']);
                }
                if (isset($table['audit_ignore_fields'])) {
                    foreach ($table['audit_ignore_fields'] as $field) {
                        $audit_ignore_fields[] = $field;
                        $fields[]              = $field;
                    }
                    unset($table['audit_ignore_fields']);
                }
                if (isset($table['type_hints'])) {
                    foreach ($table['type_hints'] as $k => $v) {
                        $type_hints[$k] = $v;
                        $fields[]       = $k;
                    }
                    unset($table['type_hints']);
                }
                if (isset($table['getter_ignore_fields'])) {
                    foreach ($table['getter_ignore_fields'] as $field) {
                        $getter_ignore_fields[] = $field;
                        $fields[]               = $field;
                    }
                    unset($table['getter_ignore_fields']);
                }
                if (isset($table['setter_ignore_fields'])) {
                    foreach ($table['setter_ignore_fields'] as $field) {
                        $setter_ignore_fields[] = $field;
                        $fields[]               = $field;
                    }
                    unset($table['setter_ignore_fields']);
                }
                if (isset($table['uuid_fields'])) {
                    foreach ($table['uuid_fields'] as $field) {
                        $uuid_fields[] = $field;
                        $fields[]      = $field;
                    }
                    unset($table['uuid_fields']);
                }

                $fConfig = [];
                $fields  = array_unique($fields);
                foreach ($fields as $field) {
                    $fConfig[$field] = [];
                    if (isset($visibility[$field])) {
                        $fConfig[$field]['visibility'] = $visibility[$field];
                    }
                    if (isset($type_hints[$field])) {
                        $fConfig[$field]['type_hint'] = $type_hints[$field];
                    }
                    if (in_array($field, $audit_ignore_fields)) {
                        $fConfig[$field]['audit_ignore'] = true;
                    }
                    if (in_array($field, $getter_ignore_fields)) {
                        $fConfig[$field]['getter_ignore'] = true;
                    }
                    if (in_array($field, $setter_ignore_fields)) {
                        $fConfig[$field]['setter_ignore'] = true;
                    }
                    if (in_array($field, $uuid_fields)) {
                        $fConfig[$field]['uuid_fields'] = true;
                    }
                }

                if (count($fConfig) > 0) {
                    $table['fields'] = $fConfig;
                } else {
                    unset($table['fields']);
                }
            }
        }
    }

    /**
     * @param array $config
     * @return void
     */
    protected function sortConfig(array &$config)
    {
        unset($config['sort']);
        if (isset($config['tables'])) {
            ksort($config['tables']);
        }
        foreach ($config['tables'] as &$table) {
            if (isset($table['fields']) && is_array($table['fields'])) {
                ksort($table['fields']);
                foreach ($table['fields'] as &$field) {
                    if (is_array($field)) {
                        ksort($field);
                    }
                }
            }
        }
    }

    /**
     * @return array
     */
    public static function getDefaultFieldConfig()
    {
        return [
            'visibility'    => 'public',
            'type_hint'     => '',
            'audit_ignore'  => false,
            'uuid_field'    => false,
            'getter_ignore' => false,
            'setter_ignore' => false,
        ];
    }
}
