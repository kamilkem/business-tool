<?php

declare(strict_types=1);

namespace App\Tests\Unit\Income\Application;

use App\CashFlow\Domain\Event\DayCloseRecorded;
use App\Income\Application\Command\BookDayCloseRevenue\BookDayCloseRevenueCommand;
use App\Income\Application\Command\BookDayCloseRevenue\BookDayCloseRevenueHandler;
use App\Income\Application\EventHandler\BookRevenueOnDayCloseRecorded;
use App\Income\Domain\Exception\InvalidPaymentLinesException;
use App\Income\Domain\Model\PaymentAmount;
use App\Income\Domain\Model\Register;
use App\Income\Domain\Model\RevenueEntry;
use App\Income\Domain\Model\VatAmount;
use App\Organization\Domain\Model\MembershipRole;
use App\Tests\Unit\Shared\Application\RecordingCommandBus;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Uid\Uuid;

/** The employee closes the day; the close books the register "Bar" for 2026-11-11 (ADR 0033 §9). */
#[CoversClass(BookDayCloseRevenueHandler::class)]
#[CoversClass(BookRevenueOnDayCloseRecorded::class)]
final class BookDayCloseRevenueHandlerTest extends IncomeHandlerTestCase
{
    private const string DATE = '2026-11-11';

