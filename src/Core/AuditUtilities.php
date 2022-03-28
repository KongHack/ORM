<?php

namespace GCWorld\ORM\Core;

use GCWorld\ORM\Helpers\CleanAuditData;
use Ramsey\Uuid\Uuid;

/**
 * AuditUtilities Class.
 */
class AuditUtilities
{
    /**
     * @param array $after
     * @param array $before
     * @return CleanAuditData
     */
    public static function cleanData(array $after, array $before)
    {
        $A = [];
        $B = [];
        foreach ($before as $k => $v) {
            if ($after[$k] !== $v) {
                $B[$k] = $v;
                $A[$k] = $after[$k];

                // Overrides

                if (strpos($k, '_uuid') !== false && strlen($v) == 16) {
                    $B[$k] = Uuid::fromBytes($v)->toString();
                } elseif (self::isBinary($v)) {
                    $B[$k] = base64_encode($v);
                }
                if (strpos($k, '_uuid') !== false && strlen($after[$k]) == 16) {
                    $A[$k] = Uuid::fromBytes($after[$k])->toString();
                } elseif (self::isBinary($after[$k])) {
                    $A[$k] = base64_encode($after[$k]);
                }
            }
        }

        $cData = new CleanAuditData();
        $cData->setAfter($A);
        $cData->setBefore($B);

        return $cData;
    }

    /**
     * @param mixed $str
     * @return bool
     */
    public static function isBinary($str)
    {
        if (!is_scalar($str)) {
            return true;
        }

        if (mb_detect_encoding($str)) {
            return false;
        }

        return preg_match('~[^\x20-\x7E\t\r\n]~', $str) > 0;
    }
}
