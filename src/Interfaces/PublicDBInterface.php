<?php
namespace GCWorld\ORM\Interfaces;

/**
 * Interface PublicDBInterface
 * @package GCWorld\ORM\Interfaces
 */
interface PublicDBInterface
{
    /**
     * @param string $key
     * @param mixed  $val
     * @return mixed
     */
    public function set(string $key, $val);

    /**
     * @param string $key
     * @return mixed
     */
    public function get(string $key);

    /**
     * @return mixed
     */
    public function save();

    /**
     * @return mixed
     */
    public function getFieldKeys();
}
