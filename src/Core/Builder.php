<?php
namespace GCWorld\ORM\Core;

use GCWorld\Database\Database;
use GCWorld\Interfaces\Common;
use GCWorld\ORM\AuditMaster;

class Builder
{
    const BUILDER_VERSION = 3;

    protected $common = null;

    /**
     * @var Database
     */
    protected $_db    = null;

    /**
     * @var Database
     */
    protected $_audit = null;

    /**
     * @var array
     */
    protected $config = [];

    protected $database = null;

    public function __construct(Common $common)
    {
        $this->config   = $common->getConfig('audit');
        $this->common   = $common;
        $this->_db      = $common->getDatabase();
        $this->_audit   = $common->getDatabase($this->config['connection']);
        $this->database = $this->config['database'];
    }

    public function run()
    {
        $master = $this->config['prefix'].'_GCAuditMaster';
        if($this->database != null) {
            $master = $this->database.'.'.$master;
        }

        if(!$this->_audit->tableExists($master)) {
            // This will create the audit master
            AuditMaster::getInstance(1,$this->common);
        }

        $existing = [];
        $sql      = "SELECT * FROM $master";
        $query    = $this->_audit->prepare($sql);
        $query->execute();
        while($row = $query->fetch()) {
            $existing[$row['audit_schema']][$row['audit_table']] = $row;
        }

        $schema = $this->database??$this->_audit->getWorkingDatabaseName();

        $sql   = 'SHOW TABLES';
        $query = $this->_db->prepare($sql);
        $query->execute();
        $tables = $query->fetchAll(\PDO::FETCH_NUM);

        foreach($tables as $tRow) {
            $table = $tRow[0];
            $audit = $auditBase = $this->config['prefix'].$table;
            if($this->database != null) {
                $audit = $this->database.'.'.$audit;
            }

            if(isset($existing[$schema][$table])) {
                $version = $existing[$schema][$table]['audit_version'];
                if($version < self::BUILDER_VERSION) {
                    $versionFiles = glob($this->getDataModelDirectory().'revisions'.DIRECTORY_SEPARATOR.'*.sql');
                    sort($versionFiles);
                    foreach ($versionFiles as $file) {
                        $tmp        = explode(DIRECTORY_SEPARATOR, $file);
                        $fileName   = array_pop($tmp);
                        $tmp        = explode('.', $fileName);
                        $fileNumber = intval($tmp[0]);
                        unset($tmp);

                        if ($fileNumber > $version && $fileNumber < self::BUILDER_VERSION) {
                            $model = file_get_contents($file);
                            $sql   = str_replace('__REPLACE__', $audit, $model);
                            $this->_audit->exec($sql);
                            $this->_audit->setTableComment($audit, $fileNumber);
                        }
                    }
                }

                // Ok, that updates the version.
                // Now make sure the primary key is set.
                if(!$existing[$schema][$table]['audit_pk_set']) {
                    // Determine our primary field type
                    $sql   = 'SHOW COLUMNS FROM '.$table;
                    $query = $this->_db->prepare($sql);
                    $query->execute();
                    $data = $query->fetchAll();
                    $type = null;
                    foreach($data as $datum) {
                        if($datum['Key'] == 'PRI') {
                            $type = $datum['Type'];
                        }
                    }
                    if($type != null) {
                        $int = stripos($type,'int')!==false;


                        $sql   = 'ALTER TABLE '.$audit.' CHANGE primary_id primary_id '.$type.' DEFAULT '.($int?'\'0\'':'\'\'');
                        $query = $this->_audit->prepare($sql);
                        $query->execute();
                        $query->closeCursor();
                    }
                }
            } else {
                AuditMaster::getInstance(1,$this->common)->handleTable($audit, $table);
            }

            $sql   = "INSERT INTO $master 
                      (audit_schema, audit_table, audit_pk_set, audit_version, audit_datetime_created)
                      VALUES
                      (:audit_schema, :audit_table, 1, :audit_version, NOW())
                      ON DUPLICATE KEY UPDATE 
                        audit_pk_set = VALUES (audit_pk_set),
                        audit_version = VALUES (audit_version),
                        audit_datetime_updated = NOW()";
            $query = $this->_audit->prepare($sql);
            $query->execute([
                ':audit_schema'  => $this->database??$schema,
                ':audit_table'   => $auditBase,
                ':audit_version' => self::BUILDER_VERSION,
            ]);
            $query->closeCursor();
        }
    }

    /**
     * @return string
     */
    private function getDataModelDirectory()
    {
        $base  = rtrim(__DIR__, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR;
        $base .= 'datamodel'.DIRECTORY_SEPARATOR.'audit'.DIRECTORY_SEPARATOR;

        return $base;
    }
}
