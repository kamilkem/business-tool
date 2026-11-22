<?php

declare(strict_types=1);

namespace App\Tests\Api\Income;

use App\CashFlow\Domain\Model\CashStorage;
use App\CashFlow\Domain\Model\CashStorageMode;
use App\CashFlow\Domain\Model\Denomination;
use App\CashFlow\Domain\Model\DenominationKind;
use App\CashFlow\Domain\Repository\CashStorageRepositoryInterface;
use App\CashFlow\Domain\Repository\DenominationRepositoryInterface;
use App\Income\Domain\Model\Register;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Tests\Fixtures\OrganizationMother;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * An end-of-day close (POST /day-closes) books its register's revenue entry (ADR 0033 §9, #186). The
 * organization is Polish (PLN, Europe/Warsaw) with a counted "Kasetka", the 100 zł note, a register
 * "Bar" and the methods Cash (marked as cash), Card, Transfer and Voucher. The server's time is 01:30
 * in Warsaw on 22 November, so a close is for 21 November.
 */
final class DayCloseRevenueApiTest extends IncomeApiTestCase
{
    private const string NOW = '2026-11-22 00:30:00';
    private const string DATE = '2026-11-21';
    private const array MERGE_PATCH = ['Content-Type' => 'application/merge-patch+json'];

    /** @return iterable<string, array{MembershipRole}> */
    public static function managingRoles(): iterable
    {
        yield 'owner' => [MembershipRole::Owner];
        yield 'manager' => [MembershipRole::Manager];
    }

    public function test_it_books_the_revenue_entry_of_an_employees_close(): void
    {
        [$employee, $organization] = self::memberWithRole(MembershipRole::Employee);
        $cash = self::arrange($organization);

        $close = self::close($employee, $organization, $cash, hundreds: 7, card: 45_00, transfer: 12_00)->toArray();
        self::assertResponseStatusCodeSame(201);

        $entries = self::entries(OrganizationMother::ownerOf($organization), $organization);
        self::assertCount(1, $entries);
        $entry = $entries[0];
        self::assertSame([
            'registerId' => $cash['bar']->getId()->toRfc4122(),
            'businessDate' => self::DATE,
            'currency' => 'PLN',
            'payments' => [self::paid($organization, 'Cash', 700_00), self::paid($organization, 'Card', 45_00), self::paid($organization, 'Transfer', 12_00), self::paid($organization, 'Voucher', 0)],
            'vatLines' => [],
            'paymentsTotal' => 757_00,
            'note' => null,
            'dayCloseId' => $close['id'],
            'createdBy' => $employee->getId()->toRfc4122(),
            'createdAt' => '2026-11-22T00:30:00+00:00',
            'updatedBy' => null,
        ], array_intersect_key($entry, array_flip(['registerId', 'businessDate', 'currency', 'payments', 'vatLines', 'paymentsTotal', 'note', 'dayCloseId', 'createdBy', 'createdAt', 'updatedBy'])));
    }

    public function test_it_books_a_shortage_as_no_cash_and_only_non_cash_lines_without_a_cash_method(): void
    {
        [$employee, $organization] = self::memberWithRole(MembershipRole::Employee);
        $cash = self::arrange($organization, openingHundreds: 5);

        // 3 counted where 5 were expected: a 200 zł shortage.
        self::close($employee, $organization, $cash, hundreds: 3, card: 10_00);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(
            [self::paid($organization, 'Cash', 0), self::paid($organization, 'Card', 10_00), self::paid($organization, 'Transfer', 0), self::paid($organization, 'Voucher', 0)],
            self::entries(OrganizationMother::ownerOf($organization), $organization)[0]['payments'],
        );

        [$other, $withoutCash] = self::memberWithRole(MembershipRole::Employee);
        $theirs = self::arrange($withoutCash);
        self::deactivatePaymentMethod($withoutCash, 'Cash');
        self::close($other, $withoutCash, $theirs, hundreds: 2, card: 5_00);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(
            [self::paid($withoutCash, 'Card', 5_00), self::paid($withoutCash, 'Transfer', 0), self::paid($withoutCash, 'Voucher', 0)],
            self::entries(OrganizationMother::ownerOf($withoutCash), $withoutCash)[0]['payments'],
        );
    }

