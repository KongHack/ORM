<?php
namespace GCWorld\ORM\Abstracts;

use GCWorld\ORM\CommonLoader;
use GCWorld\ORM\ORMException;

/**
 * Class DirectMulti
 * @package GCWorld\ORM\Abstracts
 * @todo: Complete auditing system
 */
abstract class DirectMulti
{
    /**
     * @var \GCWorld\Common\Common
     */
    protected $_common = null;
    /**
     * @var array
     */
    protected $_changed = array();
    /**
     * Set this to false in your class when you don't want to log changes
     * @var boolean
     */
    protected $_audit = false;

    /**
     * @var string
     */
    protected $myName = null;

    /**
     * Here for reference, will be created in child objects automatically
     * @var array
     */
    public static $dbInfo = [];

    /**
     * DirectMulti constructor.
     *
     * NOTE: Only pass in IDs as arguments, not an array!
     *
     * @param mixed ...$keys
     * @throws ORMException
     */
    protected function __construct(...$keys)
    {
        $this->myName  = get_class($this);
        $table_name    = constant($this->myName.'::CLASS_TABLE');
        $primaries     = constant($this->myName.'::CLASS_PRIMARIES');
        $this->_common = CommonLoader::getCommon();


        if (!is_object($this->_common)) {
            debug_print_backtrace();
            die('COMMON NOT FOUND<br>'.$table_name.'<br>'.var_export($primaries, true).'<br>'.var_export($keys, true));
        }
        $db = $this->_common->getDatabase();
        if (!is_object($db)) {
            debug_print_backtrace();
            die('Database Not Defined<br>'.$table_name.'<br>'.var_export($primaries, true).'<br>'.var_export(
                $keys,
                true
            ));
        }

        if (count($keys) == count($primaries)) {
            // Determine if we have this in the cache.

            $redis = $this->_common->getCache();
            if ($redis) {
                $json = $redis->hGet($this->myName, 'key_'.implode('-', $keys));
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

            $params    = array();
            $sql       = 'SELECT * FROM '.$table_name.' WHERE ';
            $sqlBlocks = array();
            foreach ($primaries as $k => $primary) {
                $sqlBlocks[]    = ' '.$primary.' = :id_'.$k;
                $params[':id_'] = $keys[$k];
            }
            $sql .= implode(' AND ', $sqlBlocks);

            $query = $this->_common->getDatabase()->prepare($sql);
            $query->execute($params);
            $data = $query->fetch();
            if (!is_array($data)) {
                throw new ORMException($this->myName.' Construct Failed');
            }
            if (!isset($redis)) {
                $redis = $this->_common->getCache();
            }
            if ($redis) {
                $redis->hSet($this->myName, 'key_'.implode('-', $keys), json_encode($data));
            }
            $properties = array_keys(get_object_vars($this));
            foreach ($data as $k => $v) {
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
     * @return bool
     */
    public function save()
    {
        $table_name = constant($this->myName.'::CLASS_TABLE');
        $primaries  = constant($this->myName.'::CLASS_PRIMARIES');

        if (count($this->_changed) > 0) {
            /** @var \GCWorld\Database\Database $db */
            $db = $this->_common->getDatabase();

            if ($this->_audit) {
                // TODO: Build a multi-audit system.  Before goes here.
            }

            $params = array();
            $sql    = 'UPDATE '.$table_name.' SET ';
            foreach ($this->_changed as $key) {
                $sql             .= $key.' = :'.$key.', ';
                $params[':'.$key] = $this->$key;
            }
            $sql       = substr($sql, 0, -2).' WHERE ';   //Remove last ', ';
            $sqlBlocks = array();
            foreach ($primaries as $k => $primary) {
                $sqlBlocks[]    = ' '.$primary.' = :id_'.$k;
                $params[':id_'] = $this->$primary;
            }
            $sql .= implode(' AND ', $sqlBlocks);

            $query = $db->prepare($sql);
            $query->execute($params);
            $query->closeCursor();

            /*
            if ($this->_audit) {
                // TODO: Build a multi-audit system.  After goes here.
            }
            */

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
        $redis = $this->_common->getCache();
        if ($redis) {
            $primaries = constant($this->myName.'::CLASS_PRIMARIES');
            $keys      = array();
            foreach ($primaries as $pk) {
                $keys[] = $this->$pk;
            }
            $redis->hDel($this->myName, 'key_'.implode($keys));
        }
    }

    /**
     * Gets the field keys from the dbInfo array.
     * @return array
     */
    public function getFieldKeys()
    {
        return array_keys(self::$dbInfo);
    }

    /**
     * @return bool
     */
    public function _hasChanged()
    {
        return (count($this->_changed) > 0);
    }
}
