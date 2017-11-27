<?php
namespace GCWorld\ORM;

use GCWorld\Common\Common;

/**
 * Class Audit
 * @package GCWorld\ORM
 */
class Audit
{


    private static $overrideMemberId = null;

    /** @var \GCWorld\Common\Common */
    private $common   = null;
    private $database = null;
    private $connection = 'default';
    private $prefix     = '_Audit_';
    private $enable   = true;

    protected $table     = null;
    protected $primaryId = null;
    protected $memberId  = null;
    protected $before    = [];
    protected $after     = [];

    /**
     * @param \GCWorld\Interfaces\Common $common
     */
    public function __construct(Common $common)
    {
        /** @var \GCWorld\Common\Common common */
        $this->common = $common;
        /** @var array $audit */
        $audit = $common->getConfig('audit');
        if (is_array($audit)) {
            $this->enable     = $audit['enable'];
            $this->database   = $audit['database'];
            $this->connection = $audit['connection'];
            $this->prefix     = $audit['prefix'];
        }

    }

    /**
     * @param int $memberId
     * @return void
     */
    public static function setOverrideMemberId(int $memberId)
    {
        self::$overrideMemberId = $memberId;
    }

    /**
     * @return void
     */
    public static function clearOverrideMemberId()
    {
        self::$overrideMemberId  = null;
    }

    /**
     * @param string $table
     * @param int    $primaryId
     * @param array  $before
     * @param array  $after
     * @param int    $memberId
     * @return int|string
     * @throws \Exception
     */
    public function storeLog(string $table, int $primaryId, array $before, array $after, int $memberId = 0)
    {
        if ($memberId < 1) {
            $memberId = $this->determineMemberId();
        }

        $cConfig = new Config();
        $config  = $cConfig->getConfig();
        if (array_key_exists($table, $config)) {
            $tableConfig = $config[$table];
            if (array_key_exists('audit_ignore', $tableConfig)) {
                $fields = $tableConfig['audit_ignore'];
                foreach ($fields as $field) {
                    unset($before[$field]);
                    unset($after[$field]);
                }
            }
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
        if($this->database != null) {
            $storeTable = $this->database.'.'.$storeTable;
        }
        /** @var \GCWorld\Database\Database $db */
        $db = $this->common->getDatabase($this->connection);

        AuditMaster::getInstance(1,$this->common)->handleTable($storeTable);

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
     * @return null|int
     */
    public function getPrimaryId()
    {
        return $this->primaryId;
    }

    /**
     * @return null|int
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

    /**
     * @return int
     */
    private function determineMemberId()
    {
        if(self::$overrideMemberId !== null) {
            return intval(self::$overrideMemberId);
        }

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
                } catch (\Exception $e) {
                    // Silently fail.
                }
            }
        }

        return 0;
    }
}
