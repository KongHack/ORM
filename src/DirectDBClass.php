<?php
namespace GCWorld\ORM;

use GCWorld\ORM\Abstracts\DirectSingle;

/**
 * Class DirectDBClass
 * @package GCWorld\ORM
 */
abstract class DirectDBClass extends DirectSingle
{
    /**
     * @param $key
     * @return mixed
     */
    public function get($key)
    {
        return parent::get($key);
    }

    /**
     * @param array $fields
     * @return array
     */
    public function getArray(array $fields)
    {
        return parent::getArray($fields);
    }

    public function set($key, $val)
    {
        return parent::set($key, $val);
    }

    public function setArray(array $data)
    {
        return parent::setArray($data);
    }
}
