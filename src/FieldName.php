<?php
namespace GCWorld\ORM;

/**
 * Class FieldName
 * @package GCWorld\ORM
 */
class FieldName
{
    /**
     * @param string $field
     * @return string
     */
    public static function getterName(string $field)
    {
        return 'get'.self::nameConversion($field);
    }

    /**
     * @param string $field
     * @return string
     */
    public static function setterName(string $field)
    {
        return 'set'.self::nameConversion($field);
    }

    /**
     * @param string $name
     * @return mixed
     */
    public static function nameConversion(string $name)
    {
        return str_replace('_', '', ucwords($name, '_'));
    }
}
