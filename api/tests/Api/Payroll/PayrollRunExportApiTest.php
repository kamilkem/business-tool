<?php

declare(strict_types=1);

namespace App\Tests\Api\Payroll;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixtures\OrganizationMother;
use App\User\Domain\Model\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * GET /payroll-runs/{id}/export/paychecks and /export/shifts (#65, ADR 0027 §12). Organizations are
 * Polish (PLN, Europe/Warsaw, so the CSV separator is a semicolon), created on 1 September 2026; the
 * server's time is then mocked at NOW (20 November 2026, UTC), so October has ended.
 */
final class PayrollRunExportApiTest extends PayrollApiTestCase
{
    private const string BOM = "\u{FEFF}";

    public function test_it_exports_an_approved_run_as_a_paychecks_file_and_a_shifts_file(): void
    {
        [$owner, $organization] = self::organization();
        $anna = self::addNamedMember($organization, 'Anna', 'Kowalska');
        $manager = self::addMember(self::reload($organization), MembershipRole::Manager);
        self::addHourlyRate($organization, $anna, '2026-01-01', 3_000);
        self::mockTime(self::NOW);
        self::shift($manager, $organization, $anna, '2026-10-03T18:00:00+02:00', '2026-10-03T22:08:00+02:00');
        // A night into November belongs wholly to October.
        self::shift($manager, $organization, $anna, '2026-10-31T20:00:00+01:00', '2026-11-01T03:00:00+01:00');
        $runId = self::approvedRun($owner, $manager, $organization, ['userId' => $anna->getId()->toRfc4122(), 'label' => 'Advance', 'amount' => -5_000]);
        $paycheckId = static::clientFor($owner, $organization)->request('GET', '/api/payroll-runs/'.$runId)->toArray()['paychecks'][0]['id'];
        static::clientFor($owner, $organization)->request('POST', \sprintf('/api/payroll-runs/%s/paychecks/%s/payment', $runId, $paycheckId), ['json' => ['paidOn' => '2026-11-10', 'method' => 'transfer']]);
        self::assertResponseIsSuccessful();

        $paychecks = self::export($manager, $organization, $runId, 'paychecks');

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename=payroll-2026-10-paychecks.csv');
        self::assertSame(
            self::BOM
            ."Month;First name;Last name;Paid time;Paid hours;Pay for hours;Adjustments;Total;Currency;Run status;Paid on;Payment method\r\n"
            ."2026-10;Anna;Kowalska;11:15:00;11,25;337,50;-50,00;287,50;PLN;approved;2026-11-10;transfer\r\n",
            $paychecks->getContent(),
        );

        $shifts = self::export($owner, $organization, $runId, 'shifts');

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename=payroll-2026-10-shifts.csv');
        self::assertSame(
            self::BOM
            ."Month;First name;Last name;Date;Start;End;Length;Length (hours);Paid length;Paid hours;Rate kind;Rate per hour;Amount;Currency\r\n"
            ."2026-10;Anna;Kowalska;2026-10-03;2026-10-03 18:00;2026-10-03 22:08;4:08:00;4,13;4:15:00;4,25;hourly;30,00;127,50;PLN\r\n"
            ."2026-10;Anna;Kowalska;2026-10-31;2026-10-31 20:00;2026-11-01 03:00;7:00:00;7,00;7:00:00;7,00;hourly;30,00;210,00;PLN\r\n",
            $shifts->getContent(),
        );
    }

    public function test_it_writes_the_file_with_the_organizations_csv_separator(): void
    {
        [$owner, $organization] = self::organization();
        $anna = self::addNamedMember($organization, 'Anna', 'Kowalska');
        self::addHourlyRate($organization, $anna, '2026-01-01', 3_000);
        self::mockTime(self::NOW);
        self::shift($owner, $organization, $anna, '2026-10-03T18:00:00+02:00', '2026-10-03T20:30:00+02:00');
        $runId = self::approvedRun($owner, $owner, $organization);
        static::clientFor($owner)->request('PATCH', '/api/organizations/'.$organization->getId()->toRfc4122(), ['json' => ['csvSeparator' => 'comma'], 'headers' => self::MERGE_PATCH]);
        self::assertResponseIsSuccessful();

        $paychecks = self::export($owner, $organization, $runId, 'paychecks')->getContent();

        self::assertStringEndsWith("\r\n2026-10,Anna,Kowalska,2:30:00,2.50,75.00,0.00,75.00,PLN,approved,,\r\n", $paychecks);
    }

    public function test_it_refuses_to_export_a_draft_with_409(): void
    {
        [$owner, $organization, $employee] = self::organizationWithEmployee();
        self::mockTime(self::NOW);
        self::shift($owner, $organization, $employee, '2026-10-03T18:00:00+02:00', '2026-10-03T20:00:00+02:00');
        $runId = self::generate($owner, $organization);

        foreach (['paychecks', 'shifts'] as $part) {
            $response = self::export($owner, $organization, $runId, $part);
            self::assertResponseStatusCodeSame(409);
            self::assertStringContainsString('is a draft', $response->getContent(false));
        }
    }

    public function test_it_answers_404_for_a_run_that_is_not_there(): void
    {
        [$owner, $organization] = self::organization();
        self::mockTime(self::NOW);

        self::export($owner, $organization, Uuid::v7()->toRfc4122(), 'paychecks');
        self::assertResponseStatusCodeSame(404);
        self::export($owner, $organization, Uuid::v7()->toRfc4122(), 'shifts');
        self::assertResponseStatusCodeSame(404);
    }

    /** @return iterable<string, array{string, int}> */
    public static function roles(): iterable
    {
        yield 'owner' => ['owner', 200];
        yield 'manager' => ['manager', 200];
        yield 'employee, even with a paycheck in the run' => ['employee', 403];
        yield 'deactivated manager' => ['former manager', 403];
        yield 'not a member' => ['outsider', 403];
    }

    #[DataProvider('roles')]
    public function test_it_lets_only_owners_and_managers_export(string $actor, int $status): void
    {
        [$owner, $organization, $employee, [$manager, $formerManager]] = self::organizationWithEmployee([MembershipRole::Manager, MembershipRole::Manager]);
        $outsider = UserFactory::createOne();
        self::mockTime(self::NOW);
        self::shift($owner, $organization, $employee, '2026-10-03T18:00:00+02:00', '2026-10-03T20:00:00+02:00');
        $runId = self::approvedRun($owner, $owner, $organization);
        OrganizationMother::deactivateMember(self::reload($organization), $formerManager);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $user = match ($actor) {
            'owner' => $owner,
            'manager' => $manager,
            'employee' => $employee,
            'former manager' => $formerManager,
            default => $outsider,
        };

        foreach (['paychecks', 'shifts'] as $part) {
            self::export($user, $organization, $runId, $part);
            self::assertResponseStatusCodeSame($status, $part);
        }
    }

    public function test_it_does_not_export_another_organizations_run(): void
    {
        [$owner, $organization] = self::organization();
        [$otherOwner, $other, $otherEmployee] = self::organizationWithEmployee();
        self::mockTime(self::NOW);
        self::shift($otherOwner, $other, $otherEmployee, '2026-10-03T18:00:00+02:00', '2026-10-03T20:00:00+02:00');
        $otherRun = self::approvedRun($otherOwner, $otherOwner, $other);

        self::export($owner, $organization, $otherRun, 'paychecks');
        self::assertResponseStatusCodeSame(404);
        self::export($owner, $organization, $otherRun, 'shifts');
        self::assertResponseStatusCodeSame(404);
        self::export($owner, $other, $otherRun, 'paychecks');
        self::assertResponseStatusCodeSame(403, 'Not a member of the other organization');
    }

    /** @return array{User, Organization} the owner and the organization, created on 1 September */
    private static function organization(): array
    {
        self::mockTime('2026-09-01 08:00:00');

        return self::memberWithRole(MembershipRole::Owner);
    }

    /**
     * The owner, the organization and an employee with a rate; the other members are added first, as
     * arranging the rate detaches the organization.
     *
     * @param list<MembershipRole> $others
     *
     * @return array{User, Organization, User, list<User>}
     */
    private static function organizationWithEmployee(array $others = []): array
    {
        [$owner, $organization] = self::organization();
        $employee = self::addMember($organization, MembershipRole::Employee);
        $members = array_map(static fn (MembershipRole $role): User => self::addMember($organization, $role), $others);
        self::addHourlyRate($organization, $employee, '2026-01-01');

        return [$owner, $organization, $employee, $members];
    }

    private static function addNamedMember(Organization $organization, string $firstName, string $lastName): User
    {
        $user = UserFactory::createOne(['firstName' => $firstName, 'lastName' => $lastName]);
        OrganizationMother::addMember(self::reload($organization), $user, MembershipRole::Employee);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        return $user;
    }

    private static function reload(Organization $organization): Organization
    {
        return static::getContainer()->get(EntityManagerInterface::class)->find(Organization::class, $organization->getId()) ?? throw new \LogicException('Organization expected.');
    }

    private static function shift(User $actor, Organization $organization, User $person, string $startedAt, string $endedAt): void
    {
        static::clientFor($actor, $organization)->request('POST', '/api/shifts', ['json' => [
            'userId' => $person->getId()->toRfc4122(),
            'startedAt' => $startedAt,
            'endedAt' => $endedAt,
        ]]);
        self::assertResponseStatusCodeSame(201);
    }

    /** Generates October as a draft with 15-minute rounding to the nearest; returns its id. */
    private static function generate(User $actor, Organization $organization): string
    {
        $response = static::clientFor($actor, $organization)->request('POST', '/api/payroll-runs', ['json' => ['month' => '2026-10', 'roundingUnit' => '15', 'roundingDirection' => 'nearest']]);
        self::assertResponseStatusCodeSame(201);

        return $response->toArray()['id'];
    }

    /**
     * Generates October as the preparer, adds the adjustment if any, and approves it as the owner.
     *
     * @param array<string, mixed>|null $adjustment
     */
    private static function approvedRun(User $owner, User $preparer, Organization $organization, ?array $adjustment = null): string
    {
        $runId = self::generate($preparer, $organization);
        if (null !== $adjustment) {
            static::clientFor($preparer, $organization)->request('POST', \sprintf('/api/payroll-runs/%s/adjustments', $runId), ['json' => $adjustment]);
            self::assertResponseStatusCodeSame(201);
        }
        static::clientFor($owner, $organization)->request('POST', \sprintf('/api/payroll-runs/%s/approve', $runId));
        self::assertResponseStatusCodeSame(200);

        return $runId;
    }

    private static function export(User $user, Organization $organization, string $runId, string $part): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('GET', \sprintf('/api/payroll-runs/%s/export/%s', $runId, $part), ['headers' => ['Accept' => 'text/csv']]);
    }
}
