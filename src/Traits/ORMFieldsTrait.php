<?php

namespace GCWorld\ORM\Traits;

/**
 * Trait ORMFieldsTrait
 */
trait ORMFieldsTrait
{
    /**
     * @return string
     */
    public static function getFieldName(string $fieldName): string
    {
        if (!isset(self::$ORM_FIELDS[$fieldName])) {
            return 'UNDEFINED: '.$fieldName;
        }

        return self::$ORM_FIELDS[$fieldName]['title'];
    }

    /**
     * @return string
     */
    public static function getFieldDesc(string $fieldName): string
    {
        if (!isset(self::$ORM_FIELDS[$fieldName])) {
            return 'UNDEFINED: '.$fieldName;
        }

        return self::$ORM_FIELDS[$fieldName]['desc'];
    }

    /**
     * @return string
     */
    public static function getFieldHelp(string $fieldName): string
    {
        if (!isset(self::$ORM_FIELDS[$fieldName])) {
            return 'UNDEFINED: '.$fieldName;
        }

        return self::$ORM_FIELDS[$fieldName]['help'];
    }
}
