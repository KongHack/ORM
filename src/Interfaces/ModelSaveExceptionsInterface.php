<?php
namespace GCWorld\ORM\Interfaces;

/**
 * Interface ModelSaveExceptionsInterface
 */
interface ModelSaveExceptionsInterface
{
    /**
     * @return array
     */
    public function getFieldMessages(): array;

    /**
     * @return bool
     */
    public function isThrowable(): bool;
}
