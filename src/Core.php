<?php
namespace GCWorld\ORM;

use \ReflectionClass;
use \PDO;

class Core
{
    protected $master_namespace = '\\';
    /** @var \GCWorld\Common\Common */
    protected $master_common    = null;
    protected $master_location  = null;
    private   $open_files       = [];
    private   $open_files_level = [];

    protected $get_set_funcs          = true;
    protected $var_visibility         = 'public';
    protected $json_serialize         = true;
    protected $use_defaults           = true;
    protected $defaults_override_null = true;

    /**
     * @param $namespace
     * @param $common
     */
    public function __construct($namespace, $common)
    {
        $this->master_namespace = $namespace;
        $this->master_common    = $common;
        $this->master_location  = __DIR__;

        $cConfig = new Config();
        $config  = $cConfig->getConfig();

        if (isset($config['get_set_funcs'])) {
            if (!$config['get_set_funcs']) {
                $this->get_set_funcs = false;
            }
        }
        if (isset($config['var_visibility']) && in_array($config['var_visibility'], ['public', 'protected'])) {
            $this->var_visibility = $config['var_visibility'];
        }
        if (isset($config['json_serialize']) && !$config['json_serialize']) {
            $this->json_serialize = false;
        }
        if (isset($config['use_defaults']) && !$config['use_defaults']) {
            $this->use_defaults = false;
        }
        if (isset($config['defaults_override_null']) && !$config['defaults_override_null']) {
            $this->defaults_override_null = false;
        }
    }

