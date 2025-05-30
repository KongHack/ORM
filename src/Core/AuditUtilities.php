<?php

namespace GCWorld\ORM\Core;

use GCWorld\ORM\Config;
use GCWorld\ORM\Helpers\CleanAuditData;
use Ramsey\Uuid\Uuid;

/**
 * AuditUtilities Class.
 */
class AuditUtilities
{
    /**
     * @param array<string,mixed> $after
     * @param array<string,mixed> $before
     */
    public static function cleanData(string $table, array $after, array $before): CleanAuditData
    {
        $cConfig = new Config();
        $config  = $cConfig->getConfig()['tables'] ?? [];
        if (array_key_exists($table, $config)) {
            $tableConfig = $config[$table];
            // Check to see if we are auditing this table at all
            if (isset($tableConfig['audit_ignore']) && $tableConfig['audit_ignore']) {
                return new CleanAuditData();
            }

            if (array_key_exists('fields', $tableConfig)) {
                $fields = $tableConfig['fields'];
                foreach ($fields as $field => $fieldConfig) {
                    if (isset($fieldConfig['audit_ignore']) && $fieldConfig['audit_ignore']) {
                        unset($before[$field]);
                        unset($after[$field]);
                    }
                }
            }
        }

        $A = [];
        $B = [];
        foreach ($before as $k => $v) {
            if (isset($after[$k]) && $after[$k] !== $v) {
                $B[$k] = $v;
                $A[$k] = $after[$k];

                if($v instanceof \BackedEnum) {
                    $v     = $v->value;
                    $B[$k] = $v;
                }
                if($after[$k] instanceof \BackedEnum) {
                    $after[$k] = $after[$k]->value;
                    $A[$k]     = $after[$k];
                }

                // Overrides

                if (empty($v)) {
                    $B[$k] = '';
                } elseif (strpos($k, '_uuid') !== false && strlen($v) == 16) {
                    $B[$k] = Uuid::fromBytes($v)->toString();
                } elseif (self::isBinary($v)) {
                    $B[$k] = base64_encode($v);
                }
                if (empty($after[$k])) {
                    $A[$k] = '';
                } elseif (strpos($k, '_uuid') !== false && strlen($after[$k]) == 16) {
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

    public static function isBinary(mixed $str): bool
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
