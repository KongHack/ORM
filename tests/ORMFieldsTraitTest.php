<?php

namespace GCWorld\ORM\Tests;

use GCWorld\ORM\Tests\Fixtures\ORMFieldsFixture;
use PHPUnit\Framework\TestCase;

final class ORMFieldsTraitTest extends TestCase
{
    public function testReadsGeneratedFieldMetadata(): void
    {
        self::assertSame('Email', ORMFieldsFixture::getORMFieldTitle('email'));
        self::assertSame('Member email address', ORMFieldsFixture::getORMFieldDesc('email'));
        self::assertSame('Use a deliverable address', ORMFieldsFixture::getORMFieldHelp('email'));
        self::assertSame(254, ORMFieldsFixture::getORMFieldMaxLength('email'));
    }

    public function testUnknownFieldsReturnDefaults(): void
    {
        self::assertNull(ORMFieldsFixture::getORMFieldTitle('missing'));
        self::assertNull(ORMFieldsFixture::getORMFieldDesc('missing'));
        self::assertNull(ORMFieldsFixture::getORMFieldHelp('missing'));
        self::assertSame(0, ORMFieldsFixture::getORMFieldMaxLength('missing'));
    }
}
