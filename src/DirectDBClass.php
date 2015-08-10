<?php
namespace GCWorld\ORM;

abstract class DirectDBClass
{
	protected $_common	= null;
	protected $_changed	= array();

    /**
     * @param      $common
     * @param null $primary_id
     * @param null $defaults
     * @throws \GCWorld\ORM\ORMException
     */
	public function __construct($common, $primary_id = null, $defaults = null)
	{
		$table_name		= constant(get_class($this) . '::CLASS_TABLE');
		$primary_name	= constant(get_class($this) . '::CLASS_PRIMARY');
		$this->_common	= $common;
		
		
		if(!is_object($this->_common))
		{
			debug_print_backtrace();
			die('COMMON NOT FOUND<br>'.$table_name.'<br>'.$primary_name.'<br>'.$primary_id);
		}
		if(!is_object($this->_common->DB()))
		{
			debug_print_backtrace();
			die('Database Not Defined<br>'.$table_name.'<br>'.$primary_name.'<br>'.$primary_id);
		}
		
		if($primary_id != null)
		{
			$sql = 'SELECT * FROM '.$table_name.'
					WHERE '.$primary_name.' = :id';
			$query = $this->_common->DB()->prepare($sql);
			$query->execute(array(':id'=>$primary_id));
			$defaults = $query->fetch();
			if(!is_array($defaults))
			{
				throw new ORMException(get_class($this).' Construct Failed');
			}
		}
		if(is_array($defaults))
		{
			$properties = array_keys(get_object_vars($this));
			foreach($defaults as $k => $v)
			{
				if(in_array($k, $properties))
				{
					$this->$k = $v;
				}
			}
		}
	}

	public function get($key)
	{
		return $this->$key;
	}

	public function set($key, $val)
	{
		if($this->$key !== $val)
		{
			$this->$key = $val;
			if(!in_array($key, $this->_changed))
			{
				$this->_changed[] = $key;
			}
		}
	}
	public function save()
	{
		$table_name		= constant(get_class($this) . '::CLASS_TABLE');
		$primary_name	= constant(get_class($this) . '::CLASS_PRIMARY');

		if(count($this->_changed) > 0)
		{
			$sql = 'UPDATE '.$table_name.' SET ';
			$params[':'.$primary_name] = $this->$primary_name;
			foreach($this->_changed as $key)
			{
				$sql .= $key.' = :'.$key.', ';
				$params[':'.$key] = $this->$key;
			}
			$sql = substr($sql,0,-2);	//Remove last ', ';
			$sql .= ' WHERE '.$primary_name.' = :'.$primary_name;

			$query = $this->_common->DB()->prepare($sql);
			$query->execute($params);
			return true;
		}
		return false;
	}
}
