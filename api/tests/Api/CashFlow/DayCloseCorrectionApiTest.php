<?php

declare(strict_types=1);

namespace App\Tests\Api\CashFlow;

use App\CashFlow\Domain\Model\CashStorage;
use App\CashFlow\Domain\Model\CashStorageMode;
use App\CashFlow\Domain\Model\Denomination;
use App\CashFlow\Domain\Model\DenominationKind;
use App\CashFlow\Domain\Repository\CashStorageRepositoryInterface;
use App\CashFlow\Domain\Repository\DenominationRepositoryInterface;
use App\Income\Domain\Model\PaymentMethod;
use App\Income\Domain\Model\Register;
use App\Income\Domain\Repository\PaymentMethodRepositoryInterface;
use App\Income\Domain\Repository\RegisterRepositoryInterface;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Tests\Api\Income\IncomeApiTestCase;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixtures\OrganizationMother;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * PUT and DELETE /day-closes/{id} (ADR 0033 §7), and the revenue entries that follow (§9). The
 * organization is Polish (PLN, Europe/Warsaw), with a counted "Kasetka", a deposit "Sejf", the 100
 * and 200 zł notes, a register "Bar", a cash method and a card method. The employee closes at 01:30
 * in Warsaw on two nights in a row:
 *
 * - 20 November: 300 zł in the box (cash income 300 zł), 100 zł by card;
 * - 21 November: 500 zł in the box (200 zł), 50 zł by card.
 */
final class DayCloseCorrectionApiTest extends IncomeApiTestCase
{
    private const string FIRST_NIGHT = '2026-11-21 00:30:00';
    private const string NOW = '2026-11-22 00:30:00';

    /** @return iterable<string, array{MembershipRole}> */
    public static function managingRoles(): iterable
    {
        yield 'owner' => [MembershipRole::Owner];
        yield 'manager' => [MembershipRole::Manager];
    }

    #[DataProvider('managingRoles')]
    public function test_it_corrects_a_close_and_the_revenue_entries_follow(MembershipRole $role): void
    {
        [$manager, $organization, $cash, $first, $second] = self::twoNights($role);
        self::mockTime('2026-11-22 09:00:00');

        $corrected = self::correct($manager, $organization, $first, $cash, box: [[$cash[100], 2]], card: 150_00)->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['2026-11-20', 20_000, 20_000, 0], [$corrected['date'], $corrected['cashIncome'], $corrected['storages'][0]['total'], $corrected['storages'][0]['expectedOpening']]);
        self::assertSame([$manager->getId()->toRfc4122(), $manager->getFirstName(), '2026-11-22T09:00:00+00:00'], [$corrected['updatedBy'], $corrected['updatedByFirstName'], $corrected['updatedAt']]);
        // The next close's expected opening is the corrected count.
        $next = self::details($manager, $organization, $second)->toArray();
        self::assertSame([20_000, 30_000, null], [$next['storages'][0]['expectedOpening'], $next['cashIncome'], $next['updatedBy']]);

