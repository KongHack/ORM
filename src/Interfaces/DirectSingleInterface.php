<?php

namespace GCWorld\ORM\Interfaces;

/**
 * Interface DirectSingleInterface.
 */
interface DirectSingleInterface
{
    /**
     * Purges the current item from Redis.
     *
     * @return void
     */
    public function purgeCache(): void;

    /**
     * Gets the field keys from the dbInfo array.
     *
     * @return list<string>
     */
    public function getFieldKeys(): array;

    /**
     * @return bool
     */
    public function _hasChanged(): bool;

    /**
     * @return list<string>
     */
    public function _getChanged(): array;

    /**
     * @return list<string>
     */
    public function _getLastChanged(): array;
}
