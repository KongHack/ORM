<?php
namespace GCWorld\ORM;

use Exception;
use GCWorld\Database\Database;
use GCWorld\Interfaces\CommonInterface;
use GCWorld\ORM\Core\AuditUtilities;
use GCWorld\ORM\Core\CreateAuditTable;
use GCWorld\ORM\Interfaces\AuditInterface;
use PDOException;

/**
 * Class Audit.
 */
class Audit implements AuditInterface
{
    protected static mixed $overrideMemberId = null;
    protected static ?array $config = null;

    protected bool $canAudit            = true;
    protected ?CommonInterface $cCommon = null;
    protected ?string         $database = null;
    protected string $connection        = 'default';
    protected string $prefix            = '_Audit_';
    protected bool $enable              = true;

    protected ?string $table            = null;
    protected ?string $primaryId        = null;
    protected int|string|null $memberId = null;
    protected array   $before           = [];
    protected array   $after            = [];

    /**
     * @param CommonInterface $cCommon
     */
    public function __construct(CommonInterface $cCommon)
    {
        if (null === self::$config) {
            $cConfig      = new Config();
            $config       = $cConfig->getConfig();
            self::$config = $config;
        }

        if (isset(self::$config['general']['audit']) && !self::$config['general']['audit']) {
            $this->canAudit = false;
        }

        if ($this->canAudit) {
            $this->cCommon = $cCommon;
            $audit         = $cCommon->getConfig('audit');
            if (\is_array($audit)) {
                $this->enable     = $audit['enable'] ?? false;
                $this->database   = $audit['database'] ?? $this->database;
                $this->connection = $audit['connection'] ?? $this->connection;
                $this->prefix     = $audit['prefix'] ?? $this->prefix;
            }
        }
    }

    /**
     * @param mixed $memberId
     *
     * @return void
     */
    public static function setOverrideMemberId($memberId): void
    {
        self::$overrideMemberId = $memberId;
    }

    /**
     * @return void
     */
    public static function clearOverrideMemberId(): void
    {
        self::$overrideMemberId = null;
    }

    /**
     * @param string $table
     * @param mixed  $primaryId
     * @param array  $before
     * @param array  $after
     * @param mixed  $memberId
     *
     * @throws Exception
     *
     * @return int|string
     */
    public function storeLog(
        string $table,
        mixed $primaryId,
        array $before,
        array $after,
        mixed $memberId = null
    ): int|string {
        if (!$this->canAudit) {
            return 0;
        }

        if (null === $memberId) {
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
            throw new Exception('AUDIT LOG:: Invalid Primary ID Passed');
        }

        $storeTable = $this->prefix.$table;
        if (null != $this->database) {
            $storeTable = $this->database.'.'.$storeTable;
        }
        /** @var Database $db */
        $db = $this->cCommon->getDatabase($this->connection);

        // Determine only things changed.
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
                ':logB' => \json_encode($B),
                ':logA' => \json_encode($A),
            ]);
            $query->closeCursor();
        } catch (PDOException $e) {
            if (false === \stristr($e->getMessage(), 'Base table or view not found')) {
                throw $e;
            }

            $cCreate = new CreateAuditTable($this->cCommon->getDatabase(), $db);
            $cCreate->buildTable($table);
            $query = $db->prepare($sql);
            $query->execute([
                ':pid'  => $primaryId,
                ':mid'  => $memberId,
                ':uri'  => $request,
                ':logB' => \json_encode($B),
                ':logA' => \json_encode($A),
            ]);
            $query->closeCursor();
        }

        return $db->lastInsertId();
    }

    /**
     * @return string|null
     */
    public function getTable(): ?string
    {
        return $this->table;
    }

    /**
     * @return int|string|null
     */
    public function getPrimaryId(): int|string|null
    {
        return $this->primaryId;
    }

    /**
     * @return int|string|null
     */
    public function getMemberId(): int|string|null
    {
        return $this->memberId;
    }

    /**
     * @return array
     */
    public function getBefore(): array
    {
        return $this->before;
    }

    /**
     * @return array
     */
    public function getAfter(): array
    {
        return $this->after;
    }

    /**
     * @param string|null $member_uuid
     *
     * @return void
     */
    public static function setOverrideMemberUuid(?string $member_uuid = null): void
    {
        self::$overrideMemberId = $member_uuid;
    }

    /**
     * More Info: http://stackoverflow.com/questions/1318608/php-get-parent-script-name.
     *
     * @return mixed
     */
    protected function getTopScript(): mixed
    {
        $backtrace = \debug_backtrace(\defined('DEBUG_BACKTRACE_IGNORE_ARGS') ? DEBUG_BACKTRACE_IGNORE_ARGS : false);
        $top_frame = \array_pop($backtrace);

        return $top_frame['file'] ?? '';
    }

    /**
     * @return int|string
     */
    protected function determineMemberId(): int|string
    {
        if (null !== self::$overrideMemberId) {
            if (\is_int(self::$overrideMemberId) || \is_string(self::$overrideMemberId)) {
                return self::$overrideMemberId;
            }

            return 0;
        }

        if (!\method_exists($this->cCommon, 'getUser')) {
            return 0;
        }
        $user = $this->cCommon->getUser();
        if (!\is_object($user)) {
            return 0;
        }

        if (\method_exists($user, 'getRealMemberUuid')) {
            return $user->getRealMemberUuid();
        }

        if (\method_exists($user, 'getRealMemberId')) {
            return $user->getRealMemberId();
        }

        if (\method_exists($user, 'getMemberId')) {
            return $user->getMemberId();
        }

        if (\defined(\get_class($user).'::CLASS_PRIMARY')) {
            $user_primary = \constant(\get_class($user).'::CLASS_PRIMARY');
            if (\property_exists($user, $user_primary)) {
                $memberId = $user->{$user_primary};
                if (\is_int($memberId) || \is_string($memberId)) {
                    return $memberId;
                }

                return 0;
            }
            if (\method_exists($user, 'get')) {
                try {
                    $memberId = $user->get($user_primary);
                    if (\is_int($memberId) || \is_string($memberId)) {
                        return $memberId;
                    }
                } catch (Exception $e) {
                    // Silently fail.
                }
            }
        }

        return 0;
    }
}
