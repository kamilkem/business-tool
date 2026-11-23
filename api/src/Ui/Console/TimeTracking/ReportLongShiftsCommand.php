<?php

declare(strict_types=1);

namespace Ui\Console\TimeTracking;

use App\Shared\Application\Job\JobBusInterface;
use App\TimeTracking\Application\Job\ReportLongShifts\ReportLongShiftsJob;
use App\TimeTracking\Application\Job\StartLongShiftReports\StartLongShiftReportsJob;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Manual run of the hourly long-shift report (#33, ADR 0025 §6): queues the same jobs as the
 * schedule and returns; the `jobs-worker` runs them. Shifts already reported are not reported again.
 */
#[AsCommand(name: 'app:time-tracking:report-long-shifts', description: 'Queues the report of open shifts longer than the maximum shift length, for every organization or for one')]
final readonly class ReportLongShiftsCommand
{
    public function __construct(private JobBusInterface $jobBus)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Queue the report of this organization only (its id)')]
        ?string $organization = null,
    ): int {
        if (null === $organization) {
            $this->jobBus->dispatch(new StartLongShiftReportsJob());
            $io->success('Queued the long-shift report of every organization.');

            return Command::SUCCESS;
        }

        if (!Uuid::isValid($organization)) {
            $io->error(\sprintf('"%s" is not an organization id.', $organization));

            return Command::INVALID;
        }

        $organizationId = Uuid::fromString($organization)->toRfc4122();
        $this->jobBus->dispatch(new ReportLongShiftsJob($organizationId), ReportLongShiftsJob::deduplicationKey($organizationId));
        $io->success(\sprintf('Queued the long-shift report of the organization %s (unless one is already queued or running).', $organizationId));

        return Command::SUCCESS;
    }
}
