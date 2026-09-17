<?php

namespace GCWorld\ORM\Tests;

use GCWorld\ORM\FieldName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FieldNameTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function names(): array
    {
        return [
            'single word' => ['status', 'Status'],
            'snake case' => ['member_uuid', 'MemberUuid'],
            'multiple separators' => ['audit_created_at', 'AuditCreatedAt'],
        ];
    }

    #[DataProvider('names')]
    public function testConvertsDatabaseFieldNames(string $field, string $converted): void
    {
        self::assertSame($converted, FieldName::nameConversion($field));
        self::assertSame('get' . $converted, FieldName::getterName($field));
        self::assertSame('set' . $converted, FieldName::setterName($field));
    }
}
