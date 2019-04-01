<?php
namespace GCWorld\ORM\Abstracts;

use GCWorld\ORM\Audit;
use GCWorld\ORM\CommonLoader;
use GCWorld\ORM\Config;
use GCWorld\ORM\ORMException;

/**
 * Class DirectSingle
 * @package GCWorld\ORM\Abstracts
 */
abstract class DirectSingle
{
    /**
     * @var \GCWorld\Common\Common
     */
    protected $_common = null;
    /**
     *
     * Set this in the event your class needs a non-standard DB.
     * @var null|string
     */
    protected $_dbName = null;

    /**
     * @var \GCWorld\Database\Database
     */
    protected $_db = null;

    /**
     * Set this in the event your class needs a non-standard Cache.
     * @var null|string
     */
    protected $_cacheName = null;

    /**
     * @var boolean
     * Set to false if you want to omit this object from your memory cache all together.
     */
    protected $_canCache = true;

    /**
     * @var boolean
     * Set this to false in your class when you don't want to auto re-cache after a purge
     */
    protected $_canCacheAfterPurge = true;

    /**
     * @var \Redis|bool
     */
    protected $_cache = null;

    /**
     * @var array
     */
    protected $_changed = [];

    /**
     * Set this to false in your class when you don't want to log changes
     * @var boolean
     */
    protected $_audit = true;

    /**
     * The last audit object will be set to this upon audit completion
     * @var Audit|null
     */
    protected $_lastAuditObject = null;

    /**
     * Setting this to true will enable insert on duplicate key update features.
     * This also includes not throwing an error on 0 id construct.
     * @var boolean
     */
    protected $_canInsert = false;

    /**
     * Used for reference and to reduce constant check calls
     * @var string
     */
    protected $myName = null;

    /**
     * Here for reference, will be created in child objects automatically
     * @var array
     */
    public static $dbInfo = [];

