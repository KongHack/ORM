<?php

namespace GCWorld\ORM\Interfaces;

/**
 * Interface ORMDescriptionInterface
 */
interface ORMDescriptionInterface
{
    /**
     * @return string
     */
    public static function getFieldName(string $fieldName): string;

    /**
     * @return string
     */
    public static function getFieldDesc(string $fieldName): string;

    /**
     * @return string
     */
    public static function getFieldHelp(string $fieldName): string;
}
