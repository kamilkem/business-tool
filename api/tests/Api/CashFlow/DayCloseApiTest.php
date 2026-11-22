<?php

declare(strict_types=1);

namespace App\Tests\Api\CashFlow;

use App\CashFlow\Domain\Model\CashStorage;
use App\CashFlow\Domain\Model\CashStorageMode;
use App\CashFlow\Domain\Model\DayCloseDateMode;
use App\CashFlow\Domain\Model\DayCloseRule;
use App\CashFlow\Domain\Model\DayCloseSettings;
use App\CashFlow\Domain\Model\DayEndTime;
use App\CashFlow\Domain\Model\Denomination;
use App\CashFlow\Domain\Model\DenominationKind;
use App\CashFlow\Domain\Repository\CashStorageRepositoryInterface;
use App\CashFlow\Domain\Repository\DayCloseSettingsRepositoryInterface;
use App\CashFlow\Domain\Repository\DenominationRepositoryInterface;
use App\Income\Domain\Model\PaymentMethod;
use App\Income\Domain\Model\Register;
use App\Income\Domain\Repository\PaymentMethodRepositoryInterface;
use App\Income\Domain\Repository\RegisterRepositoryInterface;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;
use App\Tests\Api\ApiTestCase;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixtures\OrganizationMother;
use App\User\Domain\Model\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * POST /day-closes, GET /day-closes and /day-closes/{id}, and GET /day-close-form (ADR 0033 §6).
 * The organization is Polish (PLN, Europe/Warsaw), with a counted "Kasetka", a deposit "Sejf", the
 * 50, 100 and 200 zł notes, a register "Bar", a cash method and a card method. The server's time is
 * 01:30 in Warsaw on 22 November, so with the default settings (the day ends at 05:00) a close is for
 * 21 November.
 */
final class DayCloseApiTest extends ApiTestCase
{
    private const string NOW = '2026-11-22 00:30:00';

    /** @return iterable<string, array{MembershipRole}> */
    public static function managingRoles(): iterable
    {
        yield 'owner' => [MembershipRole::Owner];
        yield 'manager' => [MembershipRole::Manager];
    }

    public function test_it_lets_an_employee_close_the_day_and_shows_them_only_what_they_entered(): void
    {
        [$employee, $organization] = self::memberWithRole(MembershipRole::Employee);
        $cash = self::cash($organization);
        self::mockTime('2026-11-21 08:00:00');
        self::adjust($organization, $cash['box'], [[$cash[100], 2]]);
        self::mockTime(self::NOW);

        $response = self::close($employee, $organization, $cash, box: [[$cash[100], 5], [$cash[50], 1]], safe: [[$cash[200], 2]], card: 123_45);

        self::assertResponseStatusCodeSame(201);
        $receipt = $response->toArray();
        self::assertInstanceOf(UuidV7::class, Uuid::fromString($receipt['id']));
        self::assertSame([
            'id' => $receipt['id'],
            'date' => '2026-11-21',
            'registerId' => $cash['bar'],
            'registerName' => 'Bar',
            'currency' => 'PLN',
            // 55 000 counted − 20 000 expected + 40 000 deposited.
            'cashIncome' => 75_000,
            'shortage' => false,
            'storages' => [
                ['storageId' => $cash['box'], 'name' => 'Kasetka', 'mode' => 'counted', 'total' => 55_000, 'lines' => [
                    ['denominationId' => $cash[50], 'value' => 5_000, 'kind' => 'note', 'pieces' => 1, 'amount' => 5_000],
                    ['denominationId' => $cash[100], 'value' => 10_000, 'kind' => 'note', 'pieces' => 5, 'amount' => 50_000],
                    ['denominationId' => $cash[200], 'value' => 20_000, 'kind' => 'note', 'pieces' => 0, 'amount' => 0],
                ]],
                ['storageId' => $cash['safe'], 'name' => 'Sejf', 'mode' => 'deposit', 'total' => 40_000, 'lines' => [
                    ['denominationId' => $cash[200], 'value' => 20_000, 'kind' => 'note', 'pieces' => 2, 'amount' => 40_000],
                ]],
            ],
            'payments' => [['paymentMethodId' => $cash['card'], 'name' => 'Karta', 'amount' => 123_45]],
            'closedAt' => '2026-11-22T00:30:00+00:00',
        ], $receipt);

        // The employee sees no close afterwards: neither the list nor the details.
        self::list($employee, $organization, '2026-11-01', '2026-11-30');
        self::assertResponseStatusCodeSame(403);
        self::assertJsonContains(['code' => 'cash_flow.access_forbidden.to_read_day_closes']);
        self::details($employee, $organization, $receipt['id']);
        self::assertResponseStatusCodeSame(403);

        // The owner sees the expected opening.
        $owner = OrganizationMother::ownerOf($organization);
        $details = self::details($owner, $organization, $receipt['id'])->toArray();
        self::assertResponseStatusCodeSame(200);
        self::assertSame([20_000, 35_000, null, 40_000], [$details['storages'][0]['expectedOpening'], $details['storages'][0]['cashIncome'], $details['storages'][1]['expectedOpening'], $details['storages'][1]['cashIncome']]);
        self::assertSame([$employee->getId()->toRfc4122(), $employee->getFirstName()], [$details['closedBy'], $details['closedByFirstName']]);
    }