    /**
     * @param $table_name
     * @return bool
     * @throws \Exception
     */
    public function generate($table_name)
    {
        $sql   = 'SHOW FULL COLUMNS FROM '.$table_name;
        $query = $this->master_common->getDatabase()->prepare($sql);
        $query->execute();
        $fields = $query->fetchAll(PDO::FETCH_ASSOC);

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


        $this->fileWrite($fh, "<?php\n");
        $this->fileWrite($fh, 'namespace GCWorld\\ORM\\Generated;'."\n\n");

        if ($this->json_serialize) {
            $this->fileWrite($fh, 'use \\GCWorld\\ORM\\FieldName;'."\n");
        }

        if (count($primaries) == 1) {
            // Single PK Classes get a simple set of functions.
            if ($this->get_set_funcs) {
                $this->fileWrite($fh, 'use \\GCWorld\\ORM\\Abstracts\\DirectSingle AS dbc;'."\n");
                $this->fileWrite($fh, 'use \\GCWorld\\ORM\\Interfaces\\ProtectedDBInterface as dbd;'."\n");
            } else {
                $this->fileWrite($fh, 'use \\GCWorld\\ORM\\DirectDBClass AS dbc;'."\n");
                $this->fileWrite($fh, 'use \\GCWorld\\ORM\\Interfaces\\PublicDBInterface as dbd;'."\n");
            }

            $this->fileWrite($fh, 'use \\GCWorld\\ORM\\Interfaces\\GeneratedInterface AS dbi;'."\n\n");
            $this->fileWrite($fh,
                'class '.$table_name." extends dbc implements dbi, dbd".($this->json_serialize ? ", \JsonSerializable" : '')."\n{\n");
            $this->fileBump($fh);
            $this->fileWrite($fh, "CONST ".str_pad('CLASS_TABLE', $max_var_name, ' ')."   = '$table_name';\n");
            $this->fileWrite($fh,
                "CONST ".str_pad('CLASS_PRIMARY', $max_var_name, ' ')."   = '".$primaries[0]."';\n");

        } else {
            // Multiple primary keys!!!
            if ($this->get_set_funcs) {
                $this->fileWrite($fh, 'use \\GCWorld\\ORM\\Abstracts\\DirectMulti AS dbc;'."\n");
                $this->fileWrite($fh, 'use \\GCWorld\\ORM\\Interfaces\\ProtectedDBInterface as dbd;'."\n");
            } else {
                $this->fileWrite($fh, 'use \\GCWorld\\ORM\\DirectDBMultiClass AS dbc;'."\n");
                $this->fileWrite($fh, 'use \\GCWorld\\ORM\\Interfaces\\PublicDBInterface as dbd;'."\n");
            }
            $this->fileWrite($fh, 'use \\GCWorld\\ORM\\Interfaces\\GeneratedMultiInterface AS dbi;'."\n\n");
            $this->fileWrite($fh, 'class '.$table_name." extends dbc implements dbi, dbd\n{\n");
            $this->fileBump($fh);
            $this->fileWrite($fh, "CONST ".str_pad('CLASS_TABLE', $max_var_name, ' ')."   = '$table_name';\n");
            $this->fileWrite($fh,
                "CONST ".str_pad('CLASS_PRIMARIES', $max_var_name, ' ')."   = ".var_export($primaries, true).";\n");

        }

        $this->fileWrite($fh,
            'CONST '.str_pad('AUTO_INCREMENT', $max_var_name, ' ').'   = '.($auto_increment ? 'true' : 'false').";\n");

        foreach ($fields as $i => $row) {
            $type = (stristr($row['Type'], 'int') ? 'int   ' : 'string');
            $this->fileWrite($fh, "\n\n");
            $this->fileWrite($fh, '/**'."\n");
            $this->fileWrite($fh, '* @db-info '.$row['Type']."\n");
            $this->fileWrite($fh, '* @var '.$type."\n");
            $this->fileWrite($fh, '*/'."\n");
            if ($this->use_defaults) {
                $this->fileWrite($fh, $this->var_visibility.' $'.str_pad($row['Field'], $max_var_name,
                        ' ').' = '.$this->formatDefault($row).';');
            } else {
                $this->fileWrite($fh, $this->var_visibility.' $'.str_pad($row['Field'], $max_var_name, ' ').' = null;');
            }
        }
        $this->fileWrite($fh, "\n");
        $this->fileWrite($fh, '/**'."\n");
        $this->fileWrite($fh, '* Contains an array of all fields and the database notation for field type'."\n");
        $this->fileWrite($fh, '* @var array'."\n");
        $this->fileWrite($fh, '*/'."\n");
        $this->fileWrite($fh, 'public static $dbInfo = ['."\n");
        $this->fileBump($fh);

        foreach ($fields as $i => $row) {
            $this->fileWrite($fh, str_pad(
                    "'".$row['Field']."'",
                    $max_var_name + 2,
                    ' '
                )." => '".$row['Type'].($row['Comment'] != '' ? ' - '.$row['Comment'] : '')."',\n");
        }
        $this->fileDrop($fh);
        $this->fileWrite($fh, "];\n");

        if ($this->get_set_funcs) {
            foreach ($fields as $i => $row) {
                $name = FieldName::nameConversion($row['Field']);

                //TODO: Add doc block
                $this->fileWrite($fh, 'public function get'.$name.'() {'."\n");
                $this->fileBump($fh);
                $this->fileWrite($fh, 'return $this->get(\''.$row['Field']."');\n");
                $this->fileDrop($fh);
                $this->fileWrite($fh, "}\n\n");
            }

            foreach ($fields as $i => $row) {
                $name = FieldName::nameConversion($row['Field']);

                $this->fileWrite($fh, '/**'."\n");
                $this->fileWrite($fh, '* @param mixed $value'."\n");
                $this->fileWrite($fh, '* @return $this'."\n");
                $this->fileWrite($fh, '*/'."\n");
                $this->fileWrite($fh, 'public function set'.$name.'($value) {'."\n");
                $this->fileBump($fh);
                $this->fileWrite($fh, 'return $this->set(\''.$row['Field'].'\', $value);'."\n");
                $this->fileDrop($fh);
                $this->fileWrite($fh, "}\n\n");
            }
        }

        if ($this->json_serialize) {
            $this->fileWrite($fh, 'public function jsonSerialize() {'."\n");
            $this->fileBump($fh);

            $this->fileWrite($fh, 'return ['."\n");
            $this->fileBump($fh);
            foreach ($fields as $i => $row) {
                $fName = $row['Field'];
                if ($this->get_set_funcs) {
                    $name = FieldName::getterName($fName);
                    $this->fileWrite($fh, "'$fName' => ".'$this->'.$name.'(),'."\n");
                } else {
                    $this->fileWrite($fh, "'$fName' => ".'$this->'.$fName.','."\n");
                }
            }
            $this->fileDrop($fh);
            $this->fileWrite($fh, '];'."\n");

            $this->fileDrop($fh);
            $this->fileWrite($fh, "}\n");
        }


        $this->fileDrop($fh);
        $this->fileWrite($fh, "}\n\n");
        $this->fileClose($fh);

        //Create a trait version
        $path     = $this->master_location.DIRECTORY_SEPARATOR.'Generated/Traits/';
        $filename = $table_name.'.php';
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        $fh = $this->fileOpen($path.$filename);
        $this->fileWrite($fh, "<?php\n");
        $this->fileWrite($fh, 'namespace GCWorld\\ORM\\Generated\\Traits;'."\n\n");
        $this->fileWrite($fh, 'trait '.$table_name." \n{\n");
        $this->fileBump($fh);

        foreach ($fields as $i => $row) {
            if (in_array($row['Field'], $primaries)) {
                continue;
            }
            $type = (stristr($row['Type'], 'int') ? 'int   ' : 'string');
            $this->fileWrite($fh, "\n\n");
            $this->fileWrite($fh, '/**'."\n");
            $this->fileWrite($fh, '* @db-info '.$row['Type']."\n");
            $this->fileWrite($fh, '* @var '.$type."\n");
            $this->fileWrite($fh, '*/'."\n");
            if ($this->use_defaults) {
                $this->fileWrite($fh, $this->var_visibility.' $'.str_pad($row['Field'], $max_var_name,
                        ' ').' = '.$this->formatDefault($row).';');
            } else {
                $this->fileWrite($fh, $this->var_visibility.' $'.str_pad($row['Field'], $max_var_name, ' ').' = null;');
            }
        }
        $this->fileWrite($fh, "\n");

        if ($this->get_set_funcs || $this->var_visibility == 'protected') {
            foreach ($fields as $i => $row) {
                if (in_array($row['Field'], $primaries)) {
                    continue;
                }
                $name = FieldName::nameConversion($row['Field']);
                //TODO: Add doc block
                $this->fileWrite($fh, 'public function get'.$name.'() {'."\n");
                $this->fileBump($fh);
                $this->fileWrite($fh, 'return $this->'.$row['Field'].";\n");
                $this->fileDrop($fh);
                $this->fileWrite($fh, "}\n\n");
            }
            $this->fileWrite($fh, "\n");
        }

        $this->fileDrop($fh);
        $this->fileWrite($fh, "}\n\n");
        $this->fileClose($fh);

        return true;
    }

