<?php

declare(strict_types=1);

namespace App\Tests\Unit\TimeTracking\Application;

use App\Payroll\Domain\Event\PayrollRunApproved;
use App\Payroll\Domain\Event\PayrollRunReopened;
use App\Tests\Unit\Shared\Application\RecordingCommandBus;
use App\TimeTracking\Application\Command\LockShiftPeriod\LockShiftPeriodCommand;
use App\TimeTracking\Application\Command\UnlockShiftPeriod\UnlockShiftPeriodCommand;
use App\TimeTracking\Application\EventHandler\LockShiftsOnPayrollRunApproved;
use App\TimeTracking\Application\EventHandler\UnlockShiftsOnPayrollRunReopened;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(LockShiftsOnPayrollRunApproved::class)]
#[CoversClass(UnlockShiftsOnPayrollRunReopened::class)]
final class PayrollRunLockEventHandlersTest extends TestCase
{
    public function test_it_locks_the_months_shifts_on_behalf_of_the_approved_run(): void
    {
        $commands = new RecordingCommandBus();
        $organizationId = Uuid::v7()->toRfc4122();
        $runId = Uuid::v7()->toRfc4122();

        (new LockShiftsOnPayrollRunApproved($commands))(new PayrollRunApproved($organizationId, $runId, '2026-10-01', '2026-10-31', Uuid::v7()->toRfc4122()));

        $locks = $commands->commandsOf(LockShiftPeriodCommand::class);
        self::assertCount(1, $locks);
        self::assertSame([$organizationId, $runId, '2026-10-01', '2026-10-31'], [$locks[0]->organizationId, $locks[0]->lockedBy, $locks[0]->firstDate, $locks[0]->lastDate]);
        self::assertTrue(Uuid::isValid($locks[0]->lockedShiftPeriodId));
    }

    public function test_it_unlocks_what_the_reopened_run_locked(): void
    {
        $commands = new RecordingCommandBus();
        $organizationId = Uuid::v7()->toRfc4122();
        $runId = Uuid::v7()->toRfc4122();

        (new UnlockShiftsOnPayrollRunReopened($commands))(new PayrollRunReopened($organizationId, $runId, Uuid::v7()->toRfc4122()));

        self::assertEquals([new UnlockShiftPeriodCommand($organizationId, $runId)], $commands->commands);
    }
}
