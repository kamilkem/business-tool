<?php

declare(strict_types=1);

namespace App\Tests\Api\Income;

use App\Income\Domain\Model\Register;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Tests\Fixtures\OrganizationMother;
use App\User\Domain\Model\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * GET /revenue-entries?from=&to=[&registerId=] and GET /revenue-summary?from=&to= (ADR 0022). Entries
 * are arranged through POST /revenue-entries at NOW, already 2026-11-13 in Europe/Warsaw.
 */
final class RevenueListAndSummaryApiTest extends IncomeApiTestCase
{
    private const string NOW = '2026-11-12 23:30:00';

    /** @return iterable<string, array{MembershipRole}> */
    public static function managingRoles(): iterable
    {
        yield 'owner' => [MembershipRole::Owner];
        yield 'manager' => [MembershipRole::Manager];
    }

    #[DataProvider('managingRoles')]
    public function test_it_lists_the_entries_of_a_period_by_date_then_register(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        $kitchen = self::addRegister($organization, 'Kitchen');
        $bar = self::addRegister($organization, 'Bar');
        self::mockTime(self::NOW);
        $kitchenFirst = self::record($actor, $organization, $kitchen, '2026-11-01', [['method' => 'card', 'amount' => 700]]);
        $barSecond = self::record($actor, $organization, $bar, '2026-11-02', [['method' => 'cash', 'amount' => 100]]);
        $barFirst = self::record($actor, $organization, $bar, '2026-11-01', [['method' => 'cash', 'amount' => 200]], [['rate' => '23', 'gross' => 200, 'vat' => 37]]);
        self::record($actor, $organization, $bar, '2026-10-31', [['method' => 'cash', 'amount' => 1]]);
        self::deactivateRegister($organization, $kitchen);

        $response = self::list($actor, $organization, ['from' => '2026-11-01', 'to' => '2026-11-30']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([$barFirst, $kitchenFirst, $barSecond], $response->toArray());
    }

    public function test_it_lists_one_registers_entries(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $bar = self::addRegister($organization, 'Bar');
        $kitchen = self::addRegister($organization, 'Kitchen');
        self::mockTime(self::NOW);
        self::record($owner, $organization, $bar, '2026-11-01', [['method' => 'cash', 'amount' => 100]]);
        $kitchenEntry = self::record($owner, $organization, $kitchen, '2026-11-01', [['method' => 'cash', 'amount' => 100]]);

        $response = self::list($owner, $organization, ['from' => '2026-11-01', 'to' => '2026-11-30', 'registerId' => $kitchen->getId()->toRfc4122()]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([$kitchenEntry], $response->toArray());
    }

    public function test_it_answers_404_for_a_register_that_is_not_the_organizations(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        $registerElsewhere = self::addRegister($elsewhere);

        self::list($owner, $organization, ['from' => '2026-11-01', 'to' => '2026-11-30', 'registerId' => $registerElsewhere->getId()->toRfc4122()]);
        self::assertResponseStatusCodeSame(404);
        self::list($owner, $organization, ['from' => '2026-11-01', 'to' => '2026-11-30', 'registerId' => Uuid::v7()->toRfc4122()]);
        self::assertResponseStatusCodeSame(404);
    }

    #[DataProvider('managingRoles')]
    public function test_it_sums_a_period_per_payment_method_vat_rate_and_register(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        $bar = self::addRegister($organization, 'Bar');
        $kitchen = self::addRegister($organization, 'Kitchen');
        self::mockTime(self::NOW);
        self::record($actor, $organization, $bar, '2026-11-01', [['method' => 'cash', 'amount' => 10_000], ['method' => 'card', 'amount' => 20_000]], [['rate' => '23', 'gross' => 24_600, 'vat' => 4_600], ['rate' => 'zw', 'gross' => 1_000, 'vat' => 0]]);
        self::record($actor, $organization, $kitchen, '2026-11-01', [['method' => 'card', 'amount' => 10_800]], [['rate' => '8', 'gross' => 10_800, 'vat' => 800]]);
        self::record($actor, $organization, $bar, '2026-11-13', [['method' => 'transfer', 'amount' => 5_000]]);
        self::record($actor, $organization, $bar, '2026-10-31', [['method' => 'cash', 'amount' => 99_999]]);

        $response = self::summary($actor, $organization, ['from' => '2026-11-01', 'to' => '2026-11-30']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([
            'from' => '2026-11-01',
            'to' => '2026-11-30',
            'dayCount' => 30,
            'daysWithEntries' => 2,
            'entryCount' => 3,
            'currencies' => [[
                'currency' => 'PLN',
                'total' => 45_800,
                'entryCount' => 3,
                'payments' => [['method' => 'cash', 'amount' => 10_000], ['method' => 'card', 'amount' => 30_800], ['method' => 'transfer', 'amount' => 5_000]],
                'vatLines' => [
                    ['rate' => '23', 'gross' => 24_600, 'vat' => 4_600, 'net' => 20_000],
                    ['rate' => '8', 'gross' => 10_800, 'vat' => 800, 'net' => 10_000],
                    ['rate' => 'zw', 'gross' => 1_000, 'vat' => 0, 'net' => 1_000],
                ],
                'vatGrossTotal' => 36_400,
                'vatTotal' => 5_400,
                'registers' => [
                    ['registerId' => $bar->getId()->toRfc4122(), 'registerName' => 'Bar', 'total' => 35_000, 'entryCount' => 2],
                    ['registerId' => $kitchen->getId()->toRfc4122(), 'registerName' => 'Kitchen', 'total' => 10_800, 'entryCount' => 1],
                ],
            ]],
        ], $response->toArray());
    }

    public function test_it_gives_zero_totals_in_the_organizations_currency_for_a_period_without_entries(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        $response = self::summary($owner, $organization, ['from' => '2026-02-01', 'to' => '2026-02-28']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([
            'from' => '2026-02-01',
            'to' => '2026-02-28',
            'dayCount' => 28,
            'daysWithEntries' => 0,
            'entryCount' => 0,
            'currencies' => [['currency' => 'PLN', 'total' => 0, 'entryCount' => 0, 'payments' => [], 'vatLines' => [], 'vatGrossTotal' => 0, 'vatTotal' => 0, 'registers' => []]],
        ], $response->toArray());
    }

    /** @return iterable<string, array{string, array<string, string|list<string>>, string}> */
    public static function invalidQueries(): iterable
    {
        foreach (['/api/revenue-entries', '/api/revenue-summary'] as $path) {
            yield $path.': no from' => [$path, ['to' => '2026-11-11'], 'from'];
            yield $path.': no to' => [$path, ['from' => '2026-11-11'], 'to'];
            yield $path.': from not YYYY-MM-DD' => [$path, ['from' => '1.11.2026', 'to' => '2026-11-11'], 'from'];
            yield $path.': to that does not exist' => [$path, ['from' => '2026-02-01', 'to' => '2026-02-29'], 'to'];
            yield $path.': from as an array' => [$path, ['from' => ['2026-11-01'], 'to' => '2026-11-11'], 'from'];
            yield $path.': to before from' => [$path, ['from' => '2026-11-12', 'to' => '2026-11-11'], 'to'];
        }
        yield '/api/revenue-entries: 93 days' => ['/api/revenue-entries', ['from' => '2026-08-31', 'to' => '2026-12-01'], 'to'];
        yield '/api/revenue-entries: registerId not a UUID' => ['/api/revenue-entries', ['from' => '2026-11-01', 'to' => '2026-11-30', 'registerId' => 'bar'], 'registerId'];
        yield '/api/revenue-summary: 367 days' => ['/api/revenue-summary', ['from' => '2025-01-01', 'to' => '2026-01-02'], 'to'];
    }

    /** @param array<string, string|list<string>> $query */
    #[DataProvider('invalidQueries')]
    public function test_it_answers_422_for_invalid_parameters(string $path, array $query, string $invalidParameter): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        $response = static::clientFor($owner, $organization)->request('GET', $path, ['query' => $query]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([$invalidParameter], array_values(array_unique(array_column($response->toArray(false)['violations'] ?? [], 'propertyPath'))));
    }

    public function test_it_takes_the_longest_periods(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        self::list($owner, $organization, ['from' => '2026-09-01', 'to' => '2026-12-01']);
        self::assertResponseStatusCodeSame(200);
        self::summary($owner, $organization, ['from' => '2024-01-01', 'to' => '2024-12-31']);
        self::assertResponseStatusCodeSame(200);
    }

    /** @return iterable<string, array{\Closure(User, Organization): ResponseInterface}> */
    public static function requests(): iterable
    {
        yield 'list' => [static fn (User $user, Organization $organization): ResponseInterface => self::list($user, $organization, ['from' => '2026-11-01', 'to' => '2026-11-30'])];
        yield 'summary' => [static fn (User $user, Organization $organization): ResponseInterface => self::summary($user, $organization, ['from' => '2026-11-01', 'to' => '2026-11-30'])];
    }

    /** @param \Closure(User, Organization): ResponseInterface $request */
    #[DataProvider('requests')]
    public function test_it_refuses_employees_and_deactivated_members(\Closure $request): void
    {
        [, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $formerManager = self::addMember($organization, MembershipRole::Manager);
        $formerOwner = self::addMember($organization, MembershipRole::Owner);
        OrganizationMother::deactivateMember($organization, $formerManager);
        OrganizationMother::deactivateMember($organization, $formerOwner);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        foreach ([$employee, $formerManager, $formerOwner] as $user) {
            $request($user, $organization);
            self::assertResponseStatusCodeSame(403);
        }
    }

    public function test_it_refuses_an_employee_before_looking_at_the_register(): void
    {
        [, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);

        static::clientFor($employee, $organization)->request('GET', '/api/revenue-entries', ['query' => ['from' => '2026-11-01', 'to' => '2026-11-30', 'registerId' => Uuid::v7()->toRfc4122()]]);

        self::assertResponseStatusCodeSame(403);
    }

    public function test_it_keeps_another_organizations_entries_invisible(): void
    {
        [$owner, $here] = self::memberWithRole(MembershipRole::Owner);
        [$ownerElsewhere, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        // The owner also belongs to the other organization, so only the selected organization tells them apart.
        OrganizationMother::addMember($elsewhere, $owner, MembershipRole::Manager);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $bar = self::addRegister($here, 'Bar');
        $barElsewhere = self::addRegister($elsewhere, 'Bar');
        self::mockTime(self::NOW);
        $mine = self::record($owner, $here, $bar, '2026-11-01', [['method' => 'cash', 'amount' => 100]]);
        self::record($ownerElsewhere, $elsewhere, $barElsewhere, '2026-11-01', [['method' => 'cash', 'amount' => 5_000]], [['rate' => '23', 'gross' => 5_000, 'vat' => 935]]);

        self::assertSame([$mine], self::list($owner, $here, ['from' => '2026-11-01', 'to' => '2026-11-30'])->toArray());
        $summary = self::summary($owner, $here, ['from' => '2026-11-01', 'to' => '2026-11-30'])->toArray();
        self::assertSame(1, $summary['entryCount']);
        self::assertSame(100, $summary['currencies'][0]['total']);
        self::assertSame([], $summary['currencies'][0]['vatLines']);
        self::assertSame([$bar->getId()->toRfc4122()], array_column($summary['currencies'][0]['registers'], 'registerId'));

        self::assertSame(5_000, self::summary($owner, $elsewhere, ['from' => '2026-11-01', 'to' => '2026-11-30'])->toArray()['currencies'][0]['total']);
    }

    /**
     * Records an entry through the API and returns it as GET /revenue-entries/{id} shows it.
     *
     * @param list<array{method: string, amount: int}>        $payments
     * @param list<array{rate: string, gross: int, vat: int}> $vatLines
     *
     * @return array<string, mixed>
     */
    private static function record(User $user, Organization $organization, Register $register, string $businessDate, array $payments, array $vatLines = []): array
    {
        $response = static::clientFor($user, $organization)->request('POST', '/api/revenue-entries', ['json' => [
            'registerId' => $register->getId()->toRfc4122(),
            'businessDate' => $businessDate,
            'payments' => $payments,
            'vatLines' => $vatLines,
        ]]);
        self::assertResponseStatusCodeSame(201);

        return $response->toArray();
    }

    /** @param array<string, string> $query */
    private static function list(User $user, Organization $organization, array $query): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('GET', '/api/revenue-entries', ['query' => $query]);
    }

    /** @param array<string, string> $query */
    private static function summary(User $user, Organization $organization, array $query): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('GET', '/api/revenue-summary', ['query' => $query]);
    }
}
