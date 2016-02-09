<?php
namespace GCWorld\ORM;

class FieldName
{
    /**
     * @param string $field
     * @return string
     */
    public static function getterName($field)
    {
        return 'get'.self::nameConversion($field);
    }

    /**
     * @param string $field
     * @return string
     */
    public static function setterName($field)
    {
        return 'set'.self::nameConversion($field);
    }

    /**
     * @param string $name
     * @return mixed
     */
    public static function nameConversion($name)
    {
        return str_replace('_', '', ucwords($name, '_'));
    }
}
