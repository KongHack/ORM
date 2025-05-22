<?php
namespace GCWorld\ORM\Core;

use Exception;
use GCWorld\Interfaces\CommonInterface;
use GCWorld\Interfaces\Database\DatabaseInterface;
use GCWorld\ORM\Config;
use PDO;
use PDOException;

/**
 * Class Builder.
 */
class Builder
{
    public const BUILDER_VERSION = 3;

    protected readonly CommonInterface $common;

    /**
     * @var DatabaseInterface|\GCWorld\Database\Database
     */
    protected readonly DatabaseInterface $_db;

    /**
     * @var DatabaseInterface|\GCWorld\Database\Database|null
     */
    protected readonly ?DatabaseInterface $_audit;

    protected readonly array $coreConfig;
    protected readonly array $auditConfig;
    protected readonly bool $doAudit;
    protected readonly ?string $auditDB;

    /**
     * Builder constructor.
     */
    public function __construct(CommonInterface $common)
    {
        $cConfig        = new Config();
        $this->coreConfig = $cConfig->getConfig(); // readonly
        $this->common     = $common; // readonly
        $this->_db        = $common->getDatabase(); // readonly

        $auditConfigDefault = [];
        $auditDefault       = null;
        $auditDBDefault     = null;
        $doAuditDefault     = false;

        if (!isset($this->coreConfig['general']['audit']) || $this->coreConfig['general']['audit']) {
            $auditConfigDefault = $common->getConfig('audit');
            if (isset($auditConfigDefault['enable']) && $auditConfigDefault['enable']) {
                try {
                    $auditDefault   = $common->getDatabase($auditConfigDefault['connection'] ?? '');
                    $auditDBDefault = $auditConfigDefault['database'] ?? null;
                    $doAuditDefault = true;
                } catch (Exception) {
                    // Reset to defaults if an exception occurs
                    $auditDefault   = null;
                    $auditDBDefault = null;
                    $doAuditDefault = false;
                }
            }
        }
        $this->auditConfig = $auditConfigDefault; // readonly
        $this->_audit      = $auditDefault;       // readonly
        $this->auditDB     = $auditDBDefault;     // readonly
        $this->doAudit     = $doAuditDefault;     // readonly
    }

