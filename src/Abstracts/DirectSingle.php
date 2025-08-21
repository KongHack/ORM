<?php
namespace GCWorld\ORM\Abstracts;

use Exception;
use GCWorld\Interfaces\CommonInterface;
use GCWorld\Interfaces\Database\DatabaseInterface;
use GCWorld\ORM\CommonLoader;
use GCWorld\ORM\Config;
use GCWorld\ORM\Interfaces\AuditInterface;
use GCWorld\ORM\Interfaces\DirectSingleInterface;
use GCWorld\ORM\ORMException;
use GCWorld\ORM\ORMLogger;
use PDO;
use Redis;

/**
 * Class DirectSingle.
 *
 * @SuppressWarnings(PHPMD.DevelopmentCodeFragment)
 */
abstract class DirectSingle implements DirectSingleInterface
{
    /**
     * Here for reference, will be created in child objects automatically.
     *
     * @var array
     */
    public static $dbInfo = [];
    /**
     * @var \GCWorld\Common\Common|CommonInterface
     */
    protected $_common;
    /**
     * Set this in the event your class needs a non-standard DB.
     *
     * @var string|null
     */
    protected $_dbName;

    /**
     * @var \GCWorld\Database\Database|DatabaseInterface
     */
    protected ?DatabaseInterface $_db = null;

    /**
     * Set this in the event your class needs a non-standard Cache.
     *
     * @var string|null
     */
    protected $_cacheName;

    /**
     * @var bool
     *           Set to false if you want to omit this object from your memory cache all together
     */
    protected $_canCache = true;

    /**
     * @var int
     *          TTL for cache items.  -1 = disabled
     */
    protected $_cacheTTL = 60;

    /**
     * @var bool
     *           Set this to false in your class when you don't want to auto re-cache after a purge
     */
    protected $_canCacheAfterPurge = true;

    /**
     * @var Redis|null
     */
    protected $_cache;

    /**
     * @var array
     */
    protected $_changed = [];

    /**
     * @var array
     */
    protected $_lastChanged = [];

    /**
     * Set this to false in your class when you don't want to log changes.
     *
     * @var bool
     */
    protected $_audit = true;

    /**
     * @var ?string
     */
    protected ?string $_auditHandler = null;

    /**
     * The last audit object will be set to this upon audit completion.
     *
     * @var AuditInterface|null
     */
    protected $_lastAuditObject;

    /**
     * Setting this to true will enable insert on duplicate key update features.
     * This also includes not throwing an error on 0 id construct.
     *
     * @var bool
     */
    protected $_canInsert = false;

    /**
     * Used for reference and to reduce constant check calls.
     *
     * @var string
     */
    protected $myName;

