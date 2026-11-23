<?php

declare(strict_types=1);

namespace App\Income\Application\Command\ChangeRevenueEntry;

use App\Income\Application\IncomeActor;
use App\Income\Application\RevenueEntryHistory;
use App\Income\Domain\Exception\RevenueMonthClosedException;
use App\Income\Domain\Model\RevenueEntrySnapshot;
use App\Income\Domain\Repository\RevenueMonthRepositoryInterface;
use App\Income\Application\RevenueLines;
use App\Income\Domain\Exception\IncomeManagementForbiddenException;
use App\Income\Domain\Exception\InvalidPaymentLinesException;
use App\Income\Domain\Exception\InvalidVatLinesException;
use App\Income\Domain\Exception\RevenueEntryBookedByDayCloseException;
use App\Income\Domain\Exception\RevenueEntryNotFoundException;
use App\Income\Domain\Repository\PaymentMethodRepositoryInterface;
use App\Income\Domain\Repository\RevenueEntryRepositoryInterface;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** The VAT rates are those of the organization's current country (ADR 0022 §6). */
final readonly class ChangeRevenueEntryHandler implements CommandHandlerInterface
{
    public function __construct(
        private RevenueEntryRepositoryInterface $entries,
        private PaymentMethodRepositoryInterface $paymentMethods,
        private RevenueMonthRepositoryInterface $months,
        private RevenueEntryHistory $history,
        private QueryBusInterface $queryBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException      when the actor is not an active member of the organization
     * @throws IncomeManagementForbiddenException when the actor is not an owner or manager
     * @throws RevenueEntryNotFoundException      when the entry is not one of the organization's
     * @throws RevenueMonthClosedException        when the entry's business date is in a closed month
     * @throws RevenueEntryBookedByDayCloseException when the entry is booked from a close and the payment lines differ
     * @throws InvalidPaymentLinesException       when the payment lines break a rule
     * @throws InvalidVatLinesException           when the VAT lines break a rule
     */
    public function __invoke(ChangeRevenueEntryCommand $command): void
    {
        $actor = IncomeActor::resolve($this->queryBus, $command->organizationId, $command->actorId);

        $entry = Uuid::isValid($command->entryId) ? $this->entries->findById(Uuid::fromString($command->entryId)) : null;
        $entry ??= throw RevenueEntryNotFoundException::withId($command->entryId);
        $this->months->locks()->assertOpen($entry->getBusinessDate());

        $before = RevenueEntrySnapshot::of($entry);
        $now = $this->clock->now();
        $entry->change(
            RevenueLines::payments($command->payments, $this->paymentMethods),
            RevenueLines::vat($command->vatLines),
            $command->note,
            $actor->organization->countryCode,
            $actor->id,
            $actor->role,
            $now,
        );

        $this->entries->save($entry);
        $this->history->changed($before, $entry, $actor->id, $now);
    }
}
