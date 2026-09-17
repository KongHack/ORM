<?php

namespace GCWorld\ORM\Tests\Fixtures;

use GCWorld\ORM\Traits\ORMFieldsTrait;

final class ORMFieldsFixture
{
    use ORMFieldsTrait;

    /**
     * @var array<string, array{title: string, desc: string, help: string, maxlen: int}>
     */
    protected static array $ORM_FIELDS = [
        'email' => [
            'title' => 'Email',
            'desc' => 'Member email address',
            'help' => 'Use a deliverable address',
            'maxlen' => 254,
        ],
    ];
}
