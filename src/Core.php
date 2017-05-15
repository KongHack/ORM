<?php
namespace GCWorld\ORM;

use GCWorld\Interfaces\Common;
use \ReflectionClass;
use \PDO;

/**
 * Class Core
 * @package GCWorld\ORM
 */
class Core
{
    protected $master_namespace = '\\';
    /** @var \GCWorld\Common\Common */
    protected $master_common   = null;
    protected $master_location = null;
    protected $config          = [];
    private $open_files        = [];
    private $open_files_level  = [];

    protected $get_set_funcs          = true;
    protected $var_visibility         = 'public';
    protected $json_serialize         = true;
    protected $use_defaults           = true;
    protected $defaults_override_null = true;
    protected $type_hinting           = false;

    /**
     * @param string $namespace
     * @param Common $common
     */
    public function __construct(string $namespace, Common $common)
    {
        $this->master_namespace = $namespace;
        $this->master_common    = $common;
        $this->master_location  = __DIR__;

        $cConfig      = new Config();
        $config       = $cConfig->getConfig();
        $this->config = $config;

        if (isset($config['options']['get_set_funcs'])) {
            if (!$config['options']['get_set_funcs']) {
                $this->get_set_funcs = false;
            }
        }
        if (isset($config['options']['var_visibility']) && in_array($config['options']['var_visibility'], ['public', 'protected'])) {
            $this->var_visibility = $config['options']['var_visibility'];
        }
        if (isset($config['options']['json_serialize']) && !$config['options']['json_serialize']) {
            $this->json_serialize = false;
        }
        if (isset($config['options']['use_defaults']) && !$config['options']['use_defaults']) {
            $this->use_defaults = false;
        }
        if (isset($config['options']['defaults_override_null']) && !$config['options']['defaults_override_null']) {
            $this->defaults_override_null = false;
        }
        if (isset($config['options']['type_hinting']) && $config['options']['type_hinting']) {
            $this->type_hinting = true;
        }
    }

