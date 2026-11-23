<?php

declare(strict_types=1);

namespace App\Tests\Unit\Income\Application;

use App\Income\Application\Command\BookDayCloseRevenue\BookDayCloseRevenueCommand;
use App\Income\Application\Command\BookDayCloseRevenue\BookDayCloseRevenueHandler;
use App\Income\Application\Command\ChangeRevenueEntry\ChangeRevenueEntryCommand;
use App\Income\Application\Command\ChangeRevenueEntry\ChangeRevenueEntryHandler;
use App\Income\Application\Command\CloseRevenueMonth\CloseRevenueMonthCommand;
use App\Income\Application\Command\CloseRevenueMonth\CloseRevenueMonthHandler;
use App\Income\Application\Command\DeleteRevenueEntry\DeleteRevenueEntryCommand;
use App\Income\Application\Command\DeleteRevenueEntry\DeleteRevenueEntryHandler;
use App\Income\Application\Command\RecordRevenueEntry\RecordRevenueEntryCommand;
use App\Income\Application\Command\RecordRevenueEntry\RecordRevenueEntryHandler;
use App\Income\Application\Command\RemoveDayCloseRevenue\RemoveDayCloseRevenueCommand;
use App\Income\Application\Command\RemoveDayCloseRevenue\RemoveDayCloseRevenueHandler;
use App\Income\Application\Command\ReopenRevenueMonth\ReopenRevenueMonthCommand;
use App\Income\Application\Command\ReopenRevenueMonth\ReopenRevenueMonthHandler;
use App\Income\Application\Query\GetRevenueEntry\GetRevenueEntryHandler;
use App\Income\Application\Query\GetRevenueEntry\GetRevenueEntryQuery;
use App\Income\Application\Query\GetRevenueEntry\RevenueEntryView;
use App\Income\Application\Query\GetRevenueMonth\GetRevenueMonthHandler;
use App\Income\Application\Query\GetRevenueMonth\GetRevenueMonthQuery;
use App\Income\Application\Query\GetRevenueMonth\RevenueMonthActionView;
use App\Income\Application\Query\GetRevenueMonth\RevenueMonthView;
use App\Income\Application\Query\ListClosedRevenueMonths\ListClosedRevenueMonthsHandler;
use App\Income\Application\Query\ListClosedRevenueMonths\ListClosedRevenueMonthsQuery;
use App\Income\Application\Query\ListRevenueEntryChanges\ListRevenueEntryChangesHandler;
use App\Income\Application\Query\ListRevenueEntryChanges\ListRevenueEntryChangesQuery;
use App\Income\Application\Query\ListRevenueEntryChanges\RevenueEntryChangeView;
use App\Income\Domain\Exception\IncomeManagementForbiddenException;
use App\Income\Domain\Exception\RevenueMonthAlreadyClosedException;
use App\Income\Domain\Exception\RevenueMonthClosedException;
use App\Income\Domain\Exception\RevenueMonthCloseForbiddenException;
use App\Income\Domain\Exception\RevenueMonthNotClosedException;
use App\Income\Domain\Exception\RevenueMonthNotEndedException;
use App\Income\Domain\Exception\RevenuePeriodTooLongException;
use App\Income\Domain\Model\VatAmount;
use App\Organization\Domain\Model\MembershipRole;
use App\Shared\Domain\Time\BusinessDate;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use App\Income\Domain\Model\RevenueEntryChange;
use App\Income\Domain\Model\RevenueEntrySnapshot;
use App\User\Domain\Model\User;
use Symfony\Component\Uid\Uuid;

/**
 * Closing and reopening an Income month, the lock it puts on the month's entries, and the change
 * history of entries (ADR 0034). Today is 13 November 2026 in Warsaw: October has ended.
 */
final class RevenueMonthHandlersTest extends IncomeHandlerTestCase
{
    public function test_it_lets_an_owner_close_an_ended_month_and_shows_it(): void
    {
        $this->close($this->owner, '2026-10');

        $view = $this->month($this->manager, '2026-10');
        self::assertEquals(new RevenueMonthView(
            '2026-10',
            '2026-10-01',
            '2026-10-31',
            true,
            true,
            $this->owner->getId()->toRfc4122(),
            new \DateTimeImmutable(self::NOW),
            [new RevenueMonthActionView('closed', $this->owner->getId()->toRfc4122(), new \DateTimeImmutable(self::NOW))],
        ), $view);
        self::assertTrue((new ListClosedRevenueMonthsHandler($this->months))(new ListClosedRevenueMonthsQuery())->isClosed(BusinessDate::fromString('2026-10-31')));
    }

