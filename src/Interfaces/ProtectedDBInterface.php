<?php
namespace GCWorld\ORM\Interfaces;

/**
 * Interface ProtectedDBInterface
 * @package GCWorld\ORM\Interfaces
 */
interface ProtectedDBInterface
{
    /**
     * @return mixed
     */
    public function save();

    /**
     * @return mixed
     */
    public function getFieldKeys();
}
