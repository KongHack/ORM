<?php

namespace GCWorld\ORM;

use GCWorld\Interfaces\CommonInterface;
use GCWorld\Database\Database;
use GCWorld\ORM\Core\AuditUtilities;
use GCWorld\ORM\Core\CreateAuditTable;
use Ramsey\Uuid\Uuid;

/**
 * Class Audit
 * @package GCWorld\ORM
 */
class Audit
{
    protected static $overrideMemberId = null;
    protected static $config = null;

    protected $canAudit   = true;
    protected $cCommon     = null;
    protected $database   = null;
    protected $connection = 'default';
    protected $prefix     = '_Audit_';
    protected $enable     = true;

    protected $table     = null;
    protected $primaryId = null;
    protected $memberId  = null;
    protected $before    = [];
    protected $after     = [];

    /**
     * @param CommonInterface $cCommon
     */
    public function __construct(CommonInterface $cCommon)
    {
        if (self::$config === null) {
            $cConfig      = new Config();
            $config       = $cConfig->getConfig();
            self::$config = $config;
        }

        if (isset(self::$config['general']['audit']) && !self::$config['general']['audit']) {
            $this->canAudit = false;
        }

        if ($this->canAudit) {
            $this->cCommon = $cCommon;
            /** @var array $audit */
            $audit = $cCommon->getConfig('audit');
            if (is_array($audit)) {
                $this->enable     = $audit['enable'] ?? false;
                $this->database   = $audit['database'] ?? $this->database;
                $this->connection = $audit['connection'] ?? $this->connection;
                $this->prefix     = $audit['prefix'] ?? $this->prefix;
            }
        }
    }

    /**
     * @param mixed $memberId
     * @return void
     */
    public static function setOverrideMemberId($memberId)
    {
        self::$overrideMemberId = $memberId;
    }

    /**
     * @return void
     */
    public static function clearOverrideMemberId()
    {
        self::$overrideMemberId = null;
    }

    /**
     * @param string $table
     * @param mixed  $primaryId
     * @param array  $before
     * @param array  $after
     * @param mixed  $memberId
     * @return int|string
     * @throws \Exception
     */
    public function storeLog(string $table, $primaryId, array $before, array $after, $memberId = null)
    {
        if (!$this->canAudit) {
            return 0;
        }

        if ($memberId === null) {
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
        if ($this->database != null) {
            $storeTable = $this->database.'.'.$storeTable;
        }
        /** @var Database $db */
        $db = $this->cCommon->getDatabase($this->connection);

        //Determine only things changed.
        $cData = AuditUtilities::cleanData($table, $after, $before);
        $A = $cData->getAfter();
        $B = $cData->getBefore();

        if (empty($A) || empty($B)) {
            return 0;
        }

        $cGlobals = new Globals();
        $request  = $cGlobals->string()->SERVER('REQUEST_URI') ?? $this->getTopScript();

        $sql = 'INSERT INTO '.$storeTable.'
                (primary_id, member_id, log_request_uri, log_before, log_after)
                VALUES
                (:pid, :mid, :uri, :logB, :logA)';
        try {
            $query = $db->prepare($sql);
            $query->execute([
                ':pid'  => $primaryId,
                ':mid'  => $memberId,
                ':uri'  => $request,
                ':logB' => json_encode($B),
                ':logA' => json_encode($A)
            ]);
            $query->closeCursor();
        } catch (\PDOException $e) {
            if (stristr($e->getMessage(), 'Base table or view not found') === false) {
                throw $e;
            }

            $cCreate = new CreateAuditTable($this->cCommon->getDatabase(), $db);
            $cCreate->buildTable($table);
            $query = $db->prepare($sql);
            $query->execute([
                ':pid'  => $primaryId,
                ':mid'  => $memberId,
                ':uri'  => $request,
                ':logB' => json_encode($B),
                ':logA' => json_encode($A)
            ]);
            $query->closeCursor();
        }

        return $db->lastInsertId();
    }

    /**
     * More Info: http://stackoverflow.com/questions/1318608/php-get-parent-script-name
     * @return mixed
     */
    protected function getTopScript()
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
    protected function determineMemberId()
    {
        if (self::$overrideMemberId !== null) {
            return intval(self::$overrideMemberId);
        }

        if (!method_exists($this->cCommon, 'getUser')) {
            return 0;
        }
        $user = $this->cCommon->getUser();
        if (!is_object($user)) {
            return 0;
        }

        if (method_exists($user, 'getRealMemberUuid')) {
            return $user->getRealMemberUuid();
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
