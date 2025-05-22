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
    protected static mixed $overrideMemberId = null;
    protected static ?array $config = null;

    protected readonly bool $canAudit;
    protected readonly ?CommonInterface $cCommon;
    protected readonly ?string $database;
    protected readonly string $connection;
    protected readonly string $prefix;
    protected readonly bool $enable;

    protected ?string $table = null;
    protected mixed $primaryId = null;
    protected mixed $memberId = null;
    protected array $before = [];
    protected array $after = [];

    public function __construct(CommonInterface $cCommon)
    {
        if (self::$config === null) {
            $globalConfigObj = new Config();
            self::$config    = $globalConfigObj->getConfig();
        }

        $canAudit   = true;
        if (isset(self::$config['general']['audit']) && !self::$config['general']['audit']) {
            $canAudit = false;
        }
        $this->canAudit = $canAudit;

        $commonInstance = null;
        $dbName         = null;
        $connName       = 'default';
        $prefixName     = '_Audit_';
        $enableFlag     = true;

        if ($this->canAudit) {
            $commonInstance = $cCommon;
            /** @var array|null $auditConfig */
            $auditConfig = $cCommon->getConfig('audit');
            if (is_array($auditConfig)) {
                $enableFlag = $auditConfig['enable'] ?? false;
                $dbName     = $auditConfig['database'] ?? null; // Use null if not set, don't fall back to previous $this->database
                $connName   = $auditConfig['connection'] ?? 'default';
                $prefixName = $auditConfig['prefix'] ?? '_Audit_';
            } else {
                // If $auditConfig is not an array, maintain default $enable = true but other specific settings might be null/default
                // This branch ensures that if getConfig('audit') doesn't return an array, we still initialize readonly props.
                // Based on original logic, if $audit is not an array, $this->enable would remain true (its default).
                // $this->database would remain null. $this->connection default. $this->prefix default.
                // So, the defaults assigned above are mostly correct.
            }
        }
        
        $this->cCommon    = $commonInstance;
        $this->database   = $dbName;
        $this->connection = $connName;
        $this->prefix     = $prefixName;
        $this->enable     = $enableFlag;
    }

    public static function setOverrideMemberId(mixed $memberId): void
    {
        self::$overrideMemberId = $memberId;
    }

    public static function clearOverrideMemberId(): void
    {
        self::$overrideMemberId = null;
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @return int|string
     * @throws \Exception
     */
    public function storeLog(string $table, mixed $primaryId, array $before, array $after, mixed $memberId = null): int|string
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
     */
    protected function getTopScript(): mixed
    {
        $backtrace = debug_backtrace(defined("DEBUG_BACKTRACE_IGNORE_ARGS") ? DEBUG_BACKTRACE_IGNORE_ARGS : false);
        $top_frame = array_pop($backtrace);

        return $top_frame['file'];
    }

    public function getTable(): ?string
    {
        return $this->table;
    }

    public function getPrimaryId(): mixed
    {
        return $this->primaryId;
    }

    public function getMemberId(): mixed
    {
        return $this->memberId;
    }

    public function getBefore(): array
    {
        return $this->before;
    }

    public function getAfter(): array
    {
        return $this->after;
    }

    protected function determineMemberId(): mixed
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
