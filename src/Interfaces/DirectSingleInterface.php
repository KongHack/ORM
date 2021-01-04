<?php
namespace GCWorld\ORM\Interfaces;

/**
 * Interface DirectSingleInterface
 */
interface DirectSingleInterface
{
    /**
     * @return bool
     */
    public function save(): bool;

    /**
     * Purges the current item from Redis
     * @return void
     */
    public function purgeCache(): void;

    /**
     * Gets the field keys from the dbInfo array.
     * @return array
     */
    public function getFieldKeys(): array;

    /**
     * @return bool
     */
    public function _hasChanged(): bool;

    /**
     * @return array
     */
    public function _getChanged(): array;

    /**
     * @return array
     */
    public function _getLastChanged(): array;
}
