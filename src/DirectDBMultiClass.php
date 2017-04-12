<?php
namespace GCWorld\ORM;

use GCWorld\ORM\Abstracts\DirectMulti;

/**
 * Class DirectDBMultiClass
 * @package GCWorld\ORM
 */
abstract class DirectDBMultiClass extends DirectMulti
{
    /**
     * @param $key
     * @return mixed
     */
    public function get($key)
    {
        return parent::get($key);
    }

    public function set($key, $val)
    {
        return parent::set($key, $val);
    }
}
