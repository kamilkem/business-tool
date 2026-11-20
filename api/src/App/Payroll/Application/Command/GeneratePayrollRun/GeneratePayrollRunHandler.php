<?php

declare(strict_types=1);

namespace App\Payroll\Application\Command\GeneratePayrollRun;

use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Payroll\Application\PayrollActor;
use App\Payroll\Application\PayrollSourcesLoader;
use App\Payroll\Domain\Exception\InvalidRoundingException;
use App\Payroll\Domain\Exception\PayrollAccessForbiddenException;
use App\Payroll\Domain\Exception\PayrollMonthInTheFutureException;
use App\Payroll\Domain\Exception\PayrollRunEmptyException;
use App\Payroll\Domain\Exception\PayrollRunMonthTakenException;
use App\Payroll\Domain\Model\PayrollRun;
use App\Payroll\Domain\Model\Rounding;
use App\Payroll\Domain\Repository\PayrollRunRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\BusinessPeriod;
use App\Shared\Domain\Time\Exception\TimeException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final readonly class GeneratePayrollRunHandler implements CommandHandlerInterface
{
    public function __construct(
        private PayrollRunRepositoryInterface $runs,
        private PayrollSourcesLoader $sources,
        private QueryBusInterface $queryBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException    when the actor is not an active member of the organization
     * @throws PayrollAccessForbiddenException  when the actor is not an owner or manager
     * @throws TimeException                    when the month is not a YYYY-MM month
     * @throws InvalidRoundingException         when the rounding is not a unit and a direction
     * @throws PayrollRunMonthTakenException    when the month has a run
     * @throws PayrollMonthInTheFutureException when the month starts after the current one
     * @throws PayrollRunEmptyException         when the month has no ended shift
     */
    public function __invoke(GeneratePayrollRunCommand $command): void
    {
        $actor = PayrollActor::manager($this->queryBus, $command->organizationId, $command->actorId);
        $month = BusinessPeriod::monthFromString($command->month);
        $rounding = Rounding::fromStrings($command->roundingUnit, $command->roundingDirection);
        if (null !== $this->runs->findByMonth($month)) {
            throw PayrollRunMonthTakenException::month($month);
        }

        $organization = $actor->organization($this->queryBus);
        $now = $this->clock->now();

        $this->runs->add(PayrollRun::generate(
            Uuid::fromString($command->payrollRunId),
            $rounding,
            $this->sources->load($month, $organization),
            BusinessDate::containing($now, new \DateTimeZone($organization->timezone)),
            $actor->id,
            $actor->role,
            $now,
        ));
    }
}