    /**
     * @param string $table_name
     * @return bool
     * @throws \Exception
     */
    public function generate(string $table_name)
    {
        $sql   = 'SHOW FULL COLUMNS FROM '.$table_name;
        $query = $this->master_common->getDatabase()->prepare($sql);
        $query->execute();
        $fields = $query->fetchAll(PDO::FETCH_ASSOC);

        $overrides = isset($this->config['override:'.$table_name]) ? $this->config['override:'.$table_name] : [];
        if (!isset($overrides['constructor'])) {
            $overrides['constructor'] = 'public';
        }

        $return_types = isset($this->config['return_types:'.$table_name]) ? $this->config['return_types:'.$table_name] : [];


        $auto_increment = false;
        $primaries      = [];
        $max_var_name   = 0;
        $max_var_type   = 0;

        $path = $this->master_location.DIRECTORY_SEPARATOR.'Generated/';
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        foreach ($fields as $i => $row) {
            if (strstr($row['Key'], 'PRI')) {
                $primaries[] = $row['Field'];
            }
            if (strlen($row['Field']) > $max_var_name) {
                $max_var_name = strlen($row['Field']);
            }
            if (strlen($row['Type']) > $max_var_type) {
                $max_var_type = strlen($row['Type']);
            }
            if (strstr($row['Extra'], 'auto_increment')) {
                $auto_increment = true;
            }
        }

        $filename = $table_name.'.php';
        $fh       = $this->fileOpen($path.$filename);


        if (count($primaries) < 1) {
            return false;
        }


        $this->fileWrite($fh, '<?php'.PHP_EOL);
        $this->fileWrite($fh, 'namespace GCWorld\\ORM\\Generated;'.PHP_EOL.PHP_EOL);

        /* Not needed as a use, it's just fine how it is
        if ($this->json_serialize) {
            $this->fileWrite($fh, 'use \\GCWorld\\ORM\\FieldName;'.PHP_EOL);
        }
        */

        if (count($primaries) == 1) {
            // Single PK Classes get a simple set of functions.
            if ($this->get_set_funcs) {
                $this->fileWrite($fh, 'use \\GCWorld\\ORM\\Abstracts\\DirectSingle AS dbc;'.PHP_EOL);
                $this->fileWrite($fh, 'use \\GCWorld\\ORM\\Interfaces\\ProtectedDBInterface as dbd;'.PHP_EOL);
            } else {
                $this->fileWrite($fh, 'use \\GCWorld\\ORM\\DirectDBClass AS dbc;'.PHP_EOL);
                $this->fileWrite($fh, 'use \\GCWorld\\ORM\\Interfaces\\PublicDBInterface as dbd;'.PHP_EOL);
            }

            $this->fileWrite($fh, 'use \\GCWorld\\ORM\\Interfaces\\GeneratedInterface AS dbi;'.PHP_EOL.PHP_EOL);
            $this->fileWrite(
                $fh,
                'class '.$table_name.' extends dbc implements dbi, dbd'.($this->json_serialize ? ', \\JsonSerializable' : '').PHP_EOL.'{'.PHP_EOL
            );
            $this->fileBump($fh);
            $this->fileWrite($fh, "CONST ".str_pad('CLASS_TABLE', $max_var_name, ' ')."   = '$table_name';".PHP_EOL);
            $this->fileWrite(
                $fh,
                "CONST ".str_pad('CLASS_PRIMARY', $max_var_name, ' ')."   = '".$primaries[0]."';".PHP_EOL
            );
        } else {
            // Multiple primary keys!!!
            if ($this->get_set_funcs) {
                $this->fileWrite($fh, 'use \\GCWorld\\ORM\\Abstracts\\DirectMulti AS dbc;'.PHP_EOL);
                $this->fileWrite($fh, 'use \\GCWorld\\ORM\\Interfaces\\ProtectedDBInterface as dbd;'.PHP_EOL);
            } else {
                $this->fileWrite($fh, 'use \\GCWorld\\ORM\\DirectDBMultiClass AS dbc;'.PHP_EOL);
                $this->fileWrite($fh, 'use \\GCWorld\\ORM\\Interfaces\\PublicDBInterface as dbd;'.PHP_EOL);
            }
            $this->fileWrite($fh, 'use \\GCWorld\\ORM\\Interfaces\\GeneratedMultiInterface AS dbi;'.PHP_EOL.PHP_EOL);
            $this->fileWrite($fh, 'class '.$table_name." extends dbc implements dbi, dbd".PHP_EOL."{".PHP_EOL);
            $this->fileBump($fh);
            $this->fileWrite($fh, "CONST ".str_pad('CLASS_TABLE', $max_var_name, ' ')."   = '$table_name';".PHP_EOL);
            $this->fileWrite(
                $fh,
                "CONST ".str_pad('CLASS_PRIMARIES', $max_var_name, ' ')."   = ".var_export($primaries, true).";".PHP_EOL
            );
        }

        $this->fileWrite(
            $fh,
            'CONST '.str_pad('AUTO_INCREMENT', $max_var_name, ' ').'   = '.($auto_increment ? 'true' : 'false').";".PHP_EOL
        );

        foreach ($fields as $i => $row) {
            $type = (stristr($row['Type'], 'int') ? 'int   ' : 'string');
            $this->fileWrite($fh, PHP_EOL.PHP_EOL);
            $this->fileWrite($fh, '/**'.PHP_EOL);
            $this->fileWrite($fh, '* @db-info '.$row['Type'].PHP_EOL);
            $this->fileWrite($fh, '* @var '.$type.PHP_EOL);
            $this->fileWrite($fh, '*/'.PHP_EOL);
            if ($this->use_defaults) {
                $this->fileWrite($fh, $this->var_visibility.' $'.str_pad(
                    $row['Field'],
                    $max_var_name,
                    ' '
                ).' = '.$this->formatDefault($row).';');
            } else {
                $this->fileWrite($fh, $this->var_visibility.' $'.str_pad($row['Field'], $max_var_name, ' ').' = null;');
            }
        }
        $this->fileWrite($fh, PHP_EOL);
        $this->fileWrite($fh, '/**'.PHP_EOL);
        $this->fileWrite($fh, '* Contains an array of all fields and the database notation for field type'.PHP_EOL);
        $this->fileWrite($fh, '* @var array'.PHP_EOL);
        $this->fileWrite($fh, '*/'.PHP_EOL);
        $this->fileWrite($fh, 'public static $dbInfo = ['.PHP_EOL);
        $this->fileBump($fh);

        foreach ($fields as $i => $row) {
            $this->fileWrite($fh, str_pad(
                "'".$row['Field']."'",
                $max_var_name + 2,
                ' '
            )." => '".$row['Type'].($row['Comment'] != '' ? ' - '.$row['Comment'] : '')."',".PHP_EOL);
        }
        $this->fileDrop($fh);
        $this->fileWrite($fh, "];".PHP_EOL);

        // CONSTRUCTOR!
        $conVis = $overrides['constructor'];
        if (count($primaries) == 1) {
            $this->fileWrite($fh, PHP_EOL);
            if ($this->type_hinting) {
                $this->fileWrite($fh, '/**'.PHP_EOL);
                $this->fileWrite($fh, '* @param int $primary_id'.PHP_EOL);
                $this->fileWrite($fh, '* @param array $defaults'.PHP_EOL);
                $this->fileWrite($fh, '*/'.PHP_EOL);
                $this->fileWrite(
                    $fh,
                    $conVis.' function __construct(int $primary_id = null, array $defaults = null)'.PHP_EOL
                );
            } else {
                $this->fileWrite($fh, '/**'.PHP_EOL);
                $this->fileWrite($fh, '* @param mixed ...$keys'.PHP_EOL);
                $this->fileWrite($fh, '*/'.PHP_EOL);
                $this->fileWrite($fh, $conVis.' function __construct($primary_id = null, $defaults = null)'.PHP_EOL);
            }
            $this->fileWrite($fh, '{'.PHP_EOL);
            $this->fileBump($fh);
            $this->fileWrite($fh, 'parent::__construct($primary_id, $defaults);'.PHP_EOL);
            $this->fileDrop($fh);
            $this->fileWrite($fh, '}'.PHP_EOL.PHP_EOL);
        } else {
            $this->fileWrite($fh, PHP_EOL);
            $this->fileWrite($fh, '/**'.PHP_EOL);
            $this->fileWrite($fh, '* @param mixed ...$keys'.PHP_EOL);
            $this->fileWrite($fh, '*/'.PHP_EOL);
            $this->fileWrite($fh, $conVis.' function __construct(...$keys)'.PHP_EOL);
            $this->fileWrite($fh, '{'.PHP_EOL);
            $this->fileBump($fh);
            $this->fileWrite($fh, 'parent::__construct(...$keys);'.PHP_EOL);
            $this->fileDrop($fh);
            $this->fileWrite($fh, '}'.PHP_EOL.PHP_EOL);
        }

        if ($this->get_set_funcs) {
            foreach ($fields as $i => $row) {
                $name        = FieldName::nameConversion($row['Field']);
                $return_type = 'mixed';
                if ($this->type_hinting) {
                    $return_type = $this->defaultReturn($row['Type']);
                }
                if (array_key_exists($row['Field'], $return_types)) {
                    $return_type = $return_types[$row['Field']];
                }

                $this->fileWrite($fh, '/**'.PHP_EOL);
                $this->fileWrite($fh, '* @return '.$return_type.PHP_EOL);
                $this->fileWrite($fh, '*/'.PHP_EOL);
                $this->fileWrite($fh, 'public function get'.$name.'() {'.PHP_EOL);
                $this->fileBump($fh);
                $this->fileWrite($fh, 'return $this->get(\''.$row['Field']."');".PHP_EOL);
                $this->fileDrop($fh);
                $this->fileWrite($fh, "}".PHP_EOL.PHP_EOL);
            }

            foreach ($fields as $i => $row) {
                $name        = FieldName::nameConversion($row['Field']);
                $return_type = 'mixed';
                if ($this->type_hinting) {
                    $return_type = $this->defaultReturn($row['Type']);
                }
                if (array_key_exists($row['Field'], $return_types)) {
                    $return_type = $return_types[$row['Field']];
                }

                $this->fileWrite($fh, '/**'.PHP_EOL);
                $this->fileWrite($fh, '* @param '.$return_type.' $value'.PHP_EOL);
                $this->fileWrite($fh, '* @return $this'.PHP_EOL);
                $this->fileWrite($fh, '*/'.PHP_EOL);
                if ($return_type == 'mixed') {
                    $return_type = '';
                } else {
                    $return_type .= ' ';
                }
                $this->fileWrite($fh, 'public function set'.$name.'('.$return_type.'$value) {'.PHP_EOL);
                $this->fileBump($fh);
                $this->fileWrite($fh, 'return $this->set(\''.$row['Field'].'\', $value);'.PHP_EOL);
                $this->fileDrop($fh);
                $this->fileWrite($fh, "}".PHP_EOL.PHP_EOL);
            }
        }

        if ($this->json_serialize) {
            $this->fileWrite($fh, '/**'.PHP_EOL);
            $this->fileWrite($fh, '* @return array'.PHP_EOL);
            $this->fileWrite($fh, '*/'.PHP_EOL);
            $this->fileWrite($fh, 'public function jsonSerialize() {'.PHP_EOL);
            $this->fileBump($fh);

            $this->fileWrite($fh, 'return ['.PHP_EOL);
            $this->fileBump($fh);
            foreach ($fields as $i => $row) {
                $fName = $row['Field'];
                if ($this->get_set_funcs) {
                    $name = FieldName::getterName($fName);
                    $this->fileWrite($fh, "'$fName' => ".'$this->'.$name.'(),'.PHP_EOL);
                } else {
                    $this->fileWrite($fh, "'$fName' => ".'$this->'.$fName.','.PHP_EOL);
                }
            }
            $this->fileDrop($fh);
            $this->fileWrite($fh, '];'.PHP_EOL);

            $this->fileDrop($fh);
            $this->fileWrite($fh, "}".PHP_EOL);
        }


        $this->fileDrop($fh);
        $this->fileWrite($fh, "}".PHP_EOL.PHP_EOL);
        $this->fileClose($fh);

        //Create a trait version
        $path     = $this->master_location.DIRECTORY_SEPARATOR.'Generated/Traits/';
        $filename = $table_name.'.php';
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        $fh = $this->fileOpen($path.$filename);
        $this->fileWrite($fh, "<?php\n");
        $this->fileWrite($fh, 'namespace GCWorld\\ORM\\Generated\\Traits;'.PHP_EOL.PHP_EOL);
        $this->fileWrite($fh, 'trait '.$table_name." \n{\n");
        $this->fileBump($fh);

        foreach ($fields as $i => $row) {
            if (in_array($row['Field'], $primaries)) {
                continue;
            }
            $type = (stristr($row['Type'], 'int') ? 'int   ' : 'string');
            $this->fileWrite($fh, "\n\n");
            $this->fileWrite($fh, '/**'.PHP_EOL);
            $this->fileWrite($fh, '* @db-info '.$row['Type'].PHP_EOL);
            $this->fileWrite($fh, '* @var '.$type.PHP_EOL);
            $this->fileWrite($fh, '*/'.PHP_EOL);
            if ($this->use_defaults) {
                $this->fileWrite($fh, $this->var_visibility.' $'.str_pad(
                    $row['Field'],
                    $max_var_name,
                    ' '
                ).' = '.$this->formatDefault($row).';');
            } else {
                $this->fileWrite($fh, $this->var_visibility.' $'.str_pad($row['Field'], $max_var_name, ' ').' = null;');
            }
        }
        $this->fileWrite($fh, PHP_EOL);

        if ($this->get_set_funcs || $this->var_visibility == 'protected') {
            foreach ($fields as $i => $row) {
                if (in_array($row['Field'], $primaries)) {
                    continue;
                }
                $name        = FieldName::nameConversion($row['Field']);
                $return_type = 'mixed';
                if ($this->type_hinting) {
                    $return_type = $this->defaultReturn($row['Type']);
                }
                if (array_key_exists($row['Field'], $return_types)) {
                    $return_type = $return_types[$row['Field']];
                }

                $this->fileWrite($fh, '/**'.PHP_EOL);
                $this->fileWrite($fh, '* @return '.$return_type.PHP_EOL);
                $this->fileWrite($fh, '*/'.PHP_EOL);
                $this->fileWrite($fh, 'public function get'.$name.'() {'.PHP_EOL);
                $this->fileBump($fh);
                $this->fileWrite($fh, 'return $this->'.$row['Field'].";\n");
                $this->fileDrop($fh);
                $this->fileWrite($fh, "}\n\n");
            }
            $this->fileWrite($fh, PHP_EOL);
        }

        $this->fileDrop($fh);
        $this->fileWrite($fh, "}\n\n");
        $this->fileClose($fh);

        return true;
    }

