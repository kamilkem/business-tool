<?php

declare(strict_types=1);

namespace App\Income\Application\Command\RecordRevenueEntry;

use App\Income\Application\IncomeActor;
use App\Income\Application\RevenueLines;
use App\Income\Domain\Exception\IncomeManagementForbiddenException;
use App\Income\Domain\Exception\InvalidPaymentLinesException;
use App\Income\Domain\Exception\InvalidVatLinesException;
use App\Income\Domain\Exception\RegisterDeactivatedException;
use App\Income\Domain\Exception\RegisterNotFoundException;
use App\Income\Domain\Exception\RevenueEntryAlreadyExistsException;
use App\Income\Domain\Exception\RevenueEntryInTheFutureException;
use App\Income\Domain\Model\RevenueEntry;
use App\Income\Domain\Repository\RegisterRepositoryInterface;
use App\Income\Domain\Repository\PaymentMethodRepositoryInterface;
use App\Income\Domain\Repository\RevenueEntryRepositoryInterface;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\Exception\InvalidBusinessDateException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The currency and the country come from the organization, never from the client; "today" is in
 * its time zone (ADR 0017). What makes an entry valid is RevenueEntry's rule.
 */
final readonly class RecordRevenueEntryHandler implements CommandHandlerInterface
{
    public function __construct(
        private RevenueEntryRepositoryInterface $entries,
        private PaymentMethodRepositoryInterface $paymentMethods,
        private RegisterRepositoryInterface $registers,
        private QueryBusInterface $queryBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException      when the actor is not an active member of the organization
     * @throws IncomeManagementForbiddenException when the actor is not an owner or manager
     * @throws RegisterNotFoundException          when the register is not one of the organization's
     * @throws InvalidBusinessDateException       when the business date is not a YYYY-MM-DD calendar date
     * @throws RegisterDeactivatedException       when the register is deactivated
     * @throws RevenueEntryInTheFutureException   when the business date is after today
     * @throws InvalidPaymentLinesException       when the payment lines break a rule
     * @throws InvalidVatLinesException           when the VAT lines break a rule
     * @throws RevenueEntryAlreadyExistsException when the register already has an entry for the date
     */
    public function __invoke(RecordRevenueEntryCommand $command): void
    {
        $actor = IncomeActor::resolve($this->queryBus, $command->organizationId, $command->actorId);

        $register = Uuid::isValid($command->registerId) ? $this->registers->findById(Uuid::fromString($command->registerId)) : null;
        $register ??= throw RegisterNotFoundException::withId($command->registerId);

        $now = $this->clock->now();
        $entry = RevenueEntry::record(
            Uuid::fromString($command->entryId),
            $register,
            BusinessDate::fromString($command->businessDate),
            $actor->organization->currency,
            $actor->organization->countryCode,
            RevenueLines::payments($command->payments, $this->paymentMethods),
            RevenueLines::vat($command->vatLines),
            $command->note,
            $actor->id,
            $actor->role,
            $actor->today($this->clock),
            $now,
        );

        // A clean 409 before the insert; the unique index catches a concurrent one (add()).
        if (null !== $this->entries->findByRegisterAndDate($register->getId(), $entry->getBusinessDate())) {
            throw RevenueEntryAlreadyExistsException::for($register->getId()->toRfc4122(), $entry->getBusinessDate()->toString());
        }

        $this->entries->add($entry);
    }
}
