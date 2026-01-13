<?php
namespace GCWorld\ORM\Interfaces;

use GCWorld\Interfaces\CommonInterface;

/**
 * AuditInterface Interface.
 */
interface AuditInterface
{
    /**
     * @param CommonInterface $cCommon
     */
    public function __construct(CommonInterface $cCommon);

    /**
     * @param string $table
     * @param mixed  $primaryId
     * @param array  $before
     * @param array  $after
     * @param mixed  $memberId
     *
     * @return mixed
     */
    public function storeLog(string $table, mixed $primaryId, array $before, array $after, mixed $memberId = null): mixed;

    /**
     * @param string|null $member_uuid
     *
     * @return void
     */
    public static function setOverrideMemberUuid(?string $member_uuid = null): void;
}
