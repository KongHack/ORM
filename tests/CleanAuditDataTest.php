<?php

namespace GCWorld\ORM\Tests;

use GCWorld\ORM\Helpers\CleanAuditData;
use PHPUnit\Framework\TestCase;

final class CleanAuditDataTest extends TestCase
{
    public function testStoresSnapshotsAndIndividualValues(): void
    {
        $data = new CleanAuditData();
        $data->setBefore(['status' => 'draft']);
        $data->setAfter(['status' => 'published']);
        $data->addBefore('owner_id', 10);
        $data->addAfter('owner_id', 20);

        self::assertSame(
            ['status' => 'draft', 'owner_id' => 10],
            $data->getBefore(),
        );
        self::assertSame(
            ['status' => 'published', 'owner_id' => 20],
            $data->getAfter(),
        );
    }
}