    /**
     * @param string $filename
     * @return mixed
     */
    protected function fileOpen(string $filename)
    {
        $key                          = str_replace('.', '', microtime(true));
        $this->open_files[$key]       = fopen($filename, 'w');
        $this->open_files_level[$key] = 0;

        return $key;
    }

    /**
     * @param mixed  $key
     * @param string $string
     * @return void
     */
    protected function fileWrite($key, string $string)
    {
        fwrite($this->open_files[$key], str_repeat(' ', $this->open_files_level[$key] * 4).$string);
    }

    /**
     * @param mixed $key
     * @return void
     */
    protected function fileBump($key)
    {
        ++$this->open_files_level[$key];
    }

    /**
     * @param mixed $key
     * @return void
     */
    protected function fileDrop($key)
    {
        --$this->open_files_level[$key];
    }

    /**
     * @param mixed $key
     * @return void
     */
    protected function fileClose($key)
    {
        fclose($this->open_files[$key]);
        unset($this->open_files[$key]);
        unset($this->open_files_level[$key]);
    }

    /**
     * @return object
     */
    public function load()
    {
        $args       = func_get_args();
        $class_name = '\\GCWorld\\ORM\\Generated\\'.$args[0];

        if (!class_exists($class_name)) {
            die('Invalid Class: '.$class_name);
        }
        $args[0] = $this->master_common;

        $reflectionClass = new ReflectionClass($class_name);
        $module          = $reflectionClass->newInstanceArgs($args);

        return $module;
        //$handler = call_user_func_array($class_name, $args);
    }

