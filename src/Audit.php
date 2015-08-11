<?php
namespace GCWorld\ORM;

class Audit
{
    /** @var \GCWorld\Common\Common */
    private $common   = null;
    private $database = 'default';
    private $prefix   = '_Audit_';

    /**
     * @param \GCWorld\Interfaces\Common $common
     */
    public function __construct($common)
    {
        /** @var \GCWorld\Common\Common common */
        $this->common = $common;
        /** @var array $audit */
        $audit = $common->getConfig('audit');
        if (is_array($audit)) {
            $this->enable = $audit['enable'];
            $this->database = $audit['database'];
            $this->prefix = $audit['prefix'];
        }
    }

    /**
     * @param $tableName
     */
    private function createTable($tableName)
    {
        $sql = '
        CREATE TABLE IF NOT EXISTS `'.$tableName.'` (
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
        $this->common->getDatabase($this->database)->exec($sql);
    }

    /**
     * @param string $table
     * @param int $primaryID
     * @param int $memberID
     * @param array $before
     * @param array $after
     * @return bool
     */
    public function storeLog($table, $primaryID, $memberID, $before, $after)
    {
        if (!$this->enable) {
            return false;
        }

        $storeTable = $this->prefix.$table;
        $db = $this->common->getDatabase($this->database);
        if (!$db->tableExists($storeTable)) {
            $this->createTable($storeTable);
        }

        //Determine only things changed.
        $A = array();
        $B = array();

        // KISS
        foreach ($before as $k => $v) {
            if ($after[$k] !== $v) {
                $B[$k] = $v;
                $A[$k] = $after[$k];
            }
        }


        if (count($A) > 0) {
            $sql = 'INSERT INTO '.$storeTable.'
            (primary_id, member_id, log_before, log_after)
            VALUES
            (:pid, :mid, :logB, :logA)';
            $query = $db->prepare($sql);
            $query->execute(array(
                ':pid'  => $primaryID,
                ':mid'  => $memberID,
                ':logB' => json_encode($B),
                ':logA' => json_encode($A)
            ));
            $query->closeCursor();
        }

        return true;
    }
}
