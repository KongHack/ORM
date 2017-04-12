<?php
namespace GCWorld\ORM\Interfaces;

/**
 * Interface PublicDBInterface
 * @package GCWorld\ORM\Interfaces
 */
interface PublicDBInterface
{
    /**
     * @param $key
     * @param $val
     * @return mixed
     */
    public function set($key, $val);

    /**
     * @param $key
     * @return mixed
     */
    public function get($key);

    /**
     * @return mixed
     */
    public function save();

    /**
     * @return mixed
     */
    public function getFieldKeys();
}