    /**
     * @param string|null $schema
     *
     * @throws Exception
     * @throws PDOException
     *
     * @return void
     */
    public function run(?string $schema = null): void
    {
        if (!$this->doAudit) {
            return;
        }

        if (!$this->coreConfig['general']['audit']) {
            return;
        }

        if (!$this->auditDB) {
            return;
        }

        $master = $this->auditConfig['prefix'].'_GCAuditMaster';
        if (null != $this->auditDB) {
            $master = $this->auditDB.'.'.$master;
        }

        if (!$this->_audit->tableExists($master)) {
            // This will create the audit master
            $file    = self::getDataModelDirectory().'master.sql';
            $content = \file_get_contents($file);
            $content = \str_replace('__REPLACE__', $master, $content);
            $this->_audit->exec($content);
        } else {
            $sql   = 'SHOW COLUMNS FROM '.$master;
            $query = $this->_audit->prepare($sql);
            $query->execute();
            $rows    = $query->fetchAll();
            $col     = 'audit_pk_set';
            $colGood = false;
            foreach ($rows as $row) {
                if ($row['Field'] == $col) {
                    $colGood = true;

                    break;
                }
            }
            if (!$colGood) {
                $sql = "ALTER TABLE {$master} ADD `audit_pk_set` TINYINT(1) NOT NULL DEFAULT '0' AFTER `audit_table`";
                $this->_audit->exec($sql);
            }
        }

        $cConfig   = new Config();
        $ormConfig = $cConfig->getConfig()['tables'] ?? [];

        $existing = [];
        $sql      = "SELECT * FROM {$master}";
        $query    = $this->_audit->prepare($sql);
        $query->execute();
        while ($row = $query->fetch()) {
            $existing[$row['audit_schema']][$row['audit_table']] = $row;
        }

        if (null == $schema) {
            $schema = $this->auditDB ?? $this->_audit->getWorkingDatabaseName();
        }

        $sql   = 'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = :schema AND TABLE_TYPE = :type';
        $query = $this->_db->prepare($sql);
        $query->execute([
            ':schema' => $schema,
            ':type'   => 'BASE TABLE',
        ]);
        $tables = $query->fetchAll(PDO::FETCH_NUM);
        $preLen = \strlen($this->auditConfig['prefix']);

        foreach ($tables as $tRow) {
            $table = $tRow[0];

            // Prevent recursion
            if (\substr($table, 0, $preLen) == $this->auditConfig['prefix']) {
                continue;
            }

            if (\array_key_exists($table, $ormConfig)) {
                $tableConfig = $ormConfig[$table];
                if (isset($tableConfig['audit_ignore']) && $tableConfig['audit_ignore']) {
                    continue;
                }
            }

            $audit = $auditBase = $this->auditConfig['prefix'].$table;
            if (null != $this->auditDB) {
                $audit = $this->auditDB.'.'.$audit;
            }

            if (!isset($existing[$schema][$auditBase])) {
                if ($this->_audit->tableExists($audit)) {
                    $existing[$schema][$auditBase]['audit_version'] = $this->_audit->getTableComment($audit);
                    $existing[$schema][$auditBase]['audit_pk_set']  = 0;
                } else {
                    $source = \file_get_contents(self::getDataModelDirectory().'source.sql');
                    $sql    = \str_replace('__REPLACE__', $audit, $source);
                    $this->_audit->exec($sql);
                    $this->_audit->setTableComment($audit, '0');
                    $existing[$schema][$auditBase]['audit_version'] = 0;
                    $existing[$schema][$auditBase]['audit_pk_set']  = 0;
                }
            }

            $version = $existing[$schema][$auditBase]['audit_version'];
            if ($version < self::BUILDER_VERSION) {
                $versionFiles = \glob(self::getDataModelDirectory().'revisions'.DIRECTORY_SEPARATOR.'*.sql');
                \sort($versionFiles);
                foreach ($versionFiles as $file) {
                    $tmp        = \explode(DIRECTORY_SEPARATOR, $file);
                    $fileName   = \array_pop($tmp);
                    $tmp        = \explode('.', $fileName);
                    $fileNumber = \intval($tmp[0]);
                    unset($tmp);

                    if ($fileNumber > $version && $fileNumber < self::BUILDER_VERSION) {
                        $model = \file_get_contents($file);
                        $sql   = \str_replace('__REPLACE__', $audit, $model);

                        try {
                            $this->_audit->exec($sql);
                            $this->_audit->setTableComment($audit, $fileNumber);
                        } catch (PDOException $e) {
                            if (false !== \strpos($e->getMessage(), 'Column already exists')) {
                                continue;
                            }

                            echo 'BAD SQL: ',PHP_EOL,PHP_EOL,$sql,PHP_EOL,PHP_EOL;

                            throw $e;
                        }
                    }
                }
            }

            // Ok, that updates the version.
            // Now make sure the primary key is set.
            if (!$existing[$schema][$auditBase]['audit_pk_set']) {
                // Determine our primary field type
                $keys  = 0;
                $sql   = 'SHOW COLUMNS FROM '.$table;
                $query = $this->_db->prepare($sql);
                $query->execute();
                $data = $query->fetchAll();
                $type = null;
                foreach ($data as $datum) {
                    if ('PRI' == $datum['Key']) {
                        $type = $datum['Type'];
                        ++$keys;
                    }
                }
                if (null != $type && 1 == $keys) {
                    // DISABLED FOR NOW
                    /*
                    try {
                        $int   = stripos($type,'int')!==false;
                        $sql   = 'ALTER TABLE '.$audit.' CHANGE primary_id primary_id '.$type.' DEFAULT '.
                            ($int ? '\'0\'' : '\'\'');
                        $query = $this->_audit->prepare($sql);
                        $query->execute();
                        $query->closeCursor();
                    } catch (\PDOException $e) {
                        echo 'BAD SQL',PHP_EOL,PHP_EOL,$sql,PHP_EOL,PHP_EOL;
                        throw $e;
                    }
                    */
                }
            }

            $sql   = "INSERT INTO {$master} 
                      (audit_schema, audit_table, audit_pk_set, audit_version, audit_datetime_created)
                      VALUES
                      (:audit_schema, :audit_table, 1, :audit_version, NOW())
                      ON DUPLICATE KEY UPDATE 
                        audit_pk_set = VALUES (audit_pk_set),
                        audit_version = VALUES (audit_version),
                        audit_datetime_updated = NOW()";
            $query = $this->_audit->prepare($sql);
            $query->execute([
                ':audit_schema'  => $this->auditDB ?? $schema,
                ':audit_table'   => $auditBase,
                ':audit_version' => self::BUILDER_VERSION,
            ]);
            $query->closeCursor();

            if (self::BUILDER_VERSION != $version) {
                $this->_audit->setTableComment($audit, self::BUILDER_VERSION);
            }
        }
    }

    public static function getDataModelDirectory(): string
    {
        $base  = \rtrim(__DIR__, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR;
        $base .= 'datamodel'.DIRECTORY_SEPARATOR.'audit'.DIRECTORY_SEPARATOR;

        return $base;
    }
}
