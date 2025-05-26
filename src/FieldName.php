<?php
namespace GCWorld\ORM;

/**
 * Class FieldName
 * @package GCWorld\ORM
 */
class FieldName
{
    public static function getterName(string $field): string
    {
        return 'get'.self::nameConversion($field);
    }

    public static function setterName(string $field): string
    {
        return 'set'.self::nameConversion($field);
    }

    public static function nameConversion(string $name): string
    {
        return str_replace('_', '', ucwords($name, '_'));
    }
}
