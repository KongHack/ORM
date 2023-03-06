<?php

namespace GCWorld\ORM\Interfaces;

/**
 * Interface ORMDescriptionInterface
 */
interface ORMDescriptionInterface
{
    /**
     * @param string $fieldName
     * @return string|null
     */
    public static function getFieldTitle(string $fieldName): ?string;

    /**
     * @param string $fieldName
     * @return string|null
     */
    public static function getFieldDesc(string $fieldName): ?string;

    /**
     * @param string $fieldName
     * @return string|null
     */
    public static function getFieldHelp(string $fieldName): ?string;
}
