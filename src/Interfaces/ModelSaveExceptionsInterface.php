<?php

namespace GCWorld\ORM\Interfaces;

/**
 * Interface ModelSaveExceptionsInterface.
 */
interface ModelSaveExceptionsInterface
{
    /**
     * @return array<string, string>
     */
    public function getFieldMessages(): array;

    /**
     * @return bool
     */
    public function isThrowable(): bool;
}