    /**
     * @param mixed|null $primary_id
     * @param array|null $defaults
     *
     * @throws ORMException
     */
    protected function __construct(mixed $primary_id = null, ?array $defaults = null)
    {
        $cLogger       = ORMLogger::getLogger();
        $this->myName  = \get_class($this);
        $table_name    = \constant($this->myName.'::CLASS_TABLE');
        $primary_name  = \constant($this->myName.'::CLASS_PRIMARY');
        $this->_common = CommonLoader::getCommon();

        $cLogger->info('ORM: DS: '.$table_name, \func_get_args());
        $this->_db    = $this->_common->getDatabase($this->_dbName);
        $this->_cache = $this->_common->getCache($this->_cacheName);

        if (null !== $primary_id && !\is_scalar($primary_id)) {
            $cLogger->info('ORM: DS: '.$table_name.': Primary ID is not scalar', \debug_backtrace());

            throw new ORMException('Primary ID is not scalar');
        }

        $cachable = $this->_canCache && !empty($this->_cache) && 0 !== $this->_cacheTTL;

        if ($cachable
            && !empty($primary_id)
            && null !== $primary_id
            && 0 !== $primary_id
            && '' !== $primary_id
        ) {
            $blob = $this->_cache->hGet($this->myName, 'key_'.$primary_id);
            $cLogger->info('ORM: DS: '.$table_name.': Cache1: Blob Acquired', [
                'key'  => $this->myName,
                'hash' => 'key_'.$primary_id,
                'blob' => $blob,
            ]);
            if (false !== $blob && null !== $blob && !empty($blob)) {
                $cLogger->info('ORM: DS: '.$table_name.': Cache1: Blob is not false, not null, and not empty');

                try {
                    $data = @\unserialize($blob);
                } catch (Exception $e) {
                    $data = null;
                }
                if (\is_array($data)
                    && $this->_cacheTTL > 0
                    && (!isset($data['ORM_TIME']) || $data['ORM_TIME'] < \time())
                ) {
                    $cLogger->info('ORM: DS: '.$table_name.': Cache1: Data is Expired');
                    $data = null;
                }

                if (null !== $data && \is_array($data) && !empty($data)) {
                    $cLogger->info('ORM: DS: '.$table_name.': Cache1: Data is Good');
                    unset($data['ORM_TIME']); // This is our field

                    $fields = \array_keys(static::$dbInfo);
                    if (\count($fields) == \count($data)) {
                        $cLogger->info('ORM: DS: '.$table_name.': Cache1: Count Matches');
                        // $properties = array_keys(get_object_vars($this));
                        foreach ($data as $k => $v) {
                            if (\in_array($k, $fields)) {
                                $this->{$k} = $v;
                            }
                        }
                        $cLogger->info('ORM: DS: '.$table_name.': Cache1: All Good!');

                        return;
                    }
                    $cLogger->info('ORM: DS: '.$table_name.': Cache1: Count does not match', [
                        $fields,
                        $data,
                    ]);
                }
                // If we made it here, the blob is garbage, delete it
                $this->_cache->hDel($this->myName, 'key_'.$primary_id);
            }
        } else {
            $cLogger->info('ORM: DS: Cache1: '.$table_name.': Bad Options', [
                $this->_canCache,
                null !== $this->_cache,
                false !== $this->_cache,
                !empty($primary_id),
                null !== $primary_id,
                0 !== $primary_id,
                '' !== $primary_id,
                $primary_id,
            ]);
        }

        $cLogger->info('ORM: DS: Cache1: '.$table_name.': Exiting Routine');
        if (!empty($primary_id)
            && null !== $primary_id
            && 0 !== $primary_id
            && '' !== $primary_id
        ) {
            if (\defined($this->myName.'::SQL')) {
                $sql = \constant($this->myName.'::SQL');
            } else {
                $tmp = ($cachable ? 'SQL_NO_CACHE' : '');
                $sql = "SELECT {$tmp} * FROM {$table_name} WHERE {$primary_name} = :id";
            }
            $cLogger->info('ORM: DS: SELECT: '.$table_name.': Start', [
                'sql' => $sql,
                'id'  => $primary_id,
            ]);

            $query = $this->_db->prepare($sql);
            $query->execute([':id' => $primary_id]);
            $defaults = $query->fetch(PDO::FETCH_ASSOC);
            $query->closeCursor();
            unset($query);
            if (!\is_array($defaults)) {
                $cLogger->info('ORM: DS: SELECT: '.$table_name.': Data Not Found');
                if (!$this->_canInsert) {
                    $cConfig = new Config();
                    $config  = $cConfig->getConfig();
                    if (isset($config['options']['enable_backtrace']) && $config['options']['enable_backtrace']) {
                        \debug_print_backtrace();
                        if (\function_exists('d')) {
                            d(\func_get_args());
                        }
                    } else {
                        $cLogger->info('ORM: DS: SELECT: '.$table_name.': Backtrace', [
                            'args'  => \func_get_args(),
                            'trace' => \debug_backtrace(),
                        ]);
                    }

                    throw new ORMException($this->myName.' Construct Failed');
                }
            } else {
                $cLogger->info('ORM: DS: SELECT: '.$table_name.': Select Success', $defaults);
            }
        }

        if (\is_array($defaults)) {
            $fields = \array_keys(static::$dbInfo);
            foreach ($defaults as $k => $v) {
                if (\in_array($k, $fields)) {
                    $this->{$k} = $v;
                }
            }
        }
        if ($cachable) {
            $cLogger->info('ORM: DS: SELECT: '.$table_name.': Can Cache');
            if ($primary_id > 0) {
                $this->setCacheData();
            }
        }
    }

