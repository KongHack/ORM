<?php
namespace GCWorld\ORM;

abstract class DirectDBClass
{
    /**
     * @var \GCWorld\Common\Common
     */
    protected $_common  = null;
    /**
     * Set this in the event your class needs a non-standard DB.
     * @var null|string
     */
    protected $_dbName  = null;

    /**
     * Set this in the event your class needs a non-standard Cache.
     * @var null|string
     */
    protected $_cacheName = null;

    /**
     * @var \GCWorld\Database\Database
     */
    protected $_db      = null;

    /**
     * Set to false if you want to omit this object from your memory cache all together.
     * @var bool
     */
    protected $_canCache = true;

    /**
     * @var \Redis|bool
     */
    protected $_cache   = null;

    /**
     * @var array
     */
    protected $_changed = array();
    /**
     * Set this to false in your class when you don't want to log changes
     * @var bool
     */
    protected $_audit   = true;

    /**
     * @var string
     */
    protected $myName = null;

    /**
     * @param      $common
     * @param null $primary_id
     * @param null $defaults
     * @throws \GCWorld\ORM\ORMException
     */
    public function __construct($primary_id = null, $defaults = null)
    {
        $this->myName  = get_class($this);
        $table_name    = constant($this->myName . '::CLASS_TABLE');
        $primary_name  = constant($this->myName . '::CLASS_PRIMARY');
        $this->_common = CommonLoader::getCommon();

        if (!is_object($this->_common)) {
            debug_print_backtrace();
            die('COMMON NOT FOUND<br>'.$table_name.'<br>'.$primary_name.'<br>'.$primary_id);
        }

        $this->_db     = $this->_common->getDatabase($this->_dbName);
        if (!is_object($this->_db)) {
            debug_print_backtrace();
            die('Database Not Defined<br>'.$table_name.'<br>'.$primary_name.'<br>'.$primary_id);
        }
        $this->_cache  = $this->_common->getCache($this->_cacheName);

        if ($primary_id !== null && !is_scalar($primary_id)) {
            throw new ORMException('Primary ID is not scalar');
        }
        if ($defaults !== null && !is_array($defaults)) {
            throw new ORMException('Defaults Array is not an array');
        }

        if ($this->_canCache && $primary_id != null) {
            // Determine if we have this in the cache.
            if ($primary_id > 0) {
                if ($this->_cache) {
                    $json = $this->_cache->hGet($this->myName, 'key_'.$primary_id);
                    if (strlen($json) > 2) {
                        $data = json_decode($json, true);
                        $properties = array_keys(get_object_vars($this));
                        foreach ($data as $k => $v) {
                            if (in_array($k, $properties)) {
                                $this->$k = $v;
                            }
                        }
                        return;
                    }
                }
            }

            if (defined($this->myName.'::SQL')) {
                $sql = constant($this->myName.'::SQL');
            } else {
                $sql = 'SELECT * FROM '.$table_name.' WHERE '.$primary_name.' = :id';
            }

            $query = $this->_db->prepare($sql);
            $query->execute(array(':id'=>$primary_id));
            $defaults = $query->fetch();
            if (!is_array($defaults)) {
                throw new ORMException($this->myName.' Construct Failed');
            }
            if ($this->_canCache) {
                if (!isset($redis)) {
                    $redis = $this->_common->getCache();
                }
                if ($redis) {
                    $redis->hSet($this->myName, 'key_'.$primary_id, json_encode($defaults));
                }
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
     * @param array $fields
     * @return array
     */
    public function getArray(array $fields)
    {
        $return = [];
        foreach ($fields as $k) {
            $return[$k] = $this->$k;
        }
        return $return;
    }


    /**
     * @param $key
     * @param $val
     * @return $this
     */
    public function set($key, $val)
    {
        if ($this->$key !== $val) {
            $this->$key = $val;
            if (!in_array($key, $this->_changed)) {
                $this->_changed[] = $key;
            }
        }
        return $this;
    }

    /**
     * @param array $data
     * @return $this
     */
    public function setArray(array $data)
    {
        foreach ($data as $k => $v) {
            $this->set($k, $v);
        }
        return $this;
    }

    /**
     * @return bool
     */
    public function save()
    {
        $table_name     = constant($this->myName . '::CLASS_TABLE');
        $primary_name   = constant($this->myName . '::CLASS_PRIMARY');

        if (count($this->_changed) > 0) {
        /** @var \GCWorld\Database\Database $db */
            $db = $this->_db;

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

            $this->purgeCache();
            return true;
        }
        return false;
    }

    /**
     * Purges the current item from Redis
     */
    public function purgeCache()
    {
        if ($this->_canCache && $this->_cache) {
            $primary_name  = constant($this->myName . '::CLASS_PRIMARY');
            $this->_cache->hDel($this->myName, 'key_'.$this->$primary_name);
        }
    }
}
