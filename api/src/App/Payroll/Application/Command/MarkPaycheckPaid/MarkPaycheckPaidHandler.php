<?php

declare(strict_types=1);

namespace App\Payroll\Application\Command\MarkPaycheckPaid;

use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Payroll\Application\PayrollActor;
use App\Payroll\Domain\Exception\PaycheckNotFoundException;
use App\Payroll\Domain\Exception\PaycheckPaymentException;
use App\Payroll\Domain\Exception\PaymentDateInTheFutureException;
use App\Payroll\Domain\Exception\PayrollAccessForbiddenException;
use App\Payroll\Domain\Exception\PayrollRunNotFoundException;
use App\Payroll\Domain\Exception\PayrollRunStatusException;
use App\Payroll\Domain\Model\PaymentMethod;
use App\Payroll\Domain\Repository\PayrollRunRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\Exception\InvalidBusinessDateException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final readonly class MarkPaycheckPaidHandler implements CommandHandlerInterface
{
    public function __construct(
        private PayrollRunRepositoryInterface $runs,
        private QueryBusInterface $queryBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException   when the actor is not an active member of the organization
     * @throws PayrollAccessForbiddenException when the actor is not an owner
     * @throws PayrollRunNotFoundException     when the run is not the organization's
     * @throws PaycheckNotFoundException       when the paycheck is not on the run
     * @throws PayrollRunStatusException       when the run is a draft
     * @throws PaycheckPaymentException        when the paycheck is marked paid already
     * @throws PaymentDateInTheFutureException when the date is after today
     * @throws InvalidBusinessDateException    when the date is not YYYY-MM-DD (the Input checks it)
     */
    public function __invoke(MarkPaycheckPaidCommand $command): void
    {
        $actor = PayrollActor::payer($this->queryBus, $command->organizationId, $command->actorId);
        $run = (Uuid::isValid($command->payrollRunId) ? $this->runs->findById(Uuid::fromString($command->payrollRunId)) : null)
            ?? throw PayrollRunNotFoundException::forId($command->payrollRunId);
        if (!Uuid::isValid($command->paycheckId)) {
            throw PaycheckNotFoundException::forId($command->paycheckId);
        }
        $organization = $actor->organization($this->queryBus);
        $now = $this->clock->now();

        $run->markPaycheckPaid(
            Uuid::fromString($command->paycheckId),
            BusinessDate::fromString($command->paidOn),
            PaymentMethod::from($command->method),
            BusinessDate::containing($now, new \DateTimeZone($organization->timezone)),
            $actor->id,
            $actor->role,
            $now,
        );
        $this->runs->save($run);
    }
}