    /**
     * @return bool
     */
    public function save(): bool
    {
        $table_name    = \constant($this->myName.'::CLASS_TABLE');
        $primary_name  = \constant($this->myName.'::CLASS_PRIMARY');
        $version_field = \constant($this->myName.'::VERSION_FIELD');

        if (empty($this->_changed)) {
            return false;
        }

        $save_hook = \method_exists($this, 'saveHook');
        $before    = [];
        $after     = [];

        // ============================================================================== Audit
        if ($this->_audit || $save_hook) {
            $sql   = "SELECT * FROM {$table_name} WHERE {$primary_name} = :primary";
            $query = $this->_db->prepare($sql);
            $query->execute([
                ':primary' => $this->{$primary_name},
            ]);
            $before = $query->fetch(PDO::FETCH_ASSOC) ?? [];
            $query->closeCursor();
            unset($query);
        }

        // ============================================================================== Write Logic
        if ($this->_canInsert) {
            $auto_increment = \constant($this->myName.'::CLASS_PRIMARY');
            $fields         = \array_keys(static::$dbInfo);
            if (!\in_array($primary_name, $fields)) {
                $fields[] = $primary_name;
            }
            $params = [];
            if (\count($fields) > 1) {    // 1 being the primary key
                $sql = 'INSERT INTO '.$table_name.
                    ' ('.\implode(', ', $fields).
                    ') VALUES (:'.\implode(', :', $fields).') ON DUPLICATE KEY UPDATE ';
                foreach ($fields as $field) {
                    $params[':'.$field] = (null == $this->{$field} ? '' : $this->{$field});
                    if ($field == $primary_name && !$auto_increment) {
                        continue;
                    }
                    $sql .= "{$field} = VALUES({$field}), \n";
                }
                $sql   = \rtrim($sql, ", \n");
                $query = $this->_db->prepare($sql);
                $query->execute($params);
                $newId = $this->_db->lastInsertId();
                if ($newId > 0) {
                    $this->{$primary_name} = $newId;
                }
                $query->closeCursor();
                unset($query);
            } else {
                $sql = 'INSERT IGNORE INTO '.$table_name.
                    ' ('.\implode(', ', $fields).') VALUES (:'.
                    \implode(', :', $fields).')';
                foreach ($fields as $field) {
                    $params[':'.$field] = (null == $this->{$field} ? '' : $this->{$field});
                    if ($field == $primary_name && !$auto_increment) {
                        continue;
                    }
                    $sql .= "{$field} = VALUES({$field}), \n";
                }
                $sql   = \rtrim($sql, ", \n");
                $query = $this->_db->prepare($sql);
                $query->execute($params);
                $newId = $this->_db->lastInsertId();
                if ($newId > 0) {
                    $this->{$primary_name} = $newId;
                }
                $query->closeCursor();
                unset($query);
            }
        } else {
            $sql                       = 'UPDATE '.$table_name.' SET ';
            $params[':'.$primary_name] = $this->{$primary_name};
            foreach ($this->_changed as $key) {
                $sql             .= $key.' = :'.$key.', ';
                $params[':'.$key] = $this->{$key};
            }
            $sql = \substr($sql, 0, -2);   // Remove last ', ';
            if (!empty($version_field)) {
                $sql .= ', '.$version_field.' = '.$version_field.' + 1';
            }
            $sql .= ' WHERE '.$primary_name.' = :'.$primary_name;

            $qry = $this->_db->prepare($sql);
            $qry->execute($params);
            $qry->closeCursor();
            unset($qry);
        }

        // ============================================================================== Audit
        if ($this->_audit || $save_hook) {
            $sql   = 'SELECT * FROM '.$table_name.' WHERE '.$primary_name.' = :primary';
            $query = $this->_db->prepare($sql);
            $query->execute([
                ':primary' => $this->{$primary_name},
            ]);
            $after = $query->fetch(PDO::FETCH_ASSOC) ?? [];
            $query->closeCursor();
            unset($query);

            // The is_array check solves issues with canInsert style objects
            if (\is_array($before) && \is_array($after) && !empty($this->_auditHandler)) {
                /** @var AuditInterface $cAudit */
                $cAudit = new $this->_auditHandler($this->_common);
                $cAudit->storeLog($table_name, $this->{$primary_name}, $before, $after);
                $this->_lastAuditObject = $cAudit;
            }
        }

        if ($save_hook && \method_exists($this, 'saveHook')) {
            if (!\is_array($before)) {
                $before = [];
            }
            if (!\is_array($after)) {
                $after = [];
            }

            $this->saveHook($before, $after, $this->_changed);
        }

        $this->purgeCache();

        // Now that we have saved everything, there are no remaining changes
        $this->_lastChanged = $this->_changed;
        $this->_changed     = [];

        return true;
    }

