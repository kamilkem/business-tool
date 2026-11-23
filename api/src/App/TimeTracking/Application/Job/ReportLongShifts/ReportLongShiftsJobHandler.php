<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Job\ReportLongShifts;

use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Job\JobHandlerInterface;
use App\TimeTracking\Application\Command\ReportLongShift\ReportLongShiftCommand;
use App\TimeTracking\Application\MaxShiftLengths;
use App\TimeTracking\Domain\Repository\ShiftRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * Runs in the organization's tenant context (the job bus switches it). Lists its open shifts past
 * the limit that are not reported yet, and sends one ReportLongShiftCommand each: a shift reported
 * before an interruption is not reported again.
 */
final readonly class ReportLongShiftsJobHandler implements JobHandlerInterface
{
    public function __construct(
        private ShiftRepositoryInterface $shifts,
        private MaxShiftLengths $maxShiftLengths,
        private CommandBusInterface $commandBus,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ReportLongShiftsJob $job): void
    {
        $startedBefore = $this->maxShiftLengths->current()->startedBefore($this->clock->now());

        foreach ($this->shifts->findOpenToReport($startedBefore) as $shift) {
            $this->commandBus->handle(new ReportLongShiftCommand($job->organizationId, $shift->getId()->toRfc4122()));
        }
    }
}
