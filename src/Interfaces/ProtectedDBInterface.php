<?php

namespace GCWorld\ORM\Interfaces;

/**
 * Interface ProtectedDBInterface.
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
