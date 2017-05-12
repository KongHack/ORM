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
     * @param string $key
     * @return mixed
     */
    public function get(string $key)
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

    /**
     * @param array $data
     * @return $this
     */
    public function setArray(array $data)
    {
        parent::setArray($data);

        return $this;
    }
}