    /**
     * @param mixed|null $primary_id
     * @param array|null $defaults
     * @throws ORMException
     */
    protected function __construct($primary_id = null, array $defaults = null)
    {
        $this->myName  = get_class($this);
        $table_name    = constant($this->myName.'::CLASS_TABLE');
        $primary_name  = constant($this->myName.'::CLASS_PRIMARY');
        $this->_common = CommonLoader::getCommon();

        if (!is_object($this->_common)) {
            debug_print_backtrace();
            die('COMMON NOT FOUND<br>'.$table_name.'<br>'.$primary_name.'<br>'.$primary_id);
        }

        $this->_db = $this->_common->getDatabase($this->_dbName);
        if (!is_object($this->_db)) {
            debug_print_backtrace();
            die('Database Not Defined<br>'.$table_name.'<br>'.$primary_name.'<br>'.$primary_id);
        }
        $this->_cache = $this->_common->getCache($this->_cacheName);

        if ($primary_id !== null && !is_scalar($primary_id)) {
            throw new ORMException('Primary ID is not scalar');
        }
        if ($defaults !== null && !is_array($defaults)) {
            throw new ORMException('Defaults Array is not an array');
        }

        if ($this->_canCache
            && !empty($primary_id)
            && $primary_id !== null
            && $primary_id !== 0
            && $primary_id !== ''
        ) {
            if ($this->_cache) {
                $json = $this->_cache->hGet($this->myName, 'key_'.$primary_id);
                if (strlen($json) > 2) {
                    $data       = json_decode($json, true);
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
        if ($primary_id !== null && $primary_id !== 0) {
            if (defined($this->myName.'::SQL')) {
                $sql = constant($this->myName.'::SQL');
            } else {
                $sql = 'SELECT * FROM '.$table_name.' WHERE '.$primary_name.' = :id';
            }

            $query = $this->_db->prepare($sql);
            $query->execute([':id' => $primary_id]);
            $defaults = $query->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($defaults)) {
                if (!$this->_canInsert) {
                    $cConfig = new Config();
                    $config  = $cConfig->getConfig();
                    if (isset($config['options']['enable_backtrace']) && $config['options']['enable_backtrace']) {
                        debug_print_backtrace();
                        if (function_exists('d')) {
                            d(func_get_args());
                        }
                    }
                    throw new ORMException($this->myName.' Construct Failed');
                }
            } else {
                if ($this->_canCache) {
                    if (!isset($redis)) {
                        $redis = $this->_common->getCache($this->_cacheName);
                    }
                    if ($redis && $primary_id > 0) {
                        $redis->hSet($this->myName, 'key_'.$primary_id, json_encode($defaults));
                    }
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
     * @param string $key
     * @return mixed
     */
    protected function get(string $key)
    {
        return $this->$key;
    }

    /**
     * @param array $fields
     * @return array
     */
    protected function getArray(array $fields)
    {
        $return = [];
        foreach ($fields as $k) {
            $return[$k] = $this->$k;
        }

        return $return;
    }


    /**
     * @param string $key
     * @param mixed  $val
     * @return $this
     */
    protected function set(string $key, $val)
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
    protected function setArray(array $data)
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
        $table_name   = constant($this->myName.'::CLASS_TABLE');
        $primary_name = constant($this->myName.'::CLASS_PRIMARY');

        if (count($this->_changed) > 0) {
            /** @var \GCWorld\Database\Database $db */
            $db     = $this->_db;
            $before = [];
            $after  = [];

            // ============================================================================== Audit
            if ($this->_audit) {
                $sql   = "SELECT * FROM $table_name WHERE $primary_name = :primary";
                $query = $db->prepare($sql);
                $query->execute([':primary' => $this->$primary_name]);
                $before = $query->fetch(\PDO::FETCH_ASSOC);
                $query->closeCursor();
            }

            // ============================================================================== Write Logic
            if ($this->_canInsert) {
                $auto_increment = constant($this->myName.'::CLASS_PRIMARY');
                $fields         = array_keys(static::$dbInfo);
                if (!in_array($primary_name, $fields)) {
                    $fields[] = $primary_name;
                }
                $params = [];
                if (count($fields) > 1) {    // 1 being the primary key
                    $sql = 'INSERT INTO '.$table_name.' ('.implode(', ', $fields).') VALUES (:'.implode(', :', $fields).')
                        ON DUPLICATE KEY UPDATE ';
                    foreach ($fields as $field) {
                        $params[':'.$field] = ($this->$field == null ? '' : $this->$field);
                        if ($field == $primary_name && !$auto_increment) {
                            continue;
                        }
                        $sql .= "$field = VALUES($field), \n";
                    }
                    $sql   = rtrim($sql, ", \n");
                    $query = $this->_db->prepare($sql);
                    $query->execute($params);
                    $newId = $this->_db->lastInsertId();
                    if ($newId > 0) {
                        $this->$primary_name = $newId;
                    }
                    $query->closeCursor();
                } else {
                    $sql = 'INSERT IGNORE INTO '.$table_name.' ('.implode(', ', $fields).') VALUES (:'.implode(
                        ', :',
                        $fields
                    ).')';
                    foreach ($fields as $field) {
                        $params[':'.$field] = ($this->$field == null ? '' : $this->$field);
                        if ($field == $primary_name && !$auto_increment) {
                            continue;
                        }
                        $sql .= "$field = VALUES($field), \n";
                    }
                    $sql   = rtrim($sql, ", \n");
                    $query = $this->_db->prepare($sql);
                    $query->execute($params);
                    $newId = $this->_db->lastInsertId();
                    if ($newId > 0) {
                        $this->$primary_name = $newId;
                    }
                    $query->closeCursor();
                }
            } else {
                $sql                       = 'UPDATE '.$table_name.' SET ';
                $params[':'.$primary_name] = $this->$primary_name;
                foreach ($this->_changed as $key) {
                    $sql             .= $key.' = :'.$key.', ';
                    $params[':'.$key] = $this->$key;
                }
                $sql  = substr($sql, 0, -2);   //Remove last ', ';
                $sql .= ' WHERE '.$primary_name.' = :'.$primary_name;

                $query = $db->prepare($sql);
                $query->execute($params);
                $query->closeCursor();
            }

            // ============================================================================== Audit
            if ($this->_audit) {
                $sql   = 'SELECT * FROM '.$table_name.' WHERE '.$primary_name.' = :primary';
                $query = $db->prepare($sql);
                $query->execute([':primary' => $this->$primary_name]);
                $after = $query->fetch(\PDO::FETCH_ASSOC);
                $query->closeCursor();

                // The is_array check solves issues with canInsert style objects
                if (is_array($before) && is_array($after)) {
                    $audit = new Audit($this->_common);
                    $audit->storeLog($table_name, $this->$primary_name, $before, $after);
                    $this->_lastAuditObject = $audit;
                }
            }

            $this->purgeCache();

            return true;
        }

        return false;
    }

    /**
     * Purges the current item from Redis
     * @return void
     */
    public function purgeCache()
    {
        if ($this->_canCache && $this->_cache) {
            $primary_name = constant($this->myName.'::CLASS_PRIMARY');
            $this->_cache->hDel($this->myName, 'key_'.$this->$primary_name);

            if ($this->_canCacheAfterPurge) {
                $fields = array_keys(self::$dbInfo);
                $data   = [];
                foreach ($fields as $field) {
                    $data[$field] = $this->$field;
                }
                $this->_cache->hSet($this->myName, 'key_'.$this->$primary_name, json_encode($data));
            }
        }
    }

    /**
     * Gets the field keys from the dbInfo array.
     * @return array
     */
    public function getFieldKeys()
    {
        return array_keys(static::$dbInfo);
    }

    /**
     * @return bool
     */
    public function _hasChanged()
    {
        return (count($this->_changed) > 0);
    }
}