        self::assertSame([
            '2026-11-20' => [$cash['bar'], [$cash['cash'] => 20_000, $cash['card'] => 150_00], $manager->getId()->toRfc4122()],
            '2026-11-21' => [$cash['bar'], [$cash['cash'] => 30_000, $cash['card'] => 50_00], $manager->getId()->toRfc4122()],
        ], self::entries($manager, $organization));
    }

    public function test_it_moves_the_revenue_entry_when_a_correction_changes_the_register(): void
    {
        [$owner, $organization, $cash, $first] = self::twoNights(MembershipRole::Owner);
        $kitchen = self::addRegister($organization, 'Kuchnia');

        $corrected = self::correct($owner, $organization, $first, $cash, box: [[$cash[100], 3]], card: 100_00, register: $kitchen->getId()->toRfc4122())->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame([$kitchen->getId()->toRfc4122(), 'Kuchnia'], [$corrected['registerId'], $corrected['registerName']]);
        $entries = self::entries($owner, $organization);
        self::assertSame([$kitchen->getId()->toRfc4122(), [$cash['cash'] => 30_000, $cash['card'] => 100_00]], [$entries['2026-11-20'][0], $entries['2026-11-20'][1]]);
        self::assertSame($cash['bar'], $entries['2026-11-21'][0]);
        self::assertCount(2, $entries);
    }

    public function test_it_deletes_a_close_with_its_revenue_entry_and_its_cash_counts_in_the_next_close(): void
    {
        [$manager, $organization, $cash, $first, $second] = self::twoNights(MembershipRole::Manager);

        static::clientFor($manager, $organization)->request('DELETE', '/api/day-closes/'.$first);

        self::assertResponseStatusCodeSame(204);
        self::details($manager, $organization, $first);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(50_000, self::details($manager, $organization, $second)->toArray()['cashIncome']);
        self::assertSame([
            '2026-11-21' => [$cash['bar'], [$cash['cash'] => 50_000, $cash['card'] => 50_00], $manager->getId()->toRfc4122()],
        ], self::entries($manager, $organization));
    }

    public function test_it_recalculates_the_next_close_and_its_entry_after_a_backdated_adjustment_and_its_delete(): void
    {
        [$manager, $organization, $cash, $first, $second] = self::twoNights(MembershipRole::Manager);

        // 100 zł of change put into the box in the afternoon of 21 November, recorded the next morning.
        $movement = static::clientFor($manager, $organization)->request('POST', '/api/cash-movements/adjustments', ['json' => [
            'storageId' => $cash['box'],
            'lines' => [['denominationId' => $cash[100], 'pieces' => 1]],
            'occurredAt' => '2026-11-21T15:00:00+01:00',
        ]])->toArray();
        self::assertResponseStatusCodeSame(201);

        self::assertSame([30_000, 10_000], [self::details($manager, $organization, $first)->toArray()['cashIncome'], self::details($manager, $organization, $second)->toArray()['cashIncome']]);
        self::assertSame([$cash['cash'] => 10_000, $cash['card'] => 50_00], self::entries($manager, $organization)['2026-11-21'][1]);

        static::clientFor($manager, $organization)->request('DELETE', '/api/cash-movements/'.$movement['id']);
        self::assertResponseStatusCodeSame(204);
        self::assertSame([$cash['cash'] => 20_000, $cash['card'] => 50_00], self::entries($manager, $organization)['2026-11-21'][1]);
    }

    public function test_it_books_a_shortage_from_a_correction_as_no_cash(): void
    {
        [$owner, $organization, $cash, , $second] = self::twoNights(MembershipRole::Owner);

        $corrected = self::correct($owner, $organization, $second, $cash, box: [[$cash[100], 2]], card: 50_00)->toArray();

        self::assertSame([-10_000, true], [$corrected['cashIncome'], $corrected['shortage']]);
        self::assertSame([$cash['cash'] => 0, $cash['card'] => 50_00], self::entries($owner, $organization)['2026-11-21'][1]);
    }

    public function test_it_refuses_a_correction_or_delete_that_takes_the_safe_below_zero(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $cash = self::cash($organization);
        self::mockTime(self::NOW);
        $close = self::close($employee, $organization, $cash, box: [[$cash[100], 3]], safe: [[$cash[200], 2]])->toArray()['id'];
        self::mockTime('2026-11-22 09:00:00');
        // The 400 zł deposited go to the bank in the morning.
        static::clientFor($owner, $organization)->request('POST', '/api/cash-movements/adjustments', ['json' => ['storageId' => $cash['safe'], 'lines' => [['denominationId' => $cash[200], 'pieces' => -2]]]]);
        self::assertResponseStatusCodeSame(201);

        self::correct($owner, $organization, $close, $cash, box: [[$cash[100], 3]], safe: [[$cash[200], 1]]);
        self::assertResponseStatusCodeSame(409);
        self::assertJsonContains(['code' => 'cash_flow.storage_balance_negative', 'parameters' => ['storageId' => $cash['safe'], 'pieces' => -1]]);
        static::clientFor($owner, $organization)->request('DELETE', '/api/day-closes/'.$close);
        self::assertResponseStatusCodeSame(409);

        $details = self::details($owner, $organization, $close)->toArray();
        self::assertSame([70_000, 40_000, null], [$details['cashIncome'], $details['storages'][1]['total'], $details['updatedBy']]);
    }

    public function test_it_rejects_an_invalid_correction(): void
    {
        [$owner, $organization, $cash, $first] = self::twoNights(MembershipRole::Owner);
        $client = static::clientFor($owner, $organization);

        $client->request('PUT', '/api/day-closes/'.$first, ['json' => ['registerId' => 'nope']]);
        self::assertResponseStatusCodeSame(422);

        $client->request('PUT', '/api/day-closes/'.$first, ['json' => [
            'registerId' => $cash['bar'],
            'storages' => [['storageId' => $cash['box'], 'lines' => []]],
            'payments' => [['paymentMethodId' => $cash['card'], 'amount' => 0]],
        ]]);
        self::assertResponseStatusCodeSame(422);
        self::assertJsonContains(['code' => 'cash_flow.invalid_day_close.missing_storage', 'parameters' => ['storageId' => $cash['safe']]]);

        self::correct($owner, $organization, $first, $cash, payments: [['paymentMethodId' => $cash['cash'], 'amount' => 0]]);
        self::assertResponseStatusCodeSame(422);
        self::assertJsonContains(['code' => 'cash_flow.invalid_day_close.payment_method_not_in_close']);

        self::correct($owner, $organization, Uuid::v7()->toRfc4122(), $cash);
        self::assertResponseStatusCodeSame(404);
        static::clientFor($owner, $organization)->request('DELETE', '/api/day-closes/'.Uuid::v7()->toRfc4122());
        self::assertResponseStatusCodeSame(404);
    }

    public function test_it_lets_only_owners_and_managers_correct_or_delete_a_close(): void
    {
        [$owner, $organization, , $first, , $employee] = self::twoNights(MembershipRole::Owner);

        // The employee is refused before the body is read.
        static::clientFor($employee, $organization)->request('PUT', '/api/day-closes/'.$first, ['json' => []]);
        self::assertResponseStatusCodeSame(403);
        self::assertJsonContains(['code' => 'cash_flow.access_forbidden.to_read_day_closes']);
        static::clientFor($employee, $organization)->request('DELETE', '/api/day-closes/'.$first);
        self::assertResponseStatusCodeSame(403);

        $stranger = UserFactory::createOne();
        static::clientFor($stranger, $organization)->request('DELETE', '/api/day-closes/'.$first);
        self::assertResponseStatusCodeSame(403);

        self::assertSame(30_000, self::details($owner, $organization, $first)->toArray()['cashIncome']);
        self::assertCount(2, self::entries($owner, $organization));
    }

    public function test_it_keeps_another_organizations_closes_out_of_reach(): void
    {
        [$owner, $organization, $cash, $first] = self::twoNights(MembershipRole::Owner);
        [$otherOwner, $other] = self::memberWithRole(MembershipRole::Owner);
        $otherCash = self::cash($other);

        self::correct($otherOwner, $other, $first, $otherCash);
        self::assertResponseStatusCodeSame(404);
        static::clientFor($otherOwner, $other)->request('DELETE', '/api/day-closes/'.$first);
        self::assertResponseStatusCodeSame(404);

        self::assertSame(30_000, self::details($owner, $organization, $first)->toArray()['cashIncome']);
        self::assertSame([$cash['cash'] => 30_000, $cash['card'] => 100_00], self::entries($owner, $organization)['2026-11-20'][1]);
    }

    /**
     * An organization whose employee closed 20 and 21 November, and a member of it with the role.
     *
     * @return array{User, Organization, array<string|int, string>, string, string, User} the member, the organization, its cash, both closes and the employee
     */
    private static function twoNights(MembershipRole $role): array
    {
        [$member, $organization] = self::memberWithRole($role);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $cash = self::cash($organization);

        self::mockTime(self::FIRST_NIGHT);
        $first = self::close($employee, $organization, $cash, box: [[$cash[100], 3]], card: 100_00);
        self::assertResponseStatusCodeSame(201);
        self::mockTime(self::NOW);
        $second = self::close($employee, $organization, $cash, box: [[$cash[100], 5]], card: 50_00);
        self::assertResponseStatusCodeSame(201);

        return [$member, $organization, $cash, $first->toArray()['id'], $second->toArray()['id'], $employee];
    }

    /**
     * @param array<string|int, string> $cash
     * @param list<array{string, int}>  $box
     * @param list<array{string, int}>  $safe
     */
    private static function close(User $user, Organization $organization, array $cash, array $box = [], array $safe = [], int $card = 0): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('POST', '/api/day-closes', ['json' => self::body($cash, $box, $safe, $card)]);
    }

    /**
     * @param array<string|int, string>       $cash
     * @param list<array{string, int}>        $box
     * @param list<array{string, int}>        $safe
     * @param list<array<string, mixed>>|null $payments
     */
    private static function correct(User $user, Organization $organization, string $id, array $cash, array $box = [], array $safe = [], int $card = 0, ?string $register = null, ?array $payments = null): ResponseInterface
    {
        $body = self::body($cash, $box, $safe, $card, $register);
        if (null !== $payments) {
            $body['payments'] = $payments;
        }

        return static::clientFor($user, $organization)->request('PUT', '/api/day-closes/'.$id, ['json' => $body]);
    }

    /**
     * @param array<string|int, string> $cash
     * @param list<array{string, int}>  $box
     * @param list<array{string, int}>  $safe
     *
     * @return array<string, mixed>
     */
    private static function body(array $cash, array $box, array $safe, int $card, ?string $register = null): array
    {
        return [
            'registerId' => $register ?? $cash['bar'],
            'storages' => [
                ['storageId' => $cash['box'], 'lines' => self::lines($box)],
                ['storageId' => $cash['safe'], 'lines' => self::lines($safe)],
            ],
            'payments' => [['paymentMethodId' => $cash['card'], 'amount' => $card]],
        ];
    }

    /**
     * @param list<array{string, int}> $lines
     *
     * @return list<array{denominationId: string, pieces: int}>
     */
    private static function lines(array $lines): array
    {
        return array_map(static fn (array $line): array => ['denominationId' => $line[0], 'pieces' => $line[1]], $lines);
    }

    private static function details(User $user, Organization $organization, string $id): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('GET', '/api/day-closes/'.$id);
    }

    /**
     * The revenue entries of November by date: register, amount by payment method, last updated by.
     *
     * @return array<string, array{string, array<string, int>, ?string}>
     */
    private static function entries(User $user, Organization $organization): array
    {
        /** @var list<array{businessDate: string, registerId: string, payments: list<array{paymentMethodId: string, amount: int}>, updatedBy: ?string}> $entries */
        $entries = static::clientFor($user, $organization)->request('GET', '/api/revenue-entries?from=2026-11-01&to=2026-11-30')->toArray();

        $byDate = [];
        foreach ($entries as $entry) {
            $byDate[$entry['businessDate']] = [$entry['registerId'], array_column($entry['payments'], 'amount', 'paymentMethodId'), $entry['updatedBy']];
        }
        ksort($byDate);

        return $byDate;
    }

    /** @return array<string|int, string> ids of the storages ('box', 'safe'), notes (100, 200), register ('bar') and methods ('cash', 'card') */
    private static function cash(Organization $organization): array
    {
        $ownerId = OrganizationMother::ownerOf($organization)->getId();
        $now = Clock::get()->now();
        $safe = CashStorage::create(Uuid::v7(), 'Sejf', CashStorageMode::Deposit, null, $ownerId, MembershipRole::Owner, $now);
        $box = CashStorage::create(Uuid::v7(), 'Kasetka', CashStorageMode::Counted, null, $ownerId, MembershipRole::Owner, $now);
        $notes = array_map(static fn (int $value): Denomination => Denomination::create(Uuid::v7(), 'PLN', $value, DenominationKind::Note, null, $ownerId, MembershipRole::Owner, $now), [10_000, 20_000]);
        $bar = Register::create(Uuid::v7(), 'Bar', null, $ownerId, MembershipRole::Owner, $now);
        $cashMethod = PaymentMethod::create(Uuid::v7(), 'Gotówka', null, 1, true, null, $ownerId, MembershipRole::Owner, $now);
        $card = PaymentMethod::create(Uuid::v7(), 'Karta', null, 2, false, $cashMethod, $ownerId, MembershipRole::Owner, $now);

        self::inTenant($organization, static function () use ($safe, $box, $notes, $bar, $cashMethod, $card): void {
            $container = static::getContainer();
            $container->get(CashStorageRepositoryInterface::class)->add($safe);
            $container->get(CashStorageRepositoryInterface::class)->add($box);
            foreach ($notes as $note) {
                $container->get(DenominationRepositoryInterface::class)->add($note);
            }
            $container->get(RegisterRepositoryInterface::class)->add($bar);
            $container->get(PaymentMethodRepositoryInterface::class)->add($cashMethod);
            $container->get(PaymentMethodRepositoryInterface::class)->add($card);
        });

        return [
            'safe' => $safe->getId()->toRfc4122(),
            'box' => $box->getId()->toRfc4122(),
            100 => $notes[0]->getId()->toRfc4122(),
            200 => $notes[1]->getId()->toRfc4122(),
            'bar' => $bar->getId()->toRfc4122(),
            'cash' => $cashMethod->getId()->toRfc4122(),
            'card' => $card->getId()->toRfc4122(),
        ];
    }
}
