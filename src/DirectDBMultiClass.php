<?php
namespace GCWorld\ORM;

use GCWorld\ORM\Abstracts\DirectMulti;

abstract class DirectDBMultiClass extends DirectMulti
{
    public function get($key)
    {
        return parent::get($key);
    }

    public function set($key, $val)
    {
        return parent::set($key, $val);
    }
}
