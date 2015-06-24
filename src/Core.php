<?php
namespace GCWorld\ORM;

use \ReflectionClass;
use \Exception;
use \PDO;

class Core
{
	protected $master_namespace		= '\\';
	protected $master_common		= '\\';
	protected $master_location		= null;
	private $open_files				= array();
	private $open_files_level		= array();

	/**
	 * @param $namespace
	 * @param $common
	 */
	public function __construct($namespace, $common)
	{
		$this->master_namespace = $namespace;
		$this->master_common	= $common;
		$this->master_location	= __DIR__;
	}

	/**
	 * @param $table_name
	 */
	public function generate($table_name)
	{
		$sql = 'SHOW FULL COLUMNS FROM '.$table_name;
		$query = $this->master_common->DB()->prepare($sql);
		$query->execute();
		$fields = $query->fetchAll(PDO::FETCH_ASSOC);

		$pk_name = '';
		$pk_count = 0;
		$max_var_name	= 0;
		$max_var_type	= 0;
		foreach($fields as $i => $row)
		{
			if(strstr($row['Key'],'PRI'))
			{
				$pk_name = $row['Field'];
				++$pk_count;
			}
			if(strlen($row['Field']) > $max_var_name)
			{
				$max_var_name = strlen($row['Field']);
			}
			if(strlen($row['Type']) > $max_var_type)
			{
				$max_var_type = strlen($row['Type']);
			}
			
		}

		if($pk_count != 1)
		{
			return false;
			//throw new Exception('Invalid Primary Key ('.$pk_count.')');
		}
		
		
		//Let's get to generating.
		$path = $this->master_location . DIRECTORY_SEPARATOR . 'Generated/';
		$filename = $table_name.'.php';

		// Note: The following block was used when PSR-0 auto-loading.
		// This library has been converted to PSR-4, but I am leaving this code here in the event
		// it's ever needed.
		/*
		$parts = explode('_',$table_name);
		$filename = array_pop($parts).'.php';
		foreach($parts as $folder)
		{
			$path .= $folder. DIRECTORY_SEPARATOR;
		}
		unset($parts);
		*/
		if (!is_dir($path)) {
			mkdir($path, 0755, true);
		}


		$fh = $this->fileOpen($path.$filename);
		$this->fileWrite($fh, "<?php\n");
		$this->fileWrite($fh, 'namespace GCWorld\\ORM\\Generated;'."\n\n");
		$this->fileWrite($fh, 'use \\GCWorld\\ORM\\DirectDBClass AS dbc;'."\n\n");
		$this->fileWrite($fh, 'use \\GCWorld\\ORM\\GeneratedInterface AS dbi;'."\n\n");
		$this->fileWrite($fh, 'class '.$table_name." extends dbc implements dbi\n{\n");
		$this->fileBump($fh);
		$this->fileWrite($fh, "CONST ".str_pad('CLASS_TABLE',$max_var_name,' ')."   = '$table_name';\n");
		$this->fileWrite($fh, "CONST ".str_pad('CLASS_PRIMARY',$max_var_name,' ')."   = '$pk_name';\n\n");

		foreach($fields as $i => $row)
		{
			$this->fileWrite($fh, 'public $'.str_pad($row['Field'],$max_var_name,' ').' = null;');
			$this->fileWrite($fh, ' // '.$row['Type']."\n");
		}
		$this->fileWrite($fh,"\n");
		$this->fileWrite($fh,'public static $dbInfo = array('."\n");
		$this->fileBump($fh);

		foreach($fields as $i => $row)
		{
			$this->fileWrite($fh, str_pad("'".$row['Field']."'",$max_var_name+2,' ')." => '".$row['Type'].($row['Comment']!=''?' - '.$row['Comment']:'')."',\n");
		}
		$this->fileDrop($fh);
		$this->fileWrite($fh,");\n");
		
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
        $this->fileWrite($fh, 'namespace GCWorld\\ORM\\Generated;'."\n\n");
        $this->fileWrite($fh, 'trait '.$table_name." \n{\n");
        $this->fileBump($fh);

        foreach($fields as $i => $row) {
            if($row['Field']==$pk_name) {
                continue;
            }
            $this->fileWrite($fh, 'public $'.str_pad($row['Field'],$max_var_name,' ').' = null;');
            $this->fileWrite($fh, ' // '.$row['Type']."\n");
        }
        $this->fileWrite($fh,"\n");

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
		$key = str_replace('.','',microtime(true));
		$this->open_files[$key] = fopen($filename,'w');
		$this->open_files_level[$key] = 0;
		return $key;
	}

	/**
	 * @param $key
	 * @param $string
	 */
	protected function fileWrite($key, $string)
	{
		fwrite($this->open_files[$key], str_repeat(' ',$this->open_files_level[$key]*4).$string);
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
	public function load(/* polymorphic args */)
	{
		$args = func_get_args();
		$class_name = '\\GCWorld\\ORM\\Generated\\'.$args[0];
		
		if(!class_exists($class_name))
		{
			die('Invalid Class: '.$class_name);
		}
		$args[0] = $this->master_common;
		
		$reflectionClass = new ReflectionClass($class_name);
		$module = $reflectionClass->newInstanceArgs($args);
		return $module;
		//$handler = call_user_func_array($class_name, $args);
	}
}
