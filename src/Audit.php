<?php
namespace GCWorld\ORM;

class Audit
{
    private $enabled  = false;
    private $common   = null;
    private $database = null;
    private $prefix   = '_Audit_';



    public function __construct(array $auditConfig)
    {
        if(isset($auditConfig['enabled']) && $auditConfig['enabled']) {
            $this->enabled = true;

            if(!$auditConfig['common'] instanceof \GCWorld\Interfaces\Common){
                throw new \Exception('Invalid Common Passed');
            }
            $this->common = $auditConfig['common'];
            $this->database = (isset($auditConfig['database'])?$auditConfig['database']:'default');
            if(isset($auditConfig['prefix']) && $auditConfig['prefix'] != '') {
                $this->prefix = $auditConfig['prefix'];
            }
        }
    }

    /**
     * @param $tableName
     */
    private function createTable($tableName)
    {
        $sql = '
        CREATE TABLE IF NOT EXISTS `'.$this->prefix.$tableName.'` (
          `log_id` int(11) NOT NULL AUTO_INCREMENT,
          `primary_id` int(11) NOT NULL,
          `member_id` int(11) NOT NULL DEFAULT \'0\',
          `log_timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `log_before` longtext COLLATE utf8_bin NOT NULL,
          `log_after` longtext COLLATE utf8_bin NOT NULL,
          PRIMARY KEY (`log_id`),
          KEY `primary_id` (`primary_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin;
        ';
    }



}