    public function test_it_lets_an_employee_pick_today_or_yesterday_in_employee_picks_mode(): void
    {
        [$employee, $organization] = self::memberWithRole(MembershipRole::Employee);
        $cash = self::cash($organization);
        self::mockTime(self::NOW);

        self::close($employee, $organization, $cash, date: '2026-11-22');
        self::assertResponseStatusCodeSame(422);
        self::assertJsonContains(['code' => 'cash_flow.invalid_day_close_date.not_chosen_by_employee']);

        self::inOrganization($organization, static fn () => static::getContainer()->get(DayCloseSettingsRepositoryInterface::class)->add(
            DayCloseSettings::create(Uuid::v7(), new DayCloseRule(DayCloseDateMode::EmployeePicks, DayEndTime::default()), OrganizationMother::ownerOf($organization)->getId(), MembershipRole::Owner, Clock::get()->now()),
        ));

        self::close($employee, $organization, $cash, date: '2026-11-20');
        self::assertResponseStatusCodeSame(422);
        self::assertJsonContains(['code' => 'cash_flow.invalid_day_close_date.not_today_or_yesterday']);
        self::assertSame('2026-11-22', self::close($employee, $organization, $cash, date: '2026-11-22')->toArray()['date']);
        self::assertResponseStatusCodeSame(201);
    }

    #[DataProvider('managingRoles')]
    public function test_it_lets_owners_and_managers_close_a_past_date_but_not_a_future_one(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        $cash = self::cash($organization);
        self::mockTime(self::NOW);

        self::close($actor, $organization, $cash, date: '2026-11-23');
        self::assertResponseStatusCodeSame(422);
        self::assertJsonContains(['code' => 'cash_flow.invalid_day_close_date.in_the_future']);

        $close = self::close($actor, $organization, $cash, date: '2026-11-15', box: [[$cash[50], 1]])->toArray();
        self::assertResponseStatusCodeSame(201);
        self::assertSame('2026-11-15', $close['date']);

        $list = self::list($actor, $organization, '2026-11-01', '2026-11-30')->toArray();
        self::assertResponseStatusCodeSame(200);
        self::assertSame([$close['id']], array_column($list, 'id'));
        self::assertSame($list[0], self::details($actor, $organization, $close['id'])->toArray());
    }

    public function test_it_refuses_a_second_close_of_a_date_saying_who_closed_it_and_an_earlier_date(): void
    {
        [$employee, $organization] = self::memberWithRole(MembershipRole::Employee);
        $cash = self::cash($organization);
        self::mockTime(self::NOW);
        $first = self::close($employee, $organization, $cash)->toArray();

        $owner = OrganizationMother::ownerOf($organization);
        self::close($owner, $organization, $cash, date: '2026-11-21');
        self::assertResponseStatusCodeSame(409);
        self::assertJsonContains(['code' => 'cash_flow.day_already_closed.by_member', 'parameters' => [
            'date' => '2026-11-21',
            'dayCloseId' => $first['id'],
            'closedBy' => $employee->getId()->toRfc4122(),
            'closedByFirstName' => $employee->getFirstName(),
            'closedByLastName' => $employee->getLastName(),
            'closedAt' => '2026-11-22T00:30:00+00:00',
        ]]);

        self::close($owner, $organization, $cash, date: '2026-11-19');
        self::assertResponseStatusCodeSame(409);
        self::assertJsonContains(['code' => 'cash_flow.day_close_out_of_order', 'parameters' => ['date' => '2026-11-19', 'lastClosedDate' => '2026-11-21']]);

        $form = self::form($employee, $organization)->toArray();
        self::assertSame([$first['id'], $employee->getFirstName()], [$form['proposedDateClosed']['dayCloseId'], $form['proposedDateClosed']['closedByFirstName']]);
    }