    /**
     * @param $filename
     * @return mixed
     */
    protected function fileOpen($filename)
    {
        $key                          = str_replace('.', '', microtime(true));
        $this->open_files[$key]       = fopen($filename, 'w');
        $this->open_files_level[$key] = 0;

        return $key;
    }

    /**
     * @param $key
     * @param $string
     */
    protected function fileWrite($key, $string)
    {
        fwrite($this->open_files[$key], str_repeat(' ', $this->open_files_level[$key] * 4).$string);
    }

    /**
     * @param $key
     */
    protected function fileBump($key)
    {
        ++$this->open_files_level[$key];
    }

    /**
     * @param $key
     */
    protected function fileDrop($key)
    {
        --$this->open_files_level[$key];
    }

    /**
     * @param $key
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

    private function formatDefault($row)
    {
        $default = $row['Default'];
        if ($default === null) {
            if ($row['Null'] == 'NO') {
                $default = $this->defaultData($row['Type']);
            }
        }

        if (is_numeric($default)) {
            if (strstr($default, '.')) {
                return floatval($default);
            }

            return intval($default);
        }

        return var_export($default, true);
    }

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
            case 'BIGINT':
                return 0;

            case 'DECIMAL':
            case 'FLOAT':
            case 'DOUBLE':
            case 'REAL':
            case 'BIT':
            case 'BOOLEAN':
            case 'SERIAL':
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
}
