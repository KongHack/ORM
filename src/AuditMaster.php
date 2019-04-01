<?php
namespace GCWorld\ORM;

use GCWorld\Database\Database;
use GCWorld\Interfaces\Common;

/**
 * Class AuditMaster
 * @package GCWorld\ORM
 */
class AuditMaster
{
    const DATA_MODEL_VERSION = 1;

    protected static $instances = [];

    protected $master   = '_GCAuditMaster';
    protected $versions = [];
    protected $checked  = [];
    protected $common   = null;
    protected $config   = null;
    /** @var \GCWorld\Database\Database */
    protected $_db = null;

    /**
     * @param string $instance
     * @param Common $common
     *
     * @return AuditMaster
     */
    public static function getInstance(string $instance, Common $common)
    {
        if (!array_key_exists($instance, self::$instances)) {
            self::$instances[$instance] = new self($common);
        }

        return self::$instances[$instance];
    }

    /**
     * AuditMaster constructor.
     * @param Common $common
     */
    protected function __construct(Common $common)
    {
        $this->common = $common;
        $this->config = $common->getConfig('audit');
        if (!is_array($this->config)) {
            $this->config = [
                'enable'     => false,
                'database'   => null,
                'connection' => 'default',
                'prefix'     => '_Audit_',
            ];
        }
        $this->_db = $this->common->getDatabase($this->config['connection']);

        // Cache versions
        $tableName = $this->config['prefix'].'_GCAuditMaster';
        if ($this->config['database'] != null) {
            $tableName = $this->config['database'].'.'.$tableName;
        }
        $this->master = $tableName;

        if (!$this->_db->tableExists($tableName)) {
            // Find all tables that currently exist, their versions, and prep for insertion
            $sql   = 'SELECT TABLE_NAME, TABLE_COMMENT, TABLE_SCHEMA
                      FROM information_schema.TABLES
                      WHERE TABLE_SCHEMA = :schema';
            $query = $this->_db->prepare($sql);
            $query->execute([
                ':schema' => ($this->config['database'] != null ? $this->config['database'] : '')
            ]);
            $tables = $query->fetchAll();

            // Ok, time to create our audit master.
            $file = $this->getDataModelDirectory() . 'master.sql';
            $sql = file_get_contents($file);
            $sql = str_replace('__REPLACE__', $tableName, $sql);
            $this->_db->exec($sql);
            sleep(1);
            $sql   = 'INSERT INTO '.$tableName.' 
                      (audit_schema, audit_table, audit_version, audit_datetime_created) 
                      VALUES
                      (:schema, :table, :version, NOW())';
            $query = $this->_db->prepare($sql);
            foreach ($tables as $table) {
                $query->execute([
                    ':schema'  => $table['TABLE_SCHEMA'],
                    ':table'   => $table['TABLE_NAME'],
                    ':version' => intval($table['TABLE_COMMENT']),
                ]);
                $query->closeCursor();
            }
        }

        $sql = 'SELECT audit_schema, audit_table, audit_version FROM '.$tableName;
        $query = $this->_db->query($sql);
        while($row = $query->fetch()) {
            $this->versions[$row['audit_schema'].'.'.$row['audit_table']] = (int) $row['audit_version'];
        }
    }

    /**
     * @return string
     */
    private function getDataModelDirectory()
    {
        $base  = rtrim(__DIR__, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR;
        $base .= 'datamodel'.DIRECTORY_SEPARATOR.'audit'.DIRECTORY_SEPARATOR;

        return $base;
    }
}
