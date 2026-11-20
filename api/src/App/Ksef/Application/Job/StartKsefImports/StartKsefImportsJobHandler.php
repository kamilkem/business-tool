<?php

declare(strict_types=1);

namespace App\Ksef\Application\Job\StartKsefImports;

use App\Ksef\Application\Job\ImportKsefInvoices\ImportKsefInvoicesJob;
use App\Ksef\Application\Query\ListKsefOrganizationsToImport\ListKsefOrganizationsToImportQuery;
use App\Shared\Application\Job\JobBusInterface;
use App\Shared\Application\Job\JobHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Psr\Clock\ClockInterface;

final readonly class StartKsefImportsJobHandler implements JobHandlerInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private JobBusInterface $jobBus,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(StartKsefImportsJob $job): void
    {
        /** @var list<string> $organizationIds */
        $organizationIds = $this->queryBus->ask(new ListKsefOrganizationsToImportQuery($this->clock->now()->format(\DATE_ATOM)));

        foreach ($organizationIds as $organizationId) {
            $this->jobBus->dispatch(new ImportKsefInvoicesJob($organizationId), ImportKsefInvoicesJob::deduplicationKey($organizationId));
        }
    }
}
