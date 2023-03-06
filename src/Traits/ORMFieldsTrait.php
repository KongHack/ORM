<?php

namespace GCWorld\ORM\Traits;

/**
 * Trait ORMFieldsTrait
 */
trait ORMFieldsTrait
{
    /**
     * @param string $fieldName
     * @return string|null
     */
    public static function getFieldTitle(string $fieldName): ?string
    {
        if (!isset(self::$ORM_FIELDS[$fieldName])) {
            return null;
        }

        return self::$ORM_FIELDS[$fieldName]['title'];
    }

    /**
     * @param string $fieldName
     * @return string|null
     */
    public static function getFieldDesc(string $fieldName): ?string
    {
        if (!isset(self::$ORM_FIELDS[$fieldName])) {
            return null;
        }

        return self::$ORM_FIELDS[$fieldName]['desc'];
    }

    /**
     * @param string $fieldName
     * @return string|null
     */
    public static function getFieldHelp(string $fieldName): ?string
    {
        if (!isset(self::$ORM_FIELDS[$fieldName])) {
            return null;
        }

        return self::$ORM_FIELDS[$fieldName]['help'];
    }

    /**
     * @param string $fieldName
     * @return int
     */
    public static function getFieldMaxLength(string $fieldName): int
    {
        if (!isset(self::$ORM_FIELDS[$fieldName])) {
            return 0;
        }

        return (int) self::$ORM_FIELDS[$fieldName]['maxlen'];
    }
}
