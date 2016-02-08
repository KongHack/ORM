<?php
namespace GCWorld\ORM;

use GCWorld\ORM\Abstracts\DirectSingle;

abstract class DirectDBClass extends DirectSingle
{
    public function get($key)
    {
        return parent::get($key);
    }

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
