<?php

namespace GCWorld\ORM\Traits;

/**
 * Trait ORMFieldsTrait
 */
trait ORMFieldsTrait
{
    public static function getORMFieldTitle(string $fieldName): ?string
    {
        if (!isset(self::$ORM_FIELDS[$fieldName])) {
            return null;
        }

        return self::$ORM_FIELDS[$fieldName]['title'];
    }

    public static function getORMFieldDesc(string $fieldName): ?string
    {
        if (!isset(self::$ORM_FIELDS[$fieldName])) {
            return null;
        }

        return self::$ORM_FIELDS[$fieldName]['desc'];
    }

    public static function getORMFieldHelp(string $fieldName): ?string
    {
        if (!isset(self::$ORM_FIELDS[$fieldName])) {
            return null;
        }

        return self::$ORM_FIELDS[$fieldName]['help'];
    }

    public static function getORMFieldMaxLength(string $fieldName): int
    {
        if (!isset(self::$ORM_FIELDS[$fieldName])) {
            return 0;
        }

        return (int) self::$ORM_FIELDS[$fieldName]['maxlen'];
    }
}