    public function test_it_rejects_an_invalid_close(): void
    {
        [$employee, $organization] = self::memberWithRole(MembershipRole::Employee);
        $cash = self::cash($organization);
        self::mockTime(self::NOW);

        $invalid = self::post($employee, $organization, [
            'registerId' => 'nope',
            'storages' => [['storageId' => $cash['box'], 'lines' => [['denominationId' => $cash[50], 'pieces' => -1]]], ['storageId' => $cash['box'], 'lines' => []]],
            'payments' => [['paymentMethodId' => $cash['card'], 'amount' => -5]],
            'date' => '21.11.2026',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['date', 'payments[0].amount', 'registerId', 'storages', 'storages[0].lines[0].pieces'], self::violationPaths($invalid));

        self::post($employee, $organization, ['registerId' => $cash['bar'], 'storages' => [['storageId' => $cash['box'], 'lines' => []]], 'payments' => [['paymentMethodId' => $cash['card'], 'amount' => 0]]]);
        self::assertResponseStatusCodeSame(422);
        self::assertJsonContains(['code' => 'cash_flow.invalid_day_close.missing_storage', 'parameters' => ['storageId' => $cash['safe'], 'name' => 'Sejf']]);

        self::close($employee, $organization, $cash, payments: []);
        self::assertResponseStatusCodeSame(422);
        self::assertJsonContains(['code' => 'cash_flow.invalid_day_close.missing_payment_method', 'parameters' => ['name' => 'Karta']]);

        self::close($employee, $organization, $cash, payments: [['paymentMethodId' => $cash['cash'], 'amount' => 0], ['paymentMethodId' => $cash['card'], 'amount' => 0]]);
        self::assertResponseStatusCodeSame(422);
        self::assertJsonContains(['code' => 'cash_flow.invalid_day_close.payment_method_not_offered']);

        self::close($employee, $organization, $cash, register: Uuid::v7()->toRfc4122());
        self::assertResponseStatusCodeSame(422);
        self::assertJsonContains(['code' => 'cash_flow.invalid_day_close.register_not_offered']);

        self::close($employee, $organization, $cash, box: [[Uuid::v7()->toRfc4122(), 1]]);
        self::assertResponseStatusCodeSame(404);
        self::assertJsonContains(['code' => 'cash_flow.denomination_not_found']);

        self::list(OrganizationMother::ownerOf($organization), $organization, '2026-01-01', '2026-11-30');
        self::assertResponseStatusCodeSame(422);
    }

    public function test_it_shows_the_close_form_to_any_member_without_balances(): void
    {
        [$employee, $organization] = self::memberWithRole(MembershipRole::Employee);
        $cash = self::cash($organization);
        self::mockTime(self::NOW);

        $form = self::form($employee, $organization)->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame([
            'currency' => 'PLN',
            'canClose' => true,
            'proposedDate' => '2026-11-21',
            'today' => '2026-11-22',
            'mode' => 'day_end_time',
            'dayEndsAt' => '05:00',
            'storages' => [['id' => $cash['box'], 'name' => 'Kasetka', 'mode' => 'counted'], ['id' => $cash['safe'], 'name' => 'Sejf', 'mode' => 'deposit']],
            'denominations' => [['id' => $cash[50], 'value' => 5_000, 'kind' => 'note'], ['id' => $cash[100], 'value' => 10_000, 'kind' => 'note'], ['id' => $cash[200], 'value' => 20_000, 'kind' => 'note']],
            'registers' => [['id' => $cash['bar'], 'name' => 'Bar']],
            'preselectedRegisterId' => $cash['bar'],
            'paymentMethods' => [['id' => $cash['card'], 'name' => 'Karta']],
            'proposedDateClosed' => null,
        ], $form);
    }

    public function test_it_keeps_another_organizations_closes_invisible(): void
    {
        [$employee, $organization] = self::memberWithRole(MembershipRole::Employee);
        $cash = self::cash($organization);
        self::mockTime(self::NOW);
        $theirs = self::close($employee, $organization, $cash)->toArray();

        [$owner, $other] = self::memberWithRole(MembershipRole::Owner);
        $otherCash = self::cash($other);

        self::details($owner, $other, $theirs['id']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame([], self::list($owner, $other, '2026-11-01', '2026-11-30')->toArray());
        self::assertNull(self::form($owner, $other)->toArray()['proposedDateClosed']);
        // Its date is open here, and its storages are unknown here.
        self::close($owner, $other, $otherCash);
        self::assertResponseStatusCodeSame(201);
        self::close($owner, $other, ['box' => $cash['box'], 'safe' => $cash['safe'], 'bar' => $otherCash['bar'], 'card' => $otherCash['card']], date: '2026-11-22');
        self::assertResponseStatusCodeSame(404);

        // A member of neither cannot see the first organization.
        $stranger = UserFactory::createOne();
        self::form($stranger, $organization);
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param array<string|int, string>         $cash
     * @param list<array{string, int}>          $box
     * @param list<array{string, int}>          $safe
     * @param list<array<string, mixed>>|null   $payments
     */
    private static function close(User $user, Organization $organization, array $cash, array $box = [], array $safe = [], int $card = 0, ?string $date = null, ?array $payments = null, ?string $register = null): ResponseInterface
    {
        $body = [
            'registerId' => $register ?? $cash['bar'],
            'storages' => [
                ['storageId' => $cash['box'], 'lines' => self::lines($box)],
                ['storageId' => $cash['safe'], 'lines' => self::lines($safe)],
            ],
            'payments' => $payments ?? [['paymentMethodId' => $cash['card'], 'amount' => $card]],
        ];
        if (null !== $date) {
            $body['date'] = $date;
        }

        return self::post($user, $organization, $body);
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

    /** @param array<string, mixed> $body */
    private static function post(User $user, Organization $organization, array $body): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('POST', '/api/day-closes', ['json' => $body]);
    }

    private static function details(User $user, Organization $organization, string $id): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('GET', '/api/day-closes/'.$id);
    }

    private static function list(User $user, Organization $organization, string $from, string $to): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('GET', '/api/day-closes?from='.$from.'&to='.$to);
    }

    private static function form(User $user, Organization $organization): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('GET', '/api/day-close-form');
    }

