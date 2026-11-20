<?php

declare(strict_types=1);

namespace App\Payroll\Application\Query\ExportPayrollRun;

use App\Organization\Application\Query\ListMembers\ListMembersQuery;
use App\Organization\Application\Query\ListMembers\MemberView;
use App\Payroll\Application\PayrollActor;
use App\Payroll\Domain\Model\Paycheck;
use App\Payroll\Domain\Model\ShiftLine;
use App\Payroll\Domain\Repository\PayrollRunRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\TimeTracking\Application\Query\ListWorkedShifts\ListWorkedShiftsQuery;
use App\TimeTracking\Application\Query\ListWorkedShifts\WorkedShiftsView;
use App\TimeTracking\Application\Query\ListWorkedShifts\WorkedShiftView;
use Symfony\Component\Uid\Uuid;

/**
 * Reads the run's own snapshot for the amounts and asks Organization for the names (deactivated
 * members included, ADR 0014) and Time tracking for the shifts' start and end: an approved month's
 * shifts are locked (ADR 0027 §8), so they are the ones the run paid.
 */
final readonly class ExportPayrollRunHandler implements QueryHandlerInterface
{
    public function __construct(
        private PayrollRunRepositoryInterface $runs,
        private QueryBusInterface $queryBus,
    ) {
    }

    public function __invoke(ExportPayrollRunQuery $query): ?PayrollRunExportView
    {
        $actor = PayrollActor::manager($this->queryBus, $query->organizationId, $query->actorId);
        $run = Uuid::isValid($query->payrollRunId) ? $this->runs->findById(Uuid::fromString($query->payrollRunId)) : null;
        if (null === $run) {
            return null;
        }
        $run->assertCanExport($actor->id, $actor->role);

        $organization = $actor->organization($this->queryBus);
        $month = $run->month();
        $names = $this->namesByUserId($query->organizationId, $query->actorId);
        $shifts = $this->shiftsById($month->first->toString(), $month->last->toString(), $organization->timezone);

        $paychecks = array_map(fn (Paycheck $paycheck): PaycheckExportView => new PaycheckExportView(
            $paycheck->getUserId()->toRfc4122(),
            $names[$paycheck->getUserId()->toRfc4122()][0] ?? '',
            $names[$paycheck->getUserId()->toRfc4122()][1] ?? '',
            $paycheck->paidSeconds(),
            $paycheck->payForHours(),
            $paycheck->adjustmentsTotal(),
            $paycheck->total(),
            $paycheck->getPaidOn()?->toString(),
            $paycheck->getPaymentMethod()?->value,
            array_map(static fn (ShiftLine $line): PaycheckLineExportView => new PaycheckLineExportView(
                $line->shiftId->toRfc4122(),
                $line->date->toString(),
                ($shifts[$line->shiftId->toRfc4122()] ?? null)?->startedAt,
                ($shifts[$line->shiftId->toRfc4122()] ?? null)?->endedAt,
                $line->seconds,
                $line->paidSeconds,
                $line->rateKind?->value,
                $line->rate,
                $line->amount(),
            ), $paycheck->shiftLines()),
        ), $run->paychecks());
        usort($paychecks, static fn (PaycheckExportView $a, PaycheckExportView $b): int => [$a->lastName, $a->firstName, $a->userId] <=> [$b->lastName, $b->firstName, $b->userId]);

        return new PayrollRunExportView(
            $run->getId()->toRfc4122(),
            substr($month->first->toString(), 0, 7),
            $month->first->toString(),
            $month->last->toString(),
            $run->getStatus()->value,
            $run->getCurrency(),
            $organization->timezone,
            $organization->csvSeparator,
            $paychecks,
        );
    }

    /** @return array<string, array{string, string}> first and last name by user id */
    private function namesByUserId(string $organizationId, string $actorId): array
    {
        /** @var list<MemberView>|null $members */
        $members = $this->queryBus->ask(new ListMembersQuery($organizationId, $actorId, includeDeactivated: true));
        $names = [];
        foreach ($members ?? [] as $member) {
            $names[$member->userId] = [$member->firstName, $member->lastName];
        }

        return $names;
    }

    /** @return array<string, WorkedShiftView> */
    private function shiftsById(string $from, string $to, string $timeZone): array
    {
        /** @var WorkedShiftsView $worked */
        $worked = $this->queryBus->ask(new ListWorkedShiftsQuery($from, $to, $timeZone));
        $shifts = [];
        foreach ($worked->shifts as $shift) {
            $shifts[$shift->shiftId] = $shift;
        }

        return $shifts;
    }
}