    /**
     * Purges the current item from Redis.
     *
     * @return void
     */
    public function purgeCache(): void
    {
        if ($this->_canCache && $this->_cache) {
            $primary_name = \constant($this->myName.'::CLASS_PRIMARY');
            $this->_cache->hDel($this->myName, 'key_'.$this->{$primary_name});

            if ($this->_canCacheAfterPurge) {
                $this->setCacheData();
            }
        }
    }

    /**
     * Gets the field keys from the dbInfo array.
     *
     * @return array
     */
    public function getFieldKeys(): array
    {
        return \array_keys(static::$dbInfo);
    }

    /**
     * @return bool
     */
    public function _hasChanged(): bool
    {
        return \count($this->_changed) > 0;
    }

    /**
     * @return array
     */
    public function _getChanged(): array
    {
        return $this->_changed;
    }

    /**
     * @return array
     */
    public function _getLastChanged(): array
    {
        return $this->_lastChanged;
    }

    /**
     * @param string $key
     *
     * @return mixed
     */
    protected function get(string $key)
    {
        return $this->{$key};
    }

    /**
     * @param array $fields
     *
     * @return array
     */
    protected function getArray(array $fields)
    {
        $return = [];
        foreach ($fields as $k) {
            $return[$k] = $this->{$k};
        }

        return $return;
    }

    /**
     * @param string $key
     * @param mixed  $val
     *
     * @return static
     */
    protected function set(string $key, $val)
    {
        if ($this->{$key} !== $val) {
            $this->{$key} = $val;
            if (!\in_array($key, $this->_changed)) {
                $this->_changed[] = $key;
            }
        }

        return $this;
    }

    /**
     * @param array $data
     *
     * @return static
     */
    protected function setArray(array $data)
    {
        foreach ($data as $k => $v) {
            $this->set($k, $v);
        }

        return $this;
    }

    /**
     * @return void
     */
    protected function setCacheData(): void
    {
        // Caching Disabled
        if (!$this->_canCache || $this->_cacheTTL < 0) {
            return;
        }

        $table_name = \constant($this->myName.'::CLASS_TABLE');
        $primary    = \constant($this->myName.'::CLASS_PRIMARY');
        $fields     = \array_keys(static::$dbInfo);
        $data       = [];
        foreach ($fields as $field) {
            $data[$field] = $this->{$field};
        }
        if ($this->_cacheTTL > 0) {
            $data['ORM_TIME'] = \time() + $this->_cacheTTL;
        }

        ORMLogger::getLogger()->info('ORM: DS: SCD: '.$table_name.': Setting Cache Data', $data);

        $this->_cache->hSet($this->myName, 'key_'.$this->{$primary}, \serialize($data));
    }
}
