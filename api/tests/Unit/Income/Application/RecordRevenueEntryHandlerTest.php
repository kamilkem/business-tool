<?php

declare(strict_types=1);

namespace App\Tests\Unit\Income\Application;

use App\Income\Application\Command\RecordRevenueEntry\RecordRevenueEntryCommand;
use App\Income\Application\Command\RecordRevenueEntry\RecordRevenueEntryHandler;
use App\Income\Application\IncomeActor;
use App\Income\Domain\Exception\IncomeManagementForbiddenException;
use App\Income\Domain\Exception\InvalidPaymentLinesException;
use App\Income\Domain\Exception\InvalidVatLinesException;
use App\Income\Domain\Exception\RegisterDeactivatedException;
use App\Income\Domain\Exception\RegisterNotFoundException;
use App\Income\Domain\Exception\RevenueEntryAlreadyExistsException;
use App\Income\Domain\Exception\RevenueEntryInTheFutureException;
use App\Income\Domain\Model\PaymentAmount;
use App\Income\Domain\Model\VatAmount;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Model\MembershipRole;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\PaymentMethodMother;
use App\Tests\Fixtures\UserMother;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\Uuid;

#[CoversClass(RecordRevenueEntryHandler::class)]
#[CoversClass(IncomeActor::class)]
final class RecordRevenueEntryHandlerTest extends IncomeHandlerTestCase
{
    public function test_it_records_an_entry_with_the_organizations_currency_and_vat_rates(): void
    {
        $entryId = $this->handle($this->manager, '2026-11-12', [self::line($this->card, 250_000), self::line($this->cash, 120_050)], [['rate' => '23', 'gross' => 370_050, 'vat' => 69_197]], 'Busy');

        $entry = $this->entries->findById(Uuid::fromString($entryId));
        self::assertNotNull($entry);
        self::assertSame($this->bar, $entry->getRegister());
        self::assertSame('2026-11-12', $entry->getBusinessDate()->toString());
        self::assertSame('PLN', $entry->getCurrency());
        self::assertEquals([new PaymentAmount($this->cash, 120_050), new PaymentAmount($this->card, 250_000)], $entry->getPaymentLines());
        self::assertEquals([new VatAmount('23', 370_050, 69_197)], $entry->getVatLines());
        self::assertSame('Busy', $entry->getNote());
        self::assertTrue($this->manager->getId()->equals($entry->getCreatedBy()));
        self::assertEquals(new \DateTimeImmutable(self::NOW), $entry->getCreatedAt());
    }

    public function test_it_takes_today_in_the_organizations_time_zone(): void
    {
        $this->handle($this->owner, self::TODAY_IN_WARSAW);

        self::assertCount(1, $this->entries->all());

        $this->expectException(RevenueEntryInTheFutureException::class);
        $this->handle($this->owner, '2026-11-14');
    }

    public function test_it_refuses_an_employee_before_looking_at_the_register(): void
    {
        $this->expectException(IncomeManagementForbiddenException::class);

        $this->handle($this->employee, '2026-11-12', registerId: Uuid::v7()->toRfc4122());
    }

    public function test_it_refuses_a_deactivated_manager_as_a_non_member(): void
    {
        OrganizationMother::deactivateMember($this->organization, $this->manager, new \DateTimeImmutable('2026-11-01'));

        $this->expectException(OrganizationNotFoundException::class);

        $this->handle($this->manager, '2026-11-12');
    }

    public function test_it_refuses_someone_who_is_not_a_member(): void
    {
        $this->expectException(OrganizationNotFoundException::class);

        $this->handle(UserMother::any('stranger@example.com'), '2026-11-12');
    }

    /** @return iterable<string, array{string}> */
    public static function unknownRegisters(): iterable
    {
        yield 'another id' => [Uuid::v7()->toRfc4122()];
        yield 'not a UUID' => ['bar'];
    }

    #[DataProvider('unknownRegisters')]
    public function test_it_answers_not_found_for_a_register_that_is_not_the_organizations(string $registerId): void
    {
        $this->expectException(RegisterNotFoundException::class);

        $this->handle($this->owner, '2026-11-12', registerId: $registerId);
    }

    public function test_it_refuses_a_deactivated_register(): void
    {
        $this->bar->deactivate($this->owner->getId(), MembershipRole::Owner, new \DateTimeImmutable('2026-11-01'));

        $this->expectException(RegisterDeactivatedException::class);

        $this->handle($this->owner, '2026-11-12');
    }

    public function test_it_refuses_a_second_entry_for_the_register_and_date(): void
    {
        $this->recorded('2026-11-12');

        $this->expectException(RevenueEntryAlreadyExistsException::class);

        $this->handle($this->owner, '2026-11-12');
    }

    public function test_it_refuses_a_payment_method_that_is_not_the_organizations(): void
    {
        $elsewhere = PaymentMethodMother::named('BLIK');

        try {
            $this->handle($this->owner, '2026-11-12', [self::line($elsewhere, 100)]);
            self::fail('A method of another organization was accepted.');
        } catch (InvalidPaymentLinesException $e) {
            self::assertSame('income.invalid_payment_lines.unknown_method', $e->errorCode());
        }

        $this->expectException(InvalidPaymentLinesException::class);
        $this->handle($this->owner, '2026-11-12', [['paymentMethodId' => 'blik', 'amount' => 100]]);
    }

    public function test_it_refuses_a_deactivated_payment_method(): void
    {
        $this->voucher->deactivate($this->owner->getId(), MembershipRole::Owner, $this->clock->now());

        try {
            $this->handle($this->owner, '2026-11-12', [self::line($this->cash, 100), self::line($this->voucher, 50)]);
            self::fail('A deactivated method was accepted.');
        } catch (InvalidPaymentLinesException $e) {
            self::assertSame('income.invalid_payment_lines.method_deactivated', $e->errorCode());
            self::assertSame(['paymentMethodId' => $this->voucher->getId()->toRfc4122(), 'name' => 'Voucher'], $e->errorParameters());
        }
        self::assertSame([], $this->entries->all());
    }

    public function test_it_refuses_vat_lines_in_a_country_without_rates(): void
    {
        $this->organization->changeSettings($this->owner->getId(), 'Corner Pub', null, 'DE', 'EUR', 'Europe/Berlin');

        try {
            $this->handle($this->owner, '2026-11-12', vatLines: [['rate' => '23', 'gross' => 100, 'vat' => 19]]);
            self::fail('VAT lines were accepted.');
        } catch (InvalidVatLinesException) {
        }

        $this->handle($this->owner, '2026-11-12');
        self::assertSame('EUR', $this->entries->all()[0]->getCurrency());
    }

    /**
     * @param ?list<array{paymentMethodId: string, amount: int}> $payments one cash line when null
     * @param list<array{rate: string, gross: int, vat: int}>           $vatLines
     *
     * @return string the new entry's id
     */
    private function handle(User $actor, string $businessDate, ?array $payments = null, array $vatLines = [], ?string $note = null, ?string $registerId = null): string
    {
        $entryId = Uuid::v7()->toRfc4122();
        $handler = new RecordRevenueEntryHandler($this->entries, $this->paymentMethods, $this->registers, $this->months, $this->history, $this->queryBus, $this->clock);
        $handler(new RecordRevenueEntryCommand(
            $this->organizationId(),
            $actor->getId()->toRfc4122(),
            $entryId,
            $registerId ?? $this->bar->getId()->toRfc4122(),
            $businessDate,
            $payments ?? [self::line($this->cash, 100)],
            $vatLines,
            $note,
        ));

        return $entryId;
    }
}
