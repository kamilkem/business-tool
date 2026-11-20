<?php

declare(strict_types=1);

namespace App\Payroll\Application\Command\UndoPaycheckPaid;

use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Payroll\Application\PayrollActor;
use App\Payroll\Domain\Exception\PaycheckNotFoundException;
use App\Payroll\Domain\Exception\PaycheckPaymentException;
use App\Payroll\Domain\Exception\PayrollAccessForbiddenException;
use App\Payroll\Domain\Exception\PayrollRunNotFoundException;
use App\Payroll\Domain\Repository\PayrollRunRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Uid\Uuid;

final readonly class UndoPaycheckPaidHandler implements CommandHandlerInterface
{
    public function __construct(
        private PayrollRunRepositoryInterface $runs,
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException   when the actor is not an active member of the organization
     * @throws PayrollAccessForbiddenException when the actor is not an owner
     * @throws PayrollRunNotFoundException     when the run is not the organization's
     * @throws PaycheckNotFoundException       when the paycheck is not on the run
     * @throws PaycheckPaymentException        when the paycheck is not marked paid
     */
    public function __invoke(UndoPaycheckPaidCommand $command): void
    {
        $actor = PayrollActor::payer($this->queryBus, $command->organizationId, $command->actorId);
        $run = (Uuid::isValid($command->payrollRunId) ? $this->runs->findById(Uuid::fromString($command->payrollRunId)) : null)
            ?? throw PayrollRunNotFoundException::forId($command->payrollRunId);
        if (!Uuid::isValid($command->paycheckId)) {
            throw PaycheckNotFoundException::forId($command->paycheckId);
        }

        $run->undoPaycheckPaid(Uuid::fromString($command->paycheckId), $actor->id, $actor->role);
        $this->runs->save($run);
    }
}