    public function test_it_shows_a_month_never_closed_as_open_and_whether_it_has_ended(): void
    {
        $view = $this->month($this->owner, '2026-11');

        self::assertNotNull($view);
        self::assertFalse($view->ended);
        self::assertFalse($view->closed);
        self::assertSame([], $view->actions);
        self::assertTrue($this->month($this->owner, '2026-10')?->ended);
    }

    public function test_it_reopens_and_closes_again_and_keeps_every_action(): void
    {
        $this->close($this->owner, '2026-10');
        $this->reopen($this->owner, '2026-10');
        self::assertFalse($this->month($this->owner, '2026-10')?->closed);

        $this->close($this->owner, '2026-10');
        self::assertSame(['closed', 'reopened', 'closed'], array_map(static fn (RevenueMonthActionView $action): string => $action->action, $this->month($this->owner, '2026-10')->actions ?? []));
    }

    public function test_it_refuses_a_close_or_reopen_that_does_not_fit(): void
    {
        $this->expectThrown(RevenueMonthNotEndedException::class, fn () => $this->close($this->owner, '2026-11'));
        $this->expectThrown(RevenueMonthCloseForbiddenException::class, fn () => $this->close($this->manager, '2026-10'));
        $this->expectThrown(IncomeManagementForbiddenException::class, fn () => $this->close($this->employee, '2026-10'));
        $this->expectThrown(RevenueMonthNotClosedException::class, fn () => $this->reopen($this->owner, '2026-10'));
        $this->expectThrown(RevenueMonthCloseForbiddenException::class, fn () => $this->reopen($this->manager, '2026-10'));

        $this->close($this->owner, '2026-10');
        $this->expectThrown(RevenueMonthAlreadyClosedException::class, fn () => $this->close($this->owner, '2026-10'));
        $this->expectThrown(RevenueMonthCloseForbiddenException::class, fn () => $this->reopen($this->manager, '2026-10'));
        $this->expectThrown(IncomeManagementForbiddenException::class, fn () => $this->month($this->employee, '2026-10'));
    }

    public function test_it_locks_every_write_of_an_entry_in_a_closed_month(): void
    {
        $entry = $this->recorded('2026-10-20');
        $entryId = $entry->getId()->toRfc4122();
        $this->closeMonth('2026-10');

        $this->expectThrown(RevenueMonthClosedException::class, fn () => $this->record($this->owner, '2026-10-21'));
        $this->expectThrown(RevenueMonthClosedException::class, fn () => $this->change($this->owner, $entryId, 50_000));
        // The same values: still refused, nothing about a closed month changes.
        $this->expectThrown(RevenueMonthClosedException::class, fn () => $this->change($this->owner, $entryId, 100_000, note: null));
        $this->expectThrown(RevenueMonthClosedException::class, fn () => $this->delete($this->owner, $entryId));
        $this->expectThrown(RevenueMonthClosedException::class, fn () => $this->book('2026-10-20', Uuid::v7()->toRfc4122()));
        self::assertSame(100_000, $entry->getTotal());
        self::assertTrue($this->get($entryId)?->monthClosed);

        // November is open.
        $this->record($this->owner, '2026-11-02');
        self::assertSame([], array_filter($this->changes->all(), static fn (RevenueEntryChange $change): bool => '2026-10-20' === $change->getBusinessDate()->toString()));
    }

    public function test_it_unlocks_the_entries_when_the_month_is_reopened(): void
    {
        $entry = $this->recorded('2026-10-20');
        $this->closeMonth('2026-10');
        $this->reopen($this->owner, '2026-10');

        $this->change($this->manager, $entry->getId()->toRfc4122(), 90_000);

        self::assertSame(90_000, $entry->getTotal());
        self::assertFalse($this->get($entry->getId()->toRfc4122())?->monthClosed);
    }

    public function test_it_keeps_the_entry_booked_by_a_close_when_its_month_is_closed(): void
    {
        $closeId = Uuid::v7()->toRfc4122();
        $this->book('2026-10-20', $closeId);
        $this->closeMonth('2026-10');

        $this->expectThrown(RevenueMonthClosedException::class, fn () => $this->removeBooking($closeId));
        self::assertNotNull($this->entries->findByDayCloseId(Uuid::fromString($closeId)));
    }