    public function test_it_takes_over_a_manual_entry_of_the_register_and_date(): void
    {
        [$employee, $organization] = self::memberWithRole(MembershipRole::Employee);
        $owner = OrganizationMother::ownerOf($organization);
        $cash = self::arrange($organization);
        $manual = static::clientFor($owner, $organization)->request('POST', '/api/revenue-entries', ['json' => [
            'registerId' => $cash['bar']->getId()->toRfc4122(),
            'businessDate' => self::DATE,
            'payments' => [self::pay($organization, 'Cash', 650_00), self::pay($organization, 'Voucher', 30_00)],
            'vatLines' => [['rate' => '23', 'gross' => 680_00, 'vat' => 127_15]],
            'note' => 'Typed in from the Z report',
        ]])->toArray();
        self::assertResponseStatusCodeSame(201);

        $close = self::close($employee, $organization, $cash, hundreds: 7, card: 20_00)->toArray();
        self::assertResponseStatusCodeSame(201);

        $entries = self::entries($owner, $organization);
        self::assertCount(1, $entries);
        self::assertSame($manual['id'], $entries[0]['id']);
        self::assertSame($close['id'], $entries[0]['dayCloseId']);
        self::assertSame([self::paid($organization, 'Cash', 700_00), self::paid($organization, 'Card', 20_00), self::paid($organization, 'Transfer', 0), self::paid($organization, 'Voucher', 0)], $entries[0]['payments']);
        self::assertSame([['rate' => '23', 'gross' => 680_00, 'vat' => 127_15, 'net' => 552_85]], $entries[0]['vatLines']);
        self::assertSame('Typed in from the Z report', $entries[0]['note']);
        self::assertSame([$owner->getId()->toRfc4122(), $employee->getId()->toRfc4122(), '2026-11-22T00:30:00+00:00'], [$entries[0]['createdBy'], $entries[0]['updatedBy'], $entries[0]['updatedAt']]);
    }