    /**
     * @param array $row
     * @return float|int|mixed
     */
    private function formatDefault(array $row)
    {
        $default = $row['Default'];
        if ($default === null) {
            if ($row['Null'] == 'NO') {
                $default = $this->defaultData($row['Type']);
            }
        } elseif (strtoupper($default) == 'CURRENT_TIMESTAMP') {
            $default = '0000-00-00 00:00:00';
        }

        if (is_numeric($default)) {
            if (strstr($default, '.')) {
                return floatval($default);
            }

            return intval($default);
        }

        return var_export($default, true);
    }

    /**
     * @param mixed $type
     * @return null|string
     */
    private function defaultData($type)
    {
        $type = strtoupper($type);
        $pos  = strpos($type, '(');
        if ($pos > 0) {
            $type = substr($type, 0, $pos);
        }

        switch ($type) {
            case 'INTEGER':
            case 'TINYINT':
            case 'SMALLINT':
            case 'MEDIUMINT':
            case 'INT':
            case 'BOOLEAN':
            case 'BIGINT':
            case 'SERIAL':
                return 0;


            case 'DECIMAL':
            case 'FLOAT':
            case 'DOUBLE':
            case 'REAL':
            case 'BIT':
            case 'NUMERIC':
            case 'YEAR':
                return 0.0;


            case 'DATE':
                return '0000-00-00';


            case 'DATETIME':
            case 'TIMESTAMP':
                return '0000-00-00 00:00:00';


            case 'TIME':
                return '00:00:00';


            case 'CHAR':
            case 'VARCHAR':
            case 'TINYTEXT':
            case 'TEXT':
            case 'MEDIUMTEXT':
            case 'LONGTEXT':
            case 'BINARY':
            case 'VARBINARY':
            case 'TINYBLOB':
            case 'MEDIUMBLOB':
            case 'BLOB':
            case 'LONGBLOB':
            case 'ENUM':
            case 'SET':
                return '';


            case 'JSON':
                return '{}';  // Probably not necessary, but hey, stay safe
        }

        // Ignoring geometry, because fuck that.
        return null;
    }