    public function test_it_records_who_created_changed_and_deleted_an_entry_with_before_and_after(): void
    {
        $entryId = $this->record($this->manager, '2026-11-02');
        $this->clock->modify('+1 hour');
        $this->change($this->owner, $entryId, 120_000, note: 'Recount');
        // Nothing changed: no row.
        $this->change($this->owner, $entryId, 120_000, note: 'Recount');
        $this->clock->modify('+1 hour');
        $this->delete($this->manager, $entryId);

        $history = $this->history($this->owner, entryId: $entryId);

        self::assertSame(['deleted', 'changed', 'created'], array_map(static fn (RevenueEntryChangeView $change): string => $change->kind, $history ?? []));
        [$deleted, $changed, $created] = $history ?? [];
        self::assertNull($created->before);
        self::assertSame($this->manager->getId()->toRfc4122(), $created->changedBy);
        self::assertEquals(new RevenueEntrySnapshot([['paymentMethodId' => $this->cash->getId()->toRfc4122(), 'paymentMethodName' => 'Cash', 'amount' => 100_000]], 100_000, [], null), $created->after);
        self::assertNotNull($changed->before);
        self::assertNotNull($changed->after);
        self::assertSame(100_000, $changed->before->total);
        self::assertSame(120_000, $changed->after->total);
        self::assertSame('Recount', $changed->after->note);
        self::assertSame($this->owner->getId()->toRfc4122(), $changed->changedBy);
        self::assertEquals(new \DateTimeImmutable(self::NOW.' +1 hour'), $changed->changedAt);
        self::assertSame(120_000, $deleted->before?->total);
        self::assertNull($deleted->after);
        self::assertSame(['Bar', '2026-11-02', 'PLN', null], [$deleted->registerName, $deleted->businessDate, $deleted->currency, $deleted->dayCloseId]);
    }

    public function test_it_records_what_a_close_booked_took_over_and_removed(): void
    {
        $manual = $this->record($this->owner, '2026-11-03');
        $closeId = Uuid::v7()->toRfc4122();
        $this->book('2026-11-03', $closeId, card: 10_000);
        // The same lines again (a recalculation that changed nothing Income books): no row.
        $this->book('2026-11-03', $closeId, card: 10_000);
        $this->book('2026-11-03', $closeId, card: 12_000);
        $this->removeBooking($closeId);

        $history = $this->history($this->owner, entryId: $manual) ?? [];

        self::assertSame(['deleted', 'booked', 'booked', 'created'], array_map(static fn (RevenueEntryChangeView $change): string => $change->kind, $history));
        self::assertSame([$closeId, $closeId, $closeId, null], array_map(static fn (RevenueEntryChangeView $change): ?string => $change->dayCloseId, $history));
        self::assertSame($this->employee->getId()->toRfc4122(), $history[1]->changedBy);
        self::assertSame([100_000, 10_000], [$history[2]->before?->total, $history[2]->after?->total]);
    }

    public function test_it_lists_the_changes_of_the_entries_dated_in_a_period_deleted_ones_included(): void
    {
        $kept = $this->record($this->owner, '2026-11-02');
        $deleted = $this->record($this->owner, '2026-11-05');
        $this->delete($this->owner, $deleted);
        $this->record($this->owner, '2026-10-30');

        $changes = $this->history($this->manager, from: '2026-11-01', to: '2026-11-30') ?? [];

        self::assertSame([[$deleted, 'deleted'], [$deleted, 'created'], [$kept, 'created']], array_map(static fn (RevenueEntryChangeView $change): array => [$change->entryId, $change->kind], $changes));
        self::assertSame([], $this->history($this->manager, entryId: 'not-a-uuid'));
        self::assertNull($this->history($this->formerMember(), from: '2026-11-01', to: '2026-11-30'));
        $this->expectThrown(IncomeManagementForbiddenException::class, fn () => $this->history($this->employee, from: '2026-11-01', to: '2026-11-30'));
        $this->expectThrown(RevenuePeriodTooLongException::class, fn () => $this->history($this->owner, from: '2026-01-01', to: '2026-11-30'));
    }

    private function close(User $actor, string $month): void
    {
        (new CloseRevenueMonthHandler($this->months, $this->queryBus, $this->clock))(new CloseRevenueMonthCommand($this->organizationId(), $actor->getId()->toRfc4122(), $month, Uuid::v7()->toRfc4122()));
    }