    #[DataProvider('managingRoles')]
    public function test_it_lets_owners_and_managers_edit_the_vat_and_note_of_a_booked_entry_but_not_its_payment_lines(MembershipRole $role): void
    {
        [$employee, $organization] = self::memberWithRole(MembershipRole::Employee);
        $manager = MembershipRole::Owner === $role ? OrganizationMother::ownerOf($organization) : self::addMember($organization, $role);
        $cash = self::arrange($organization);
        $close = self::close($employee, $organization, $cash, hundreds: 4, card: 10_00)->toArray();
        $entry = self::entries($manager, $organization)[0];

        $edited = self::correct($manager, $organization, $entry['id'], ['vatLines' => [['rate' => '23', 'gross' => 410_00, 'vat' => 76_67]], 'note' => 'VAT from the Z report']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([['rate' => '23', 'gross' => 410_00, 'vat' => 76_67, 'net' => 333_33]], $edited->toArray()['vatLines']);
        self::assertSame([$entry['payments'], $manager->getId()->toRfc4122()], [$edited->toArray()['payments'], $edited->toArray()['updatedBy']]);

        // The same lines, sent back as the admin's form sends them, are not a change of them.
        self::correct($manager, $organization, $entry['id'], ['payments' => array_map(static fn (array $line): array => ['paymentMethodId' => $line['paymentMethodId'], 'amount' => $line['amount']], array_reverse($entry['payments']))]);
        self::assertResponseStatusCodeSame(200);

        self::correct($manager, $organization, $entry['id'], ['payments' => [self::pay($organization, 'Cash', 400_00), self::pay($organization, 'Card', 11_00)]]);
        self::assertResponseStatusCodeSame(409);
        self::assertJsonContains(['code' => 'income.revenue_entry_booked_by_day_close', 'parameters' => ['entryId' => $entry['id'], 'dayCloseId' => $close['id']]]);

        static::clientFor($manager, $organization)->request('DELETE', '/api/revenue-entries/'.$entry['id']);
        self::assertResponseStatusCodeSame(409);
        self::assertJsonContains(['code' => 'income.revenue_entry_booked_by_day_close']);

        $after = self::entries($manager, $organization)[0];
        self::assertSame([$entry['payments'], 'VAT from the Z report'], [$after['payments'], self::correct($manager, $organization, $entry['id'], [])->toArray()['note']]);
    }

    public function test_it_refuses_a_close_up_front_that_income_could_not_book(): void
    {
        [$employee, $organization] = self::memberWithRole(MembershipRole::Employee);
        $cash = self::arrange($organization);
        $body = ['registerId' => $cash['bar']->getId()->toRfc4122(), 'storages' => [['storageId' => $cash['box'], 'lines' => []]], 'payments' => []];

        // A deactivated register is not offered (#185).
        self::deactivateRegister($organization, $cash['bar']);
        self::post($employee, $organization, $body);
        self::assertResponseStatusCodeSame(422);
        self::assertJsonContains(['code' => 'cash_flow.invalid_day_close.register_not_offered']);

        // No payment method is active: the cash income and the takings would be booked nowhere.
        foreach (['Cash', 'Card', 'Transfer', 'Voucher'] as $name) {
            self::deactivatePaymentMethod($organization, $name);
        }
        self::post($employee, $organization, $body);
        self::assertResponseStatusCodeSame(422);
        self::assertJsonContains(['code' => 'cash_flow.invalid_day_close.nothing_to_book']);

        self::assertSame([], self::entries(OrganizationMother::ownerOf($organization), $organization));
    }

    public function test_it_keeps_a_booked_entry_invisible_to_another_organization(): void
    {
        [$employee, $organization] = self::memberWithRole(MembershipRole::Employee);
        $cash = self::arrange($organization);
        self::close($employee, $organization, $cash, hundreds: 1);
        $entry = self::entries(OrganizationMother::ownerOf($organization), $organization)[0];

        [$owner, $other] = self::memberWithRole(MembershipRole::Owner);
        self::paymentMethodId($other);

        self::assertSame([], self::entries($owner, $other));
        static::clientFor($owner, $other)->request('GET', '/api/revenue-entries/'.$entry['id']);
        self::assertResponseStatusCodeSame(404);
        self::correct($owner, $other, $entry['id'], ['note' => 'x']);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Stores the Cash flow side and the payment methods, and, with an opening, puts that many 100 zł
     * notes into the box before the close. Mocks the time at NOW.
     *
     * @return array{box: string, hundred: string, bar: Register}
     */
    private static function arrange(Organization $organization, int $openingHundreds = 0): array
    {
        self::mockTime('2026-11-20 12:00:00');
        $ownerId = OrganizationMother::ownerOf($organization)->getId();
        $now = Clock::get()->now();
        self::paymentMethodId($organization);
        $box = CashStorage::create(Uuid::v7(), 'Kasetka', CashStorageMode::Counted, null, $ownerId, MembershipRole::Owner, $now);
        $hundred = Denomination::create(Uuid::v7(), 'PLN', 100_00, DenominationKind::Note, null, $ownerId, MembershipRole::Owner, $now);
        self::inTenant($organization, static function () use ($box, $hundred): void {
            static::getContainer()->get(CashStorageRepositoryInterface::class)->add($box);
            static::getContainer()->get(DenominationRepositoryInterface::class)->add($hundred);
        });
        $bar = self::addRegister($organization);

        $arranged = ['box' => $box->getId()->toRfc4122(), 'hundred' => $hundred->getId()->toRfc4122(), 'bar' => $bar];
        if ($openingHundreds > 0) {
            static::clientFor(OrganizationMother::ownerOf($organization), $organization)->request('POST', '/api/cash-movements/adjustments', ['json' => ['storageId' => $arranged['box'], 'lines' => [['denominationId' => $arranged['hundred'], 'pieces' => $openingHundreds]]]]);
            self::assertResponseStatusCodeSame(201);
        }
        self::mockTime(self::NOW);

        return $arranged;
    }

    /** @param array{box: string, hundred: string, bar: Register} $cash */
    private static function close(User $user, Organization $organization, array $cash, int $hundreds, int $card = 0, int $transfer = 0): ResponseInterface
    {
        return self::post($user, $organization, [
            'registerId' => $cash['bar']->getId()->toRfc4122(),
            'storages' => [['storageId' => $cash['box'], 'lines' => [['denominationId' => $cash['hundred'], 'pieces' => $hundreds]]]],
            'payments' => [self::pay($organization, 'Card', $card), self::pay($organization, 'Transfer', $transfer), self::pay($organization, 'Voucher', 0)],
        ]);
    }

    /** @param array<string, mixed> $body */
    private static function post(User $user, Organization $organization, array $body): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('POST', '/api/day-closes', ['json' => $body]);
    }

    /** @return list<array<string, mixed>> */
    private static function entries(User $user, Organization $organization): array
    {
        /** @var list<array<string, mixed>> */
        return static::clientFor($user, $organization)->request('GET', '/api/revenue-entries?from=2026-11-01&to=2026-11-30')->toArray();
    }

    /** @param array<string, mixed> $body */
    private static function correct(User $user, Organization $organization, string $id, array $body): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('PATCH', '/api/revenue-entries/'.$id, ['json' => $body, 'headers' => self::MERGE_PATCH]);
    }
}