    private string $closeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->closeId = Uuid::v7()->toRfc4122();
    }

    public function test_it_books_a_new_entry_as_the_employee_who_closed(): void
    {
        $entryId = Uuid::v7()->toRfc4122();

        $this->book(cashIncome: 85_000, nonCash: [$this->card->getId()->toRfc4122() => 40_000, $this->transfer->getId()->toRfc4122() => 0], entryId: $entryId);

        $entry = $this->bookedEntry();
        self::assertSame($entryId, $entry->getId()->toRfc4122());
        self::assertSame($this->closeId, $entry->getDayCloseId()?->toRfc4122());
        self::assertSame(self::DATE, $entry->getBusinessDate()->toString());
        self::assertSame('PLN', $entry->getCurrency());
        self::assertEquals([new PaymentAmount($this->cash, 85_000), new PaymentAmount($this->card, 40_000), new PaymentAmount($this->transfer, 0)], $entry->getPaymentLines());
        self::assertTrue($this->employee->getId()->equals($entry->getCreatedBy()));
        self::assertEquals(new \DateTimeImmutable(self::NOW), $entry->getCreatedAt());
    }

    public function test_it_books_a_shortage_as_no_cash(): void
    {
        $this->book(cashIncome: -2_500);

        self::assertEquals([new PaymentAmount($this->cash, 0), new PaymentAmount($this->card, 10_000)], $this->bookedEntry()->getPaymentLines());
    }

    public function test_it_books_only_the_non_cash_lines_without_an_active_cash_method(): void
    {
        $this->cash->deactivate($this->owner->getId(), MembershipRole::Owner, new \DateTimeImmutable('2026-11-01'));

        $this->book(cashIncome: 50_000);

        self::assertEquals([new PaymentAmount($this->card, 10_000)], $this->bookedEntry()->getPaymentLines());
    }

    public function test_it_takes_over_a_manual_entry_of_the_register_and_date(): void
    {
        $manual = $this->recorded(self::DATE, cash: 70_000);
        $manual->change([new PaymentAmount($this->cash, 70_000), new PaymentAmount($this->voucher, 3_000)], [new VatAmount('23', 70_000, 13_089)], 'By hand', 'PL', $this->owner->getId(), MembershipRole::Owner, new \DateTimeImmutable('2026-11-12 09:00:00'));

        $this->book(cashIncome: 72_000);

        self::assertCount(1, $this->entries->all());
        self::assertSame($manual, $this->bookedEntry());
        self::assertEquals([new PaymentAmount($this->cash, 72_000), new PaymentAmount($this->card, 10_000)], $manual->getPaymentLines());
        self::assertEquals([new VatAmount('23', 70_000, 13_089)], $manual->getVatLines());
        self::assertSame('By hand', $manual->getNote());
        self::assertTrue($this->owner->getId()->equals($manual->getCreatedBy()));
        self::assertTrue($this->employee->getId()->equals($manual->getUpdatedBy() ?? Uuid::v7()));
        self::assertEquals(new \DateTimeImmutable(self::NOW), $manual->getUpdatedAt());
    }

    public function test_it_books_the_same_close_again_into_its_entry_keeping_the_vat_a_manager_added(): void
    {
        $this->book(cashIncome: 10_000);
        $entry = $this->bookedEntry();
        $entry->change($entry->getPaymentLines(), [new VatAmount('23', 20_000, 3_740)], null, 'PL', $this->manager->getId(), MembershipRole::Manager, new \DateTimeImmutable(self::NOW));

        $this->book(cashIncome: 12_000, actor: $this->manager->getId()->toRfc4122());

        self::assertCount(1, $this->entries->all());
        self::assertSame(22_000, $entry->getTotal());
        self::assertEquals([new VatAmount('23', 20_000, 3_740)], $entry->getVatLines());
    }

    public function test_it_moves_the_entry_when_a_correction_changes_the_register(): void
    {
        $this->book(cashIncome: 10_000);
        $kitchen = Register::create(Uuid::v7(), 'Kitchen', null, $this->owner->getId(), MembershipRole::Owner, new \DateTimeImmutable('2026-01-05'));
        $this->registers->add($kitchen);

        $this->book(cashIncome: 10_000, registerId: $kitchen->getId()->toRfc4122());

        self::assertCount(1, $this->entries->all());
        self::assertSame($kitchen, $this->bookedEntry()->getRegister());
    }

    public function test_it_refuses_a_payment_method_that_is_not_the_organizations(): void
    {
        $this->expectException(InvalidPaymentLinesException::class);

        $this->book(nonCash: [Uuid::v7()->toRfc4122() => 1]);
    }

    public function test_it_sends_the_booking_command_for_a_recorded_close(): void
    {
        $bus = new RecordingCommandBus();
        $nonCash = [$this->card->getId()->toRfc4122() => 4_000];

        (new BookRevenueOnDayCloseRecorded($bus))(new DayCloseRecorded($this->organizationId(), $this->closeId, $this->bar->getId()->toRfc4122(), self::DATE, 'PLN', -300, $nonCash, $this->employee->getId()->toRfc4122()));

        $commands = $bus->commandsOf(BookDayCloseRevenueCommand::class);
        self::assertCount(1, $commands);
        self::assertSame([$this->organizationId(), $this->employee->getId()->toRfc4122(), $this->closeId, $this->bar->getId()->toRfc4122(), self::DATE, 'PLN', -300, $nonCash], [
            $commands[0]->organizationId, $commands[0]->actorId, $commands[0]->dayCloseId, $commands[0]->registerId, $commands[0]->businessDate, $commands[0]->currency, $commands[0]->cashIncome, $commands[0]->nonCashAmounts,
        ]);
        self::assertTrue(Uuid::isValid($commands[0]->newEntryId));
    }

    /** @param array<string, int>|null $nonCash */
    private function book(int $cashIncome = 0, ?array $nonCash = null, ?string $entryId = null, ?string $actor = null, ?string $registerId = null): void
    {
        (new BookDayCloseRevenueHandler($this->entries, $this->registers, $this->paymentMethods, $this->clock))(new BookDayCloseRevenueCommand(
            $this->organizationId(),
            $actor ?? $this->employee->getId()->toRfc4122(),
            $this->closeId,
            $entryId ?? Uuid::v7()->toRfc4122(),
            $registerId ?? $this->bar->getId()->toRfc4122(),
            self::DATE,
            'PLN',
            $cashIncome,
            $nonCash ?? [$this->card->getId()->toRfc4122() => 10_000],
        ));
    }

    private function bookedEntry(): RevenueEntry
    {
        return $this->entries->findByDayCloseId(Uuid::fromString($this->closeId)) ?? self::fail('No entry was booked.');
    }
}
