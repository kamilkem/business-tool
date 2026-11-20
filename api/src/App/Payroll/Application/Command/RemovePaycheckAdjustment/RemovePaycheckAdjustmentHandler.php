<?php

declare(strict_types=1);

namespace App\Payroll\Application\Command\RemovePaycheckAdjustment;

use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Payroll\Application\PayrollActor;
use App\Payroll\Domain\Exception\PaycheckAdjustmentNotFoundException;
use App\Payroll\Domain\Exception\PayrollAccessForbiddenException;
use App\Payroll\Domain\Exception\PayrollRunNotFoundException;
use App\Payroll\Domain\Exception\PayrollRunStatusException;
use App\Payroll\Domain\Repository\PayrollRunRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Uid\Uuid;

final readonly class RemovePaycheckAdjustmentHandler implements CommandHandlerInterface
{
    public function __construct(
        private PayrollRunRepositoryInterface $runs,
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException       when the actor is not an active member of the organization
     * @throws PayrollAccessForbiddenException     when the actor is not an owner or manager
     * @throws PayrollRunNotFoundException         when the run is not the organization's
     * @throws PaycheckAdjustmentNotFoundException when the adjustment is not on the run
     * @throws PayrollRunStatusException           when the run is approved
     */
    public function __invoke(RemovePaycheckAdjustmentCommand $command): void
    {
        $actor = PayrollActor::manager($this->queryBus, $command->organizationId, $command->actorId);
        $run = (Uuid::isValid($command->payrollRunId) ? $this->runs->findById(Uuid::fromString($command->payrollRunId)) : null)
            ?? throw PayrollRunNotFoundException::forId($command->payrollRunId);
        if (!Uuid::isValid($command->adjustmentId)) {
            throw PaycheckAdjustmentNotFoundException::forId($command->adjustmentId);
        }

        $run->removeAdjustment(Uuid::fromString($command->adjustmentId), $actor->id, $actor->role);

        $this->runs->save($run);
    }
}
