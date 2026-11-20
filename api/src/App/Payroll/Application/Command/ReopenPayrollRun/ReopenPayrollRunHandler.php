<?php

declare(strict_types=1);

namespace App\Payroll\Application\Command\ReopenPayrollRun;

use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Payroll\Application\PayrollActor;
use App\Payroll\Domain\Event\PayrollRunReopened;
use App\Payroll\Domain\Exception\PayrollAccessForbiddenException;
use App\Payroll\Domain\Exception\PayrollRunNotFoundException;
use App\Payroll\Domain\Exception\PayrollRunStatusException;
use App\Payroll\Domain\Repository\PayrollRunRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Event\EventPublisherInterface;
use Symfony\Component\Uid\Uuid;

/** Reopens the run, then publishes PayrollRunReopened; Time tracking unlocks the month's shifts from it. */
final readonly class ReopenPayrollRunHandler implements CommandHandlerInterface
{
    public function __construct(
        private PayrollRunRepositoryInterface $runs,
        private QueryBusInterface $queryBus,
        private EventPublisherInterface $events,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException   when the actor is not an active member of the organization
     * @throws PayrollAccessForbiddenException when the actor is not an owner
     * @throws PayrollRunNotFoundException     when the run is not the organization's
     * @throws PayrollRunStatusException       when the run is a draft
     */
    public function __invoke(ReopenPayrollRunCommand $command): void
    {
        $actor = PayrollActor::owner($this->queryBus, $command->organizationId, $command->actorId);
        $run = (Uuid::isValid($command->payrollRunId) ? $this->runs->findById(Uuid::fromString($command->payrollRunId)) : null)
            ?? throw PayrollRunNotFoundException::forId($command->payrollRunId);

        $run->reopen($actor->id, $actor->role);
        $this->runs->save($run);

        $this->events->publish(new PayrollRunReopened($command->organizationId, $run->getId()->toRfc4122(), $actor->id->toRfc4122()));
    }
}
