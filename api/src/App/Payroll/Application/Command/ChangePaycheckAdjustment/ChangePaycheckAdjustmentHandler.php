<?php

declare(strict_types=1);

namespace App\Payroll\Application\Command\ChangePaycheckAdjustment;

use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Payroll\Application\PayrollActor;
use App\Payroll\Domain\Exception\InvalidPaycheckAdjustmentException;
use App\Payroll\Domain\Exception\PaycheckAdjustmentNotFoundException;
use App\Payroll\Domain\Exception\PayrollAccessForbiddenException;
use App\Payroll\Domain\Exception\PayrollRunNotFoundException;
use App\Payroll\Domain\Exception\PayrollRunStatusException;
use App\Payroll\Domain\Repository\PayrollRunRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final readonly class ChangePaycheckAdjustmentHandler implements CommandHandlerInterface
{
    public function __construct(
        private PayrollRunRepositoryInterface $runs,
        private QueryBusInterface $queryBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException       when the actor is not an active member of the organization
     * @throws PayrollAccessForbiddenException     when the actor is not an owner or manager
     * @throws PayrollRunNotFoundException         when the run is not the organization's
     * @throws PaycheckAdjustmentNotFoundException when the adjustment is not on the run
     * @throws PayrollRunStatusException           when the run is approved
     * @throws InvalidPaycheckAdjustmentException  when the label or amount is invalid
     */
    public function __invoke(ChangePaycheckAdjustmentCommand $command): void
    {
        $actor = PayrollActor::manager($this->queryBus, $command->organizationId, $command->actorId);
        $run = (Uuid::isValid($command->payrollRunId) ? $this->runs->findById(Uuid::fromString($command->payrollRunId)) : null)
            ?? throw PayrollRunNotFoundException::forId($command->payrollRunId);
        if (!Uuid::isValid($command->adjustmentId)) {
            throw PaycheckAdjustmentNotFoundException::forId($command->adjustmentId);
        }

        $run->changeAdjustment(Uuid::fromString($command->adjustmentId), $command->label, $command->amount, $actor->id, $actor->role, $this->clock->now());

        $this->runs->save($run);
    }
}
