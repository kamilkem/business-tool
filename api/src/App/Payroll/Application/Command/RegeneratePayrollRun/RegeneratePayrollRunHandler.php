<?php

declare(strict_types=1);

namespace App\Payroll\Application\Command\RegeneratePayrollRun;

use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Payroll\Application\PayrollActor;
use App\Payroll\Application\PayrollSourcesLoader;
use App\Payroll\Domain\Exception\InvalidRoundingException;
use App\Payroll\Domain\Exception\PayrollAccessForbiddenException;
use App\Payroll\Domain\Exception\PayrollRunEmptyException;
use App\Payroll\Domain\Exception\PayrollRunNotFoundException;
use App\Payroll\Domain\Exception\PayrollRunStatusException;
use App\Payroll\Domain\Model\Rounding;
use App\Payroll\Domain\Repository\PayrollRunRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final readonly class RegeneratePayrollRunHandler implements CommandHandlerInterface
{
    public function __construct(
        private PayrollRunRepositoryInterface $runs,
        private PayrollSourcesLoader $sources,
        private QueryBusInterface $queryBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException   when the actor is not an active member of the organization
     * @throws PayrollAccessForbiddenException when the actor is not an owner or manager
     * @throws PayrollRunNotFoundException     when the run is not the organization's
     * @throws InvalidRoundingException        when only one rounding field is given, or one is invalid
     * @throws PayrollRunStatusException       when the run is approved
     * @throws PayrollRunEmptyException        when nothing would be left to pay
     */
    public function __invoke(RegeneratePayrollRunCommand $command): void
    {
        $actor = PayrollActor::manager($this->queryBus, $command->organizationId, $command->actorId);
        $run = (Uuid::isValid($command->payrollRunId) ? $this->runs->findById(Uuid::fromString($command->payrollRunId)) : null)
            ?? throw PayrollRunNotFoundException::forId($command->payrollRunId);
        $rounding = null === $command->roundingUnit && null === $command->roundingDirection
            ? null
            : Rounding::fromStrings($command->roundingUnit ?? '', $command->roundingDirection ?? '');

        $run->regenerate($rounding, $this->sources->load($run->month(), $actor->organization($this->queryBus)), $actor->id, $actor->role, $this->clock->now());

        $this->runs->save($run);
    }
}
