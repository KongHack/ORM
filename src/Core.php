<?php
namespace GCWorld\ORM;

use \ReflectionClass;
use \Exception;
use \PDO;

class Core
{
    protected $master_namespace     = '\\';
    /** @var \GCWorld\Common\Common */
    protected $master_common        = null;
    protected $master_location      = null;
    private $open_files             = array();
    private $open_files_level       = array();

    protected $get_set_funcs        = true;
    protected $var_visibility       = 'public';

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
        $config = $cConfig->getConfig();

        if(isset($config['get_set_funcs'])) {
            if($config['get_set_funcs'] == 'false') {
                $this->get_set_funcs = false;
            }
        }
        if(isset($config['var_visibility']) && in_array($config['var_visibility'],['public','protected'])) {
            $this->var_visibility = $config['var_visibility'];
        }
    }

    /**
     * @param $table_name
     */
    public function generate($table_name)
    {
        $sql = 'SHOW FULL COLUMNS FROM '.$table_name;
        $query = $this->master_common->getDatabase()->prepare($sql);
        $query->execute();
        $fields = $query->fetchAll(PDO::FETCH_ASSOC);

        $primaries = array();
        $max_var_name = 0;
        $max_var_type = 0;

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

        }

        $filename = $table_name.'.php';
        $fh = $this->fileOpen($path.$filename);


        if (count($primaries) < 1) {
            return false;
        }


        $this->fileWrite($fh, "<?php\n");
        $this->fileWrite($fh, 'namespace GCWorld\\ORM\\Generated;'."\n\n");
        if (count($primaries) == 1) {
            // Single PK Classes get a simple set of functions.
            $this->fileWrite($fh, 'use \\GCWorld\\ORM\\DirectDBClass AS dbc;'."\n\n");
            $this->fileWrite($fh, 'use \\GCWorld\\ORM\\GeneratedInterface AS dbi;'."\n\n");
            $this->fileWrite($fh, 'class '.$table_name." extends dbc implements dbi\n{\n");
            $this->fileBump($fh);
            $this->fileWrite($fh, "CONST ".str_pad('CLASS_TABLE', $max_var_name, ' ')."   = '$table_name';\n");
            $this->fileWrite($fh, "CONST ".str_pad('CLASS_PRIMARY', $max_var_name, ' ')."   = '".$primaries[0]."';\n\n");

        } else {
            // Multiple primary keys!!!
            $this->fileWrite($fh, 'use \\GCWorld\\ORM\\DirectDBMultiClass AS dbc;'."\n\n");
            $this->fileWrite($fh, 'use \\GCWorld\\ORM\\GeneratedMultiInterface AS dbi;'."\n\n");
            $this->fileWrite($fh, 'class '.$table_name." extends dbc implements dbi\n{\n");
            $this->fileBump($fh);
            $this->fileWrite($fh, "CONST ".str_pad('CLASS_TABLE', $max_var_name, ' ')."   = '$table_name';\n");
            $this->fileWrite($fh, "CONST ".str_pad('CLASS_PRIMARIES', $max_var_name, ' ')."   = ".var_export($primaries, true).";\n\n");

        }

        foreach ($fields as $i => $row) {
            $type = (stristr($row['Type'],'int') ? 'int   ' : 'string');
            $this->fileWrite($fh, "\n".'/**'."\n");
            $this->fileWrite($fh, '* @dbinfo '.$row['Type']."\n");
            $this->fileWrite($fh, '* @var '.$type."\n");
            $this->fileWrite($fh, '*/'."\n");
            $this->fileWrite($fh, $this->var_visibility.' $'.str_pad($row['Field'], $max_var_name, ' ').' = null;');
        }
        $this->fileWrite($fh, "\n");
        $this->fileWrite($fh, '/**'."\n");
        $this->fileWrite($fh, '* Contains an array of all fields and the database notation for field type'."\n");
        $this->fileWrite($fh, '* @var array'."\n");
        $this->fileWrite($fh, '*/'."\n");
        $this->fileWrite($fh, 'public static $dbInfo = array('."\n");
        $this->fileBump($fh);

        foreach ($fields as $i => $row) {
            $this->fileWrite($fh, str_pad(
                "'".$row['Field']."'",
                $max_var_name + 2,
                ' '
            )." => '".$row['Type'].($row['Comment'] != '' ? ' - '.$row['Comment'] : '')."',\n");
        }
        $this->fileDrop($fh);
        $this->fileWrite($fh, ");\n");

        if ($this->get_set_funcs) {
            $this->fileWrite($fh,"\n");

            foreach ($fields as $i => $row) {
                $name = str_replace('_','',ucwords($row['Field'], '_'));

                //TODO: Add doc block
                $this->fileWrite($fh, 'public function get'.$name.'() {'."\n");
                $this->fileBump($fh);
                $this->fileWrite($fh, 'return $this->get(\''.$row['Field']."');\n");
                $this->fileDrop($fh);
                $this->fileWrite($fh, "}\n\n");
            }

            foreach ($fields as $i => $row) {
                $name = str_replace('_','',ucwords($row['Field'], '_'));
                $this->fileWrite($fh, '/**'."\n");
                $this->fileWrite($fh, '* @param mixed $value'."\n");
                $this->fileWrite($fh, '* @return $this'."\n");
                $this->fileWrite($fh, '*/'."\n");
                $this->fileWrite($fh, 'public function set'.$name.'($value) {'."\n");
                $this->fileBump($fh);
                $this->fileWrite($fh, 'return $this->set(\''.$row['Field'].'\', \'$value\');'."\n");
                $this->fileDrop($fh);
                $this->fileWrite($fh, "}\n\n");
            }
        }

        $this->fileDrop($fh);
        $this->fileWrite($fh, "}\n\n");
        $this->fileClose($fh);

        //Create a trait version
        $path = $this->master_location.DIRECTORY_SEPARATOR.'Generated/Traits/';
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
            $this->fileWrite($fh, $this->var_visibility.' $'.str_pad($row['Field'], $max_var_name, ' ').' = null;');
            $this->fileWrite($fh, ' // '.$row['Type']."\n");
        }
        $this->fileWrite($fh, "\n");

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
        $key = str_replace('.', '', microtime(true));
        $this->open_files[$key] = fopen($filename, 'w');
        $this->open_files_level[$key] = 0;
        return $key;
    }

    /**
     * @param $key
     * @param $string
     */
    protected function fileWrite($key, $string)
    {
        fwrite($this->open_files[$key], str_repeat(' ', $this->open_files_level[$key]*4).$string);
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
        $args = func_get_args();
        $class_name = '\\GCWorld\\ORM\\Generated\\'.$args[0];
        
        if (!class_exists($class_name)) {
            die('Invalid Class: '.$class_name);
        }
        $args[0] = $this->master_common;
        
        $reflectionClass = new ReflectionClass($class_name);
        $module = $reflectionClass->newInstanceArgs($args);
        return $module;
        //$handler = call_user_func_array($class_name, $args);
    }
}
