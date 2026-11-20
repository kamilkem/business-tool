<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\EventHandler;

use App\Payroll\Domain\Event\PayrollRunApproved;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Domain\Event\EventHandlerInterface;
use App\TimeTracking\Application\Command\LockShiftPeriod\LockShiftPeriodCommand;
use Symfony\Component\Uid\Uuid;

/**
 * An approved payroll run locks its month's shifts (ADR 0027 §8), keyed by the run's id: approving
 * the same run again locks nothing new. Runs in the approving request, with its organization selected.
 */
final readonly class LockShiftsOnPayrollRunApproved implements EventHandlerInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
    ) {
    }

    public function __invoke(PayrollRunApproved $event): void
    {
        $this->commandBus->handle(new LockShiftPeriodCommand(
            $event->organizationId,
            Uuid::v7()->toRfc4122(),
            $event->payrollRunId,
            $event->firstDate,
            $event->lastDate,
        ));
    }
}