    /**
     * @param string $type
     * @return string
     */
    private function defaultReturn(string $type)
    {
        $type = strtoupper($type);
        $pos  = strpos($type, '(');
        if ($pos > 0) {
            $type = substr($type, 0, $pos);
        }

        switch ($type) {
            case 'INTEGER':
            case 'TINYINT':
            case 'SMALLINT':
            case 'MEDIUMINT':
            case 'INT':
            case 'BIGINT':
            case 'SERIAL':
            case 'NUMERIC':
                return 'int';

            case 'BOOLEAN':
                return 'bool';

            case 'DECIMAL':
            case 'FLOAT':
            case 'DOUBLE':
            case 'REAL':
            case 'BIT':
            case 'YEAR':
                return 'float';

            case 'DATE':
            case 'DATETIME':
            case 'TIMESTAMP':
            case 'TIME':
            case 'CHAR':
            case 'VARCHAR':
            case 'TINYTEXT':
            case 'TEXT':
            case 'MEDIUMTEXT':
            case 'LONGTEXT':
            case 'BINARY':
            case 'VARBINARY':
            case 'TINYBLOB':
            case 'MEDIUMBLOB':
            case 'BLOB':
            case 'LONGBLOB':
            case 'ENUM':
            case 'SET':
            case 'JSON':
                return 'string';
        }

        // Ignoring geometry, because fuck that.
        return 'mixed';
    }
}
