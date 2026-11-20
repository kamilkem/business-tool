<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\EventHandler;

use App\Payroll\Domain\Event\PayrollRunReopened;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Domain\Event\EventHandlerInterface;
use App\TimeTracking\Application\Command\UnlockShiftPeriod\UnlockShiftPeriodCommand;

/** A reopened payroll run unlocks the shifts it locked (ADR 0027 §9); idempotent. */
final readonly class UnlockShiftsOnPayrollRunReopened implements EventHandlerInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
    ) {
    }

    public function __invoke(PayrollRunReopened $event): void
    {
        $this->commandBus->handle(new UnlockShiftPeriodCommand($event->organizationId, $event->payrollRunId));
    }
}
