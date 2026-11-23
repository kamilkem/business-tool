<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Job\StartLongShiftReports;

use App\Shared\Application\Job\JobBusInterface;
use App\Shared\Application\Job\JobHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\TimeTracking\Application\Job\ReportLongShifts\ReportLongShiftsJob;
use App\TimeTracking\Application\Query\ListOrganizationsWithLongShifts\ListOrganizationsWithLongShiftsQuery;
use Psr\Clock\ClockInterface;

final readonly class StartLongShiftReportsJobHandler implements JobHandlerInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private JobBusInterface $jobBus,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(StartLongShiftReportsJob $job): void
    {
        /** @var list<string> $organizationIds */
        $organizationIds = $this->queryBus->ask(new ListOrganizationsWithLongShiftsQuery($this->clock->now()->format(\DATE_ATOM)));

        foreach ($organizationIds as $organizationId) {
            $this->jobBus->dispatch(new ReportLongShiftsJob($organizationId), ReportLongShiftsJob::deduplicationKey($organizationId));
        }
    }
}
