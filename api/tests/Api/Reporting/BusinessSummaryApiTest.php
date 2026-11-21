<?php

declare(strict_types=1);

namespace App\Tests\Api\Reporting;

use ApiPlatform\Test\Client;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Tests\Api\Payroll\PayrollApiTestCase;
use App\Tests\Fixtures\OrganizationMother;
use App\User\Domain\Model\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * GET /business-summary?from=&to= (#67, ADR 0029). Organizations are in PLN and Europe/Warsaw; the
 * server's time is NOW (20 November 2026), so October has ended and November is the current month.
 * Income, expenses, shifts and payroll runs are arranged through their own endpoints.
 */
final class BusinessSummaryApiTest extends PayrollApiTestCase
{
    /** @return iterable<string, array{MembershipRole}> */
    public static function managingRoles(): iterable
    {
        yield 'owner' => [MembershipRole::Owner];
        yield 'manager' => [MembershipRole::Manager];
    }

    #[DataProvider('managingRoles')]
    public function test_it_sums_income_expenses_and_payroll_per_month(MembershipRole $role): void
    {
        [$owner, $organization, $employee] = self::organizationWithEmployee();
        $reader = MembershipRole::Owner === $role ? $owner : self::addMember($organization, $role);
        self::addHourlyRate($organization, $employee, '2026-01-01', 3_000);
        self::mockTime(self::NOW);
        $bar = self::register($owner, $organization);
        self::revenue($owner, $organization, $bar, '2026-10-10', [['method' => 'cash', 'amount' => 100_000], ['method' => 'card', 'amount' => 23_000]], [['rate' => '23', 'gross' => 123_000, 'vat' => 23_000]]);
        self::revenue($owner, $organization, $bar, '2026-10-11', [['method' => 'cash', 'amount' => 10_000]]);
        self::revenue($owner, $organization, $bar, '2026-11-02', [['method' => 'cash', 'amount' => 20_000]]);
        self::expense($owner, $organization, '2026-10-15', 40_000, 9_200, '23');
        self::expense($owner, $organization, '2026-10-20', 5_000, 400, '8');
        self::shift($owner, $organization, $employee, '2026-10-03T18:00:00+02:00', '2026-10-03T22:00:00+02:00');
        self::shift($owner, $organization, $employee, '2026-11-05T18:00:00+01:00', '2026-11-05T20:00:00+01:00');
        $october = self::generate($owner, $organization, '2026-10');
        self::client($owner, $organization)->request('POST', \sprintf('/api/payroll-runs/%s/approve', $october));
        self::assertResponseStatusCodeSame(200);
        $november = self::generate($owner, $organization, '2026-11');

        $response = self::summary($reader, $organization, ['from' => '2026-09', 'to' => '2026-11']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([
            'from' => '2026-09',
            'to' => '2026-11',
            'currency' => 'PLN',
            'months' => [
                [
                    'month' => '2026-09',
                    'firstDate' => '2026-09-01',
                    'lastDate' => '2026-09-30',
                    'complete' => true,
                    'payrollStatus' => 'none',
                    'payrollRunId' => null,
                    'currencies' => [self::zeros()],
                ],
                [
                    'month' => '2026-10',
                    'firstDate' => '2026-10-01',
                    'lastDate' => '2026-10-31',
                    'complete' => true,
                    'payrollStatus' => 'approved',
                    'payrollRunId' => $october,
                    'currencies' => [[
                        'currency' => 'PLN',
                        'incomeGross' => 133_000,
                        'incomeVat' => 23_000,
                        'incomeNet' => 110_000,
                        'revenueEntryCount' => 2,
                        'expensesGross' => 54_600,
                        'expensesVat' => 9_600,
                        'expensesNet' => 45_000,
                        'invoiceCount' => 2,
                        'payrollCost' => 12_000,
                        'payrollDraftTotal' => null,
                        'result' => 53_000,
                    ]],
                ],
                [
                    'month' => '2026-11',
                    'firstDate' => '2026-11-01',
                    'lastDate' => '2026-11-30',
                    'complete' => false,
                    'payrollStatus' => 'draft',
                    'payrollRunId' => $november,
                    // The draft is an estimate: it is not a cost yet.
                    'currencies' => [[...self::zeros(), 'incomeGross' => 20_000, 'incomeNet' => 20_000, 'revenueEntryCount' => 1, 'payrollDraftTotal' => 6_000, 'result' => 20_000]],
                ],
            ],
        ], $response->toArray());
    }

    public function test_it_gives_zeros_in_the_organizations_currency_for_an_empty_month(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        self::mockTime(self::NOW);

        $response = self::summary($owner, $organization, ['from' => '2026-11', 'to' => '2026-11']);

        self::assertResponseStatusCodeSame(200);
        $month = $response->toArray()['months'];
        self::assertCount(1, $month);
        self::assertSame(['none', null, false], [$month[0]['payrollStatus'], $month[0]['payrollRunId'], $month[0]['complete']]);
        self::assertSame([self::zeros()], $month[0]['currencies']);
    }

    public function test_it_covers_twelve_months(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        self::mockTime(self::NOW);

        $months = self::summary($owner, $organization, ['from' => '2025-12', 'to' => '2026-11'])->toArray()['months'];

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['2025-12', '2026-11'], [$months[0]['month'], $months[11]['month']]);
        self::assertCount(12, $months);
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function invalidPeriods(): iterable
    {
        yield 'no from' => [['to' => '2026-10'], 'from'];
        yield 'no to' => [['from' => '2026-10'], 'to'];
        yield 'a date, not a month' => [['from' => '2026-10-01', 'to' => '2026-10'], 'from'];
        yield 'month 13' => [['from' => '2026-10', 'to' => '2026-13'], 'to'];
        yield 'to before from' => [['from' => '2026-10', 'to' => '2026-09'], 'to'];
        yield 'more than 12 months' => [['from' => '2025-10', 'to' => '2026-10'], 'to'];
    }

    /** @param array<string, string> $query */
    #[DataProvider('invalidPeriods')]
    public function test_it_answers_422_for_an_invalid_period(array $query, string $path): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        self::mockTime(self::NOW);

        $response = self::summary($owner, $organization, $query);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([$path], array_column($response->toArray(false)['violations'], 'propertyPath'));
    }

    public function test_it_explains_the_period_in_the_requests_language(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        self::mockTime(self::NOW);

        $response = self::client($owner, $organization)->request('GET', '/api/business-summary', [
            'query' => ['from' => '2025-10', 'to' => '2026-10'],
            'headers' => ['Accept-Language' => 'pl'],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Okres nie może być dłuższy niż 12 miesięcy.', $response->toArray(false)['violations'][0]['message']);
    }

    public function test_it_answers_422_for_a_month_that_has_not_started(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        self::mockTime(self::NOW);

        $response = self::summary($owner, $organization, ['from' => '2026-11', 'to' => '2026-12']);

        self::assertResponseStatusCodeSame(422);
        $problem = $response->toArray(false);
        self::assertSame('reporting.business_summary_month_in_the_future', $problem['code']);
        self::assertSame(['month' => '2026-12'], $problem['parameters']);
    }

    public function test_it_refuses_employees_and_deactivated_members(): void
    {
        [, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $formerManager = self::addMember($organization, MembershipRole::Manager);
        $formerOwner = self::addMember($organization, MembershipRole::Owner);
        OrganizationMother::deactivateMember($organization, $formerManager);
        OrganizationMother::deactivateMember($organization, $formerOwner);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        self::mockTime(self::NOW);

        $response = self::summary($employee, $organization, ['from' => '2026-11', 'to' => '2026-11']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('reporting.business_summary_forbidden', $response->toArray(false)['code']);

        foreach ([$formerManager, $formerOwner] as $user) {
            self::summary($user, $organization, ['from' => '2026-11', 'to' => '2026-11']);
            self::assertResponseStatusCodeSame(403);
        }
    }

    public function test_it_refuses_someone_who_is_not_a_member_or_not_signed_in(): void
    {
        [, $organization] = self::memberWithRole(MembershipRole::Owner);
        [$stranger] = self::memberWithRole(MembershipRole::Owner);
        self::mockTime(self::NOW);

        self::summary($stranger, $organization, ['from' => '2026-11', 'to' => '2026-11']);
        self::assertResponseStatusCodeSame(403);

        static::createClient()->request('GET', '/api/business-summary', ['query' => ['from' => '2026-11', 'to' => '2026-11'], 'headers' => ['Accept' => 'application/json']]);
        self::assertResponseStatusCodeSame(401);
    }

    public function test_it_keeps_another_organizations_figures_invisible(): void
    {
        [$owner, $here] = self::memberWithRole(MembershipRole::Owner);
        [$ownerElsewhere, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        // The owner also manages the other organization, so only the selected organization tells them apart.
        OrganizationMother::addMember(self::reload($elsewhere), self::reloadUser($owner), MembershipRole::Manager);
        $employeeElsewhere = self::addMember($elsewhere, MembershipRole::Employee);
        self::addHourlyRate($elsewhere, $employeeElsewhere, '2026-01-01', 3_000);
        self::mockTime(self::NOW);
        $barElsewhere = self::register($ownerElsewhere, $elsewhere);
        self::revenue($ownerElsewhere, $elsewhere, $barElsewhere, '2026-10-10', [['method' => 'cash', 'amount' => 50_000]]);
        self::expense($ownerElsewhere, $elsewhere, '2026-10-15', 1_000, 230, '23');
        self::shift($ownerElsewhere, $elsewhere, $employeeElsewhere, '2026-10-03T18:00:00+02:00', '2026-10-03T22:00:00+02:00');
        $runElsewhere = self::generate($ownerElsewhere, $elsewhere, '2026-10');
        $bar = self::register($owner, $here);
        self::revenue($owner, $here, $bar, '2026-10-10', [['method' => 'cash', 'amount' => 100]]);

        $mine = self::summary($owner, $here, ['from' => '2026-10', 'to' => '2026-10'])->toArray()['months'][0];

        self::assertSame(['none', null], [$mine['payrollStatus'], $mine['payrollRunId']]);
        self::assertSame([[...self::zeros(), 'incomeGross' => 100, 'incomeNet' => 100, 'revenueEntryCount' => 1, 'result' => 100]], $mine['currencies']);

        $theirs = self::summary($owner, $elsewhere, ['from' => '2026-10', 'to' => '2026-10'])->toArray()['months'][0];
        self::assertSame(['draft', $runElsewhere], [$theirs['payrollStatus'], $theirs['payrollRunId']]);
        self::assertSame([50_000, 1, 12_000], [$theirs['currencies'][0]['incomeGross'], $theirs['currencies'][0]['invoiceCount'], $theirs['currencies'][0]['payrollDraftTotal']]);
    }

    /** @return array<string, int|string|null> a PLN month with nothing in it */
    private static function zeros(): array
    {
        return [
            'currency' => 'PLN',
            'incomeGross' => 0,
            'incomeVat' => 0,
            'incomeNet' => 0,
            'revenueEntryCount' => 0,
            'expensesGross' => 0,
            'expensesVat' => 0,
            'expensesNet' => 0,
            'invoiceCount' => 0,
            'payrollCost' => 0,
            'payrollDraftTotal' => null,
            'result' => 0,
        ];
    }

    /** @return array{User, Organization, User} */
    private static function organizationWithEmployee(): array
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        return [$owner, $organization, self::addMember($organization, MembershipRole::Employee)];
    }

    private static function reload(Organization $organization): Organization
    {
        return static::getContainer()->get(EntityManagerInterface::class)->find(Organization::class, $organization->getId()) ?? throw new \LogicException('Organization expected.');
    }

    private static function reloadUser(User $user): User
    {
        return static::getContainer()->get(EntityManagerInterface::class)->find(User::class, $user->getId()) ?? throw new \LogicException('User expected.');
    }

    private static function client(User $user, Organization $organization): Client
    {
        return static::clientFor($user, $organization);
    }

    private static function register(User $owner, Organization $organization): string
    {
        $response = self::client($owner, $organization)->request('POST', '/api/registers', ['json' => ['name' => 'Bar']]);
        self::assertResponseStatusCodeSame(201);

        return $response->toArray()['id'];
    }

    /**
     * @param list<array{method: string, amount: int}>        $payments
     * @param list<array{rate: string, gross: int, vat: int}> $vatLines
     */
    private static function revenue(User $owner, Organization $organization, string $registerId, string $businessDate, array $payments, array $vatLines = []): void
    {
        self::client($owner, $organization)->request('POST', '/api/revenue-entries', ['json' => [
            'registerId' => $registerId,
            'businessDate' => $businessDate,
            'payments' => $payments,
            'vatLines' => $vatLines,
        ]]);
        self::assertResponseStatusCodeSame(201);
    }

    private static function expense(User $owner, Organization $organization, string $issueDate, int $net, int $vat, string $rate): void
    {
        self::client($owner, $organization)->request('POST', '/api/expenses', ['json' => [
            'sellerName' => 'Hurtownia',
            'issueDate' => $issueDate,
            'netTotal' => $net,
            'vatTotal' => $vat,
            'grossTotal' => $net + $vat,
            'vatLines' => [['rate' => $rate, 'net' => $net, 'vat' => $vat]],
        ]]);
        self::assertResponseStatusCodeSame(201);
    }

    private static function shift(User $actor, Organization $organization, User $person, string $startedAt, string $endedAt): void
    {
        self::client($actor, $organization)->request('POST', '/api/shifts', ['json' => [
            'userId' => $person->getId()->toRfc4122(),
            'startedAt' => $startedAt,
            'endedAt' => $endedAt,
        ]]);
        self::assertResponseStatusCodeSame(201);
    }

    /** Generates the month's payroll run as a draft; returns its id. */
    private static function generate(User $owner, Organization $organization, string $month): string
    {
        $response = self::client($owner, $organization)->request('POST', '/api/payroll-runs', ['json' => ['month' => $month]]);
        self::assertResponseStatusCodeSame(201);

        return $response->toArray()['id'];
    }

    /** @param array<string, string> $query */
    private static function summary(User $user, Organization $organization, array $query): ResponseInterface
    {
        return self::client($user, $organization)->request('GET', '/api/business-summary', ['query' => $query]);
    }
}
