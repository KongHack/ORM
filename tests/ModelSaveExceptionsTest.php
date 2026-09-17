<?php

namespace GCWorld\ORM\Tests;

use Exception;
use GCWorld\ORM\Exceptions\ModelRequiredFieldException;
use GCWorld\ORM\Exceptions\ModelSaveExceptions;
use PHPUnit\Framework\TestCase;

final class ModelSaveExceptionsTest extends TestCase
{
    public function testCollectsFieldAndGeneralMessages(): void
    {
        $exceptions = new ModelSaveExceptions();
        $exceptions->addException(new ModelRequiredFieldException('email'));
        $exceptions->addException(new Exception('Database unavailable'));

        self::assertTrue($exceptions->isThrowable());
        self::assertSame(
            [
                'email' => 'Email is a required field',
                'UNDEFINED_1' => 'Database unavailable',
            ],
            $exceptions->getFieldMessages(),
        );
        self::assertCount(2, $exceptions->getExceptions());
    }

    public function testThrowsItselfWhenExceptionsExist(): void
    {
        $exceptions = new ModelSaveExceptions();
        $exceptions->addException(new Exception('Failure'));

        $this->expectExceptionObject($exceptions);
        $exceptions->doThrow();
    }

    public function testDoesNotThrowWhenEmpty(): void
    {
        $exceptions = new ModelSaveExceptions();
        $exceptions->doThrow();

        self::assertFalse($exceptions->isThrowable());
        self::assertSame([], $exceptions->getFieldMessages());
    }
}
