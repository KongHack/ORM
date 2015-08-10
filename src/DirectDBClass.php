<?php
namespace GCWorld\ORM;

abstract class DirectDBClass
{
    /**
     * @var \GCWorld\Interfaces\Common
     */
    protected $_common  = null;
    protected $_changed = array();
    protected $_audit   = true;

    /**
     * @param      $common
     * @param null $primary_id
     * @param null $defaults
     * @throws \GCWorld\ORM\ORMException
     */
    public function __construct($common, $primary_id = null, $defaults = null)
    {
        $table_name     = constant(get_class($this) . '::CLASS_TABLE');
        $primary_name   = constant(get_class($this) . '::CLASS_PRIMARY');
        $this->_common  = $common;
        
        
        if (!is_object($this->_common)) {
            debug_print_backtrace();
            die('COMMON NOT FOUND<br>'.$table_name.'<br>'.$primary_name.'<br>'.$primary_id);
        }
        if (!is_object($this->_common->getDatabase())) {
            debug_print_backtrace();
            die('Database Not Defined<br>'.$table_name.'<br>'.$primary_name.'<br>'.$primary_id);
        }
        
        if ($primary_id != null) {
            if (defined(get_class($this).'::SQL')) {
                $sql = constant(get_class($this).'::SQL');
            } else {
                $sql = 'SELECT * FROM '.$table_name.'
					WHERE '.$primary_name.' = :id';
            }

            $query = $this->_common->DB()->prepare($sql);
            $query->execute(array(':id'=>$primary_id));
            $defaults = $query->fetch();
            if (!is_array($defaults)) {
                throw new ORMException(get_class($this).' Construct Failed');
            }
        }
        if (is_array($defaults)) {
            $properties = array_keys(get_object_vars($this));
            foreach ($defaults as $k => $v) {
                if (in_array($k, $properties)) {
                    $this->$k = $v;
                }
            }
        }
    }

    /**
     * @param $key
     * @return mixed
     */
    public function get($key)
    {
        return $this->$key;
    }

    /**
     * @param $key
     * @param $val
     */
    public function set($key, $val)
    {
        if ($this->$key !== $val) {
            $this->$key = $val;
            if (!in_array($key, $this->_changed)) {
                $this->_changed[] = $key;
            }
        }
    }

    /**
     * @return bool
     */
    public function save()
    {
        $table_name         = constant(get_class($this) . '::CLASS_TABLE');
        $primary_name   = constant(get_class($this) . '::CLASS_PRIMARY');

        if (count($this->_changed) > 0) {
        /** @var \GCWorld\Database\Database $db */
            $db = $this->_common->getDatabase();

            if ($this->_audit) {
                $sql = 'SELECT * FROM '.$table_name.' WHERE '.$primary_name.' = :primary';
                $query = $db->prepare($sql);
                $query->execute(array(':primary'=>$this->$primary_name));
                $before = $query->fetch();
                $query->closeCursor();
            }

            $sql = 'UPDATE '.$table_name.' SET ';
            $params[':'.$primary_name] = $this->$primary_name;
            foreach ($this->_changed as $key) {
                $sql .= $key.' = :'.$key.', ';
                $params[':'.$key] = $this->$key;
            }
            $sql = substr($sql, 0, -2);   //Remove last ', ';
            $sql .= ' WHERE '.$primary_name.' = :'.$primary_name;

            $query = $db->prepare($sql);
            $query->execute($params);
            $query->closeCursor();

            if ($this->_audit) {
                $sql = 'SELECT * FROM '.$table_name.' WHERE '.$primary_name.' = :primary';
                $query = $db->prepare($sql);
                $query->execute(array(':primary'=>$this->$primary_name));
                $after = $query->fetch();
                $query->closeCursor();

                //Audit Here
                $memberID = 0;
                if (method_exists($this->_common, 'getUser')) {
                    $user = $this->_common->getUser();
                    $user_primary = constant(get_class($user) . '::CLASS_PRIMARY');
                    $memberID = $user->$user_primary;
                }

                $audit = new Audit($this->_common);
                $audit->storeLog($table_name, $this->$primary_name, $memberID, $before, $after);
            }

            return true;
        }
        return false;
    }
}