    /** @param list<array{string, int}> $lines */
    private static function adjust(Organization $organization, string $storageId, array $lines): void
    {
        static::clientFor(OrganizationMother::ownerOf($organization), $organization)->request('POST', '/api/cash-movements/adjustments', ['json' => ['storageId' => $storageId, 'lines' => self::lines($lines)]]);
        self::assertResponseStatusCodeSame(201);
    }

    /** @return array<string|int, string> ids of the storages ('box', 'safe'), notes (50, 100, 200), register ('bar') and methods ('cash', 'card') */
    private static function cash(Organization $organization): array
    {
        $ownerId = OrganizationMother::ownerOf($organization)->getId();
        $now = Clock::get()->now();
        $safe = CashStorage::create(Uuid::v7(), 'Sejf', CashStorageMode::Deposit, null, $ownerId, MembershipRole::Owner, $now);
        $box = CashStorage::create(Uuid::v7(), 'Kasetka', CashStorageMode::Counted, null, $ownerId, MembershipRole::Owner, $now);
        $notes = array_map(static fn (int $value): Denomination => Denomination::create(Uuid::v7(), 'PLN', $value, DenominationKind::Note, null, $ownerId, MembershipRole::Owner, $now), [5_000, 10_000, 20_000]);
        $bar = Register::create(Uuid::v7(), 'Bar', null, $ownerId, MembershipRole::Owner, $now);
        $cashMethod = PaymentMethod::create(Uuid::v7(), 'Gotówka', null, 1, true, null, $ownerId, MembershipRole::Owner, $now);
        $card = PaymentMethod::create(Uuid::v7(), 'Karta', null, 2, false, $cashMethod, $ownerId, MembershipRole::Owner, $now);

        self::inOrganization($organization, static function () use ($safe, $box, $notes, $bar, $cashMethod, $card): void {
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
            50 => $notes[0]->getId()->toRfc4122(),
            100 => $notes[1]->getId()->toRfc4122(),
            200 => $notes[2]->getId()->toRfc4122(),
            'bar' => $bar->getId()->toRfc4122(),
            'cash' => $cashMethod->getId()->toRfc4122(),
            'card' => $card->getId()->toRfc4122(),
        ];
    }

    /** @param \Closure(): mixed $write */
    private static function inOrganization(Organization $organization, \Closure $write): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $tenantContext = static::getContainer()->get(TenantContextInterface::class);
        $tenantContext->switchTo($entityManager->find(Organization::class, $organization->getId()) ?? throw new \LogicException('Organization expected.'));
        try {
            $write();
        } finally {
            $tenantContext->reset();
            $entityManager->clear();
        }
    }

    /** @return list<string> */
    private static function violationPaths(ResponseInterface $response): array
    {
        $paths = array_column($response->toArray(false)['violations'] ?? [], 'propertyPath');
        sort($paths);

        return array_values(array_unique($paths));
    }
}
