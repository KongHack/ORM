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
     * @return mixed
     */
    public function storeLog(string $table, $primaryId, array $before, array $after, $memberId = null);
}
