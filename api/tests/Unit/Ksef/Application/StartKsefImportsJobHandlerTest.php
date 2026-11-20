<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ksef\Application;

use App\Ksef\Application\Job\ImportKsefInvoices\ImportKsefInvoicesJob;
use App\Ksef\Application\Job\StartKsefImports\StartKsefImportsJob;
use App\Ksef\Application\Job\StartKsefImports\StartKsefImportsJobHandler;
use App\Ksef\Application\Query\ListKsefOrganizationsToImport\ListKsefOrganizationsToImportQuery;
use App\Shared\Application\Query\QueryInterface;
use App\Tests\Unit\Shared\Application\RecordingJobBus;
use App\Tests\Unit\Shared\Application\StubQueryBus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(StartKsefImportsJobHandler::class)]
final class StartKsefImportsJobHandlerTest extends TestCase
{
    public function test_it_queues_one_deduplicated_import_per_organization_that_may_sync(): void
    {
        $queryBus = new StubQueryBus(static fn (QueryInterface $query): array => $query instanceof ListKsefOrganizationsToImportQuery
            ? ['0196f8a2-0000-7000-8000-000000000001', '0196f8a2-0000-7000-8000-000000000002']
            : throw new \LogicException($query::class));
        $jobBus = new RecordingJobBus();

        (new StartKsefImportsJobHandler($queryBus, $jobBus, new MockClock('2026-11-20 20:00:00 UTC')))(new StartKsefImportsJob());

        self::assertEquals([new ListKsefOrganizationsToImportQuery('2026-11-20T20:00:00+00:00')], $queryBus->queries);
        self::assertEquals([
            ['job' => new ImportKsefInvoicesJob('0196f8a2-0000-7000-8000-000000000001'), 'deduplicationKey' => 'ksef-import:0196f8a2-0000-7000-8000-000000000001'],
            ['job' => new ImportKsefInvoicesJob('0196f8a2-0000-7000-8000-000000000002'), 'deduplicationKey' => 'ksef-import:0196f8a2-0000-7000-8000-000000000002'],
        ], $jobBus->dispatched);
    }
}
