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
     * @param string $key
     * @return mixed
     */
    public function get(string $key)
    {
        return parent::get($key);
    }

    /**
     * @param string $key
     * @param mixed  $val
     * @return $this
     */
    public function set(string $key, $val)
    {
        parent::set($key, $val);

        return $this;
    }
}
