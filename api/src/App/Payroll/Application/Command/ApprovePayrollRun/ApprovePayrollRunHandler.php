<?php

declare(strict_types=1);

namespace App\Payroll\Application\Command\ApprovePayrollRun;

use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Payroll\Application\PayrollActor;
use App\Payroll\Application\PayrollSourcesLoader;
use App\Payroll\Domain\Event\PayrollRunApproved;
use App\Payroll\Domain\Exception\PayrollAccessForbiddenException;
use App\Payroll\Domain\Exception\PayrollRunNotApprovableException;
use App\Payroll\Domain\Exception\PayrollRunNotFoundException;
use App\Payroll\Domain\Exception\PayrollRunStatusException;
use App\Payroll\Domain\Repository\PayrollRunRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Event\EventPublisherInterface;
use App\Shared\Domain\Time\BusinessDate;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Approves the run, then publishes PayrollRunApproved; Time tracking locks the month's shifts from it
 * in the same request (ADR 0027 §8: the gap between the two is accepted).
 */
final readonly class ApprovePayrollRunHandler implements CommandHandlerInterface
{
    public function __construct(
        private PayrollRunRepositoryInterface $runs,
        private PayrollSourcesLoader $sources,
        private QueryBusInterface $queryBus,
        private EventPublisherInterface $events,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException    when the actor is not an active member of the organization
     * @throws PayrollAccessForbiddenException  when the actor is not an owner
     * @throws PayrollRunNotFoundException      when the run is not the organization's
     * @throws PayrollRunStatusException        when the run is approved already
     * @throws PayrollRunNotApprovableException when a rule of ADR 0027 §7 is not met
     */
    public function __invoke(ApprovePayrollRunCommand $command): void
    {
        $actor = PayrollActor::owner($this->queryBus, $command->organizationId, $command->actorId);
        $run = (Uuid::isValid($command->payrollRunId) ? $this->runs->findById(Uuid::fromString($command->payrollRunId)) : null)
            ?? throw PayrollRunNotFoundException::forId($command->payrollRunId);
        $organization = $actor->organization($this->queryBus);
        $now = $this->clock->now();

        $run->approve(
            $this->sources->load($run->month(), $organization),
            BusinessDate::containing($now, new \DateTimeZone($organization->timezone)),
            $actor->id,
            $actor->role,
            $now,
        );
        $this->runs->save($run);

        $this->events->publish(new PayrollRunApproved(
            $command->organizationId,
            $run->getId()->toRfc4122(),
            $run->month()->first->toString(),
            $run->month()->last->toString(),
            $actor->id->toRfc4122(),
        ));
    }
}
