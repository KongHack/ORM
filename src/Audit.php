<?php
namespace GCWorld\ORM;

class Audit
{
    const DATA_MODEL_VERSION = 1;

    /** @var \GCWorld\Common\Common */
    private $common   = null;
    private $database = 'default';
    private $prefix   = '_Audit_';

    // Loaded via storeLog
    protected $table     = null;
    protected $primaryId = null;
    protected $memberId  = null;
    protected $before    = [];
    protected $after     = [];

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
            $this->enable   = $audit['enable'];
            $this->database = $audit['database'];
            $this->prefix   = $audit['prefix'];
        }
    }

    /**
     * @param       $table
     * @param       $primaryID
     * @param array $before
     * @param array $after
     * @param int   $memberID
     * @return int|string
     * @throws \Exception
     */
    public function storeLog($table, $primaryId, array $before, array $after, $memberId = 0)
    {
        if ($memberId < 1) {
            $memberId = $this->determineMemberId();
        }

        $this->table     = $table;
        $this->primaryId = $primaryId;
        $this->memberId  = $memberId;
        $this->before    = $before;
        $this->after     = $after;


        if (!$this->enable) {
            return 0;
        }

        if (empty($primaryId)) {
            throw new \Exception('AUDIT LOG:: Invalid Primary ID Passed');
        }

        $storeTable = $this->prefix.$table;
        /** @var \GCWorld\Database\Database $db */
        $db = $this->common->getDatabase($this->database);

        $this->handleTable($storeTable);

        //Determine only things changed.
        $A = [];
        $B = [];

        // KISS
        foreach ($before as $k => $v) {
            if ($after[$k] !== $v) {
                $B[$k] = $v;
                $A[$k] = $after[$k];
            }
        }

        if (count($A) > 0) {
            $sql   = 'INSERT INTO '.$storeTable.'
            (primary_id, member_id, log_request_uri, log_before, log_after)
            VALUES
            (:pid, :mid, :uri, :logB, :logA)';
            $query = $db->prepare($sql);
            $query->execute([
                ':pid'  => intval($primaryId),
                ':mid'  => intval($memberId),
                ':uri'  => (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : $this->getTopScript()),
                ':logB' => json_encode($B),
                ':logA' => json_encode($A)
            ]);
            $query->closeCursor();

            return $db->lastInsertId();
        }

        return 0;
    }

    /**
     * @param $tableName
     */
    private function handleTable($tableName)
    {
        $db = $this->common->getDatabase($this->database);

        if (!$db->tableExists($tableName)) {
            $source = file_get_contents($this->getDataModelDirectory().'source.sql');
            $sql    = str_replace('__REPLACE__', $tableName, $source);
            $db->exec($sql);
            $db->setTableComment($tableName, '0');
        }
        $version = intval($db->getTableComment($tableName));
        if ($version < self::DATA_MODEL_VERSION) {
            $versionFiles = glob($this->getDataModelDirectory().'revisions'.DIRECTORY_SEPARATOR.'*.sql');
            sort($versionFiles);
            foreach ($versionFiles as $file) {
                $tmp        = explode(DIRECTORY_SEPARATOR, $file);
                $fileName   = array_pop($tmp);
                $tmp        = explode('.', $fileName);
                $fileNumber = intval($tmp[0]);
                unset($tmp);

                if ($fileNumber > $version) {
                    $model = file_get_contents($file);
                    $sql   = str_replace('__REPLACE__', $tableName, $model);
                    $db->exec($sql);
                    $db->setTableComment($tableName, $fileNumber);
                }
            }
        }
    }

    /**
     * @return string
     */
    private function getDataModelDirectory()
    {
        $base = rtrim(__DIR__, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR;
        $base .= 'datamodel'.DIRECTORY_SEPARATOR.'audit'.DIRECTORY_SEPARATOR;

        return $base;
    }

    /**
     * More Info: http://stackoverflow.com/questions/1318608/php-get-parent-script-name
     * @return mixed
     */
    private function getTopScript()
    {
        $backtrace = debug_backtrace(defined("DEBUG_BACKTRACE_IGNORE_ARGS") ? DEBUG_BACKTRACE_IGNORE_ARGS : false);
        $top_frame = array_pop($backtrace);

        return $top_frame['file'];
    }

    /**
     * @return null|string
     */
    public function getTable()
    {
        return $this->table;
    }

    /**
     * @return null|integer
     */
    public function getPrimaryId()
    {
        return $this->primaryId;
    }

    /**
     * @return null|integer
     */
    public function getMemberId()
    {
        return $this->memberId;
    }

    /**
     * @return array
     */
    public function getBefore()
    {
        return $this->before;
    }

    /**
     * @return array
     */
    public function getAfter()
    {
        return $this->after;
    }


    private function determineMemberId()
    {
        if (!method_exists($this->common, 'getUser')) {
            return 0;
        }
        $user = $this->common->getUser();
        if (!is_object($user)) {
            return 0;
        }

        if (method_exists($user, 'getRealMemberId')) {
            return $user->getRealMemberId();
        }
        if (method_exists($user, 'getMemberId')) {
            return $user->getMemberId();
        }
        if (defined(get_class($user).'::CLASS_PRIMARY')) {
            $user_primary = constant(get_class($user).'::CLASS_PRIMARY');
            if (property_exists($user, $user_primary)) {
                return $user->$user_primary;
            }
            if (method_exists($user, 'get')) {
                try {
                    return $user->get($user_primary);
                } catch(\Exception $e) {
                    // Silently fail.
                }
            }
        }

        return 0;
    }
}
