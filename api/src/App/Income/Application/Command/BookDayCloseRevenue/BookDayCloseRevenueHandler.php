<?php

declare(strict_types=1);

namespace App\Income\Application\Command\BookDayCloseRevenue;

use App\Income\Application\RevenueEntryHistory;
use App\Income\Domain\Exception\InvalidPaymentLinesException;
use App\Income\Domain\Exception\RevenueMonthClosedException;
use App\Income\Domain\Model\RevenueEntrySnapshot;
use App\Income\Domain\Repository\RevenueMonthRepositoryInterface;
use App\Income\Domain\Exception\RegisterNotFoundException;
use App\Income\Domain\Exception\RevenueEntryAlreadyExistsException;
use App\Income\Domain\Model\PaymentAmount;
use App\Income\Domain\Model\RevenueEntry;
use App\Income\Domain\Repository\PaymentMethodRepositoryInterface;
use App\Income\Domain\Repository\RegisterRepositoryInterface;
use App\Income\Domain\Repository\RevenueEntryRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\Time\BusinessDate;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The payment lines a close writes (ADR 0033 §9): the active method marked as cash (ADR 0030, #184)
 * gets max(0, cash income), and each non-cash method its amount from the close; with no active cash
 * method only the non-cash lines are booked. The entry is, in order:
 *
 * 1. the close's own entry (the close recorded again, or corrected): its lines are replaced; if the
 *    correction moved it to another register, it is removed and the new register's entry is booked;
 * 2. a manual entry of the register and date, which the close takes over;
 * 3. a new entry.
 *
 * Cash flow asked Income up front (ListBookableRegistersQuery, ListBookablePaymentMethodsQuery)
 * before it saved the close, so the register and the methods were active then.
 */
final readonly class BookDayCloseRevenueHandler implements CommandHandlerInterface
{
    public function __construct(
        private RevenueEntryRepositoryInterface $entries,
        private RegisterRepositoryInterface $registers,
        private PaymentMethodRepositoryInterface $paymentMethods,
        private RevenueMonthRepositoryInterface $months,
        private RevenueEntryHistory $history,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws RevenueMonthClosedException        when the date is in a closed month (Cash flow refuses it up front; ADR 0034 §3)
     * @throws RegisterNotFoundException          when the register is not one of the organization's
     * @throws InvalidPaymentLinesException       when a method is not one of the organization's, or no line is left
     * @throws RevenueEntryAlreadyExistsException when a manual entry of the register and date was recorded concurrently
     */
    public function __invoke(BookDayCloseRevenueCommand $command): void
    {
        $dayCloseId = Uuid::fromString($command->dayCloseId);
        $actorId = Uuid::fromString($command->actorId);
        $date = BusinessDate::fromString($command->businessDate);
        $this->months->locks()->assertOpen($date);
        $register = $this->registers->findById(Uuid::fromString($command->registerId))
            ?? throw RegisterNotFoundException::withId($command->registerId);
        $payments = $this->payments($command);
        $now = $this->clock->now();

        $entry = $this->entries->findByDayCloseId($dayCloseId);
        if (null !== $entry && !$entry->getRegister()->getId()->equals($register->getId())) {
            $this->entries->remove($entry);
            $this->history->deleted($entry, $dayCloseId, $actorId, $now);
            $entry = null;
        }
        $entry ??= $this->entries->findByRegisterAndDate($register->getId(), $date);

        if (null !== $entry) {
            $before = RevenueEntrySnapshot::of($entry);
            $takeover = null === $entry->getDayCloseId();
            $entry->bookDayClose($dayCloseId, $payments, $actorId, $now);
            $this->entries->save($entry);
            $this->history->booked($before, $entry, $dayCloseId, $actorId, $now, $takeover);

            return;
        }

        $entry = RevenueEntry::bookFromDayClose(
            Uuid::fromString($command->newEntryId),
            $dayCloseId,
            $register,
            $date,
            $command->currency,
            $payments,
            $actorId,
            $now,
        );
        $this->entries->add($entry);
        $this->history->booked(null, $entry, $dayCloseId, $actorId, $now);
    }

    /**
     * @return list<PaymentAmount>
     *
     * @throws InvalidPaymentLinesException when a method is not one of the organization's
     */
    private function payments(BookDayCloseRevenueCommand $command): array
    {
        $payments = [];
        $cash = $this->paymentMethods->findActiveCash();
        if (null !== $cash) {
            $payments[] = new PaymentAmount($cash, max(0, $command->cashIncome));
        }
        foreach ($command->nonCashAmounts as $methodId => $amount) {
            $methodId = (string) $methodId;
            $method = (Uuid::isValid($methodId) ? $this->paymentMethods->findById(Uuid::fromString($methodId)) : null)
                ?? throw InvalidPaymentLinesException::unknownMethod($methodId);
            $payments[] = new PaymentAmount($method, $amount);
        }

        return $payments;
    }
}