    private function reopen(User $actor, string $month): void
    {
        (new ReopenRevenueMonthHandler($this->months, $this->queryBus, $this->clock))(new ReopenRevenueMonthCommand($this->organizationId(), $actor->getId()->toRfc4122(), $month));
    }

    private function month(User $actor, string $month): ?RevenueMonthView
    {
        return (new GetRevenueMonthHandler($this->months, $this->queryBus, $this->clock))(new GetRevenueMonthQuery($this->organizationId(), $actor->getId()->toRfc4122(), $month));
    }

    private function get(string $entryId): ?RevenueEntryView
    {
        return (new GetRevenueEntryHandler($this->entries, $this->months, $this->queryBus))(new GetRevenueEntryQuery($this->organizationId(), $this->owner->getId()->toRfc4122(), $entryId));
    }

    /** @return list<RevenueEntryChangeView>|null */
    private function history(User $actor, ?string $entryId = null, ?string $from = null, ?string $to = null): ?array
    {
        return (new ListRevenueEntryChangesHandler($this->changes, $this->queryBus))(new ListRevenueEntryChangesQuery($this->organizationId(), $actor->getId()->toRfc4122(), $entryId, $from, $to));
    }

    private function record(User $actor, string $date): string
    {
        $id = Uuid::v7()->toRfc4122();
        (new RecordRevenueEntryHandler($this->entries, $this->paymentMethods, $this->registers, $this->months, $this->history, $this->queryBus, $this->clock))(
            new RecordRevenueEntryCommand($this->organizationId(), $actor->getId()->toRfc4122(), $id, $this->bar->getId()->toRfc4122(), $date, [self::line($this->cash, 100_000)], [], null),
        );

        return $id;
    }

    private function change(User $actor, string $entryId, int $cash, ?string $note = null): void
    {
        $vat = $this->vatOf($entryId);
        (new ChangeRevenueEntryHandler($this->entries, $this->paymentMethods, $this->months, $this->history, $this->queryBus, $this->clock))(
            new ChangeRevenueEntryCommand($this->organizationId(), $actor->getId()->toRfc4122(), $entryId, [self::line($this->cash, $cash)], $vat, $note),
        );
    }

    /** @return list<array{rate: string, gross: int, vat: int}> the entry's VAT lines as they are */
    private function vatOf(string $entryId): array
    {
        $entry = $this->entries->findById(Uuid::fromString($entryId));

        return null === $entry ? [] : array_map(static fn (VatAmount $line): array => ['rate' => $line->rate, 'gross' => $line->gross, 'vat' => $line->vat], $entry->getVatLines());
    }

    private function delete(User $actor, string $entryId): void
    {
        (new DeleteRevenueEntryHandler($this->entries, $this->queryBus, $this->months, $this->history, $this->clock))(
            new DeleteRevenueEntryCommand($this->organizationId(), $actor->getId()->toRfc4122(), $entryId),
        );
    }

    private function book(string $date, string $closeId, int $card = 10_000): void
    {
        (new BookDayCloseRevenueHandler($this->entries, $this->registers, $this->paymentMethods, $this->months, $this->history, $this->clock))(new BookDayCloseRevenueCommand(
            $this->organizationId(),
            $this->employee->getId()->toRfc4122(),
            $closeId,
            Uuid::v7()->toRfc4122(),
            $this->bar->getId()->toRfc4122(),
            $date,
            'PLN',
            0,
            [$this->card->getId()->toRfc4122() => $card],
        ));
    }

    private function removeBooking(string $closeId): void
    {
        (new RemoveDayCloseRevenueHandler($this->entries, $this->months, $this->history, $this->clock))(new RemoveDayCloseRevenueCommand($this->organizationId(), $this->manager->getId()->toRfc4122(), $closeId));
    }

    private function formerMember(): User
    {
        $former = UserMother::any('fred@example.com');
        OrganizationMother::addMember($this->organization, $former, MembershipRole::Manager, new \DateTimeImmutable('2026-01-05'));
        OrganizationMother::deactivateMember($this->organization, $former, new \DateTimeImmutable('2026-11-01'));

        return $former;
    }

    /**
     * @param class-string<\Throwable> $exception
     * @param \Closure(): mixed        $call
     */
    private function expectThrown(string $exception, \Closure $call): void
    {
        try {
            $call();
            self::fail(\sprintf('%s expected.', $exception));
        } catch (\Throwable $e) {
            self::assertInstanceOf($exception, $e, $e->getMessage());
        }
    }
}
