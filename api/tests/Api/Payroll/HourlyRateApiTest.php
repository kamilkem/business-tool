<?php

declare(strict_types=1);

namespace App\Tests\Api\Payroll;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Tests\Fixtures\OrganizationMother;
use App\User\Domain\Model\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * GET, POST, PATCH, DELETE /hourly-rates (#60, ADR 0027 §3 and §10). The server's time is mocked at
 * NOW (UTC); organizations are in PLN.
 */
final class HourlyRateApiTest extends PayrollApiTestCase
{
    /** @return iterable<string, array{MembershipRole}> */
    public static function managingRoles(): iterable
    {
        yield 'owner' => [MembershipRole::Owner];
        yield 'manager' => [MembershipRole::Manager];
    }

    #[DataProvider('managingRoles')]
    public function test_it_lets_owners_and_managers_set_a_rate(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        $employee = self::addMember($organization, MembershipRole::Employee);
        self::mockTime(self::NOW);

        $response = self::add($actor, $organization, ['userId' => $employee->getId()->toRfc4122(), 'effectiveFrom' => '2026-12-01', 'amount' => 3050]);

        self::assertResponseStatusCodeSame(201);
        $rate = $response->toArray();
        self::assertInstanceOf(UuidV7::class, Uuid::fromString($rate['id']));
        self::assertSame([
            'id' => $rate['id'],
            'userId' => $employee->getId()->toRfc4122(),
            'effectiveFrom' => '2026-12-01',
            'effectiveTo' => null,
            'amount' => 3050,
            'currency' => 'PLN',
            'createdAt' => '2026-11-20T10:00:00+00:00',
            'updatedAt' => null,
        ], $rate);

        self::assertSame($rate, self::get($actor, $organization, $rate['id'])->toArray());
    }

    public function test_it_lets_a_manager_set_their_own_rate_and_a_deactivated_members(): void
    {
        [$manager, $organization] = self::memberWithRole(MembershipRole::Manager);
        $former = self::addMember($organization, MembershipRole::Employee);
        OrganizationMother::deactivateMember($organization, $former);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        self::add($manager, $organization, ['userId' => $manager->getId()->toRfc4122(), 'effectiveFrom' => '2026-12-01', 'amount' => 4000]);
        self::assertResponseStatusCodeSame(201);
        self::add($manager, $organization, ['userId' => $former->getId()->toRfc4122(), 'effectiveFrom' => '2026-01-01', 'amount' => 2800]);
        self::assertResponseStatusCodeSame(201);

        self::assertCount(1, self::list($manager, $organization, $former->getId()->toRfc4122())->toArray());
    }

    public function test_it_refuses_employees_any_write_and_someone_elses_rates(): void
    {
        [$employee, $organization] = self::memberWithRole(MembershipRole::Employee);
        $owner = OrganizationMother::ownerOf($organization);
        $own = self::addHourlyRate($organization, $employee, '2026-03-01');
        $owners = self::addHourlyRate($organization, $owner, '2026-03-01');

        self::add($employee, $organization, ['userId' => $employee->getId()->toRfc4122(), 'effectiveFrom' => '2026-12-01', 'amount' => 9999]);
        self::assertResponseStatusCodeSame(403);
        self::patch($employee, $organization, $own->getId()->toRfc4122(), ['amount' => 9999]);
        self::assertResponseStatusCodeSame(403);
        self::delete($employee, $organization, $own->getId()->toRfc4122());
        self::assertResponseStatusCodeSame(403);
        self::get($employee, $organization, $owners->getId()->toRfc4122());
        self::assertResponseStatusCodeSame(403);
        self::list($employee, $organization, $owner->getId()->toRfc4122());
        self::assertResponseStatusCodeSame(403);

        self::assertSame(3000, self::get($employee, $organization, $own->getId()->toRfc4122())->toArray()['amount']);
        self::assertSame([$own->getId()->toRfc4122()], array_column(self::list($employee, $organization)->toArray(), 'id'));
        self::assertSame([$own->getId()->toRfc4122()], array_column(self::list($employee, $organization, $employee->getId()->toRfc4122())->toArray(), 'id'));
    }

    public function test_it_lists_everyones_rates_newest_first_with_the_date_until_which_each_is_in_effect(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $old = self::addHourlyRate($organization, $employee, '2026-03-01', 2800);
        $new = self::addHourlyRate($organization, $employee, '2026-09-01', 3000);
        $owners = self::addHourlyRate($organization, $owner, '2026-01-01', 5000);

        $rates = self::list($owner, $organization)->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertEqualsCanonicalizing([$old->getId()->toRfc4122(), $new->getId()->toRfc4122(), $owners->getId()->toRfc4122()], array_column($rates, 'id'));
        $employees = self::list($owner, $organization, $employee->getId()->toRfc4122())->toArray();
        self::assertSame([['2026-09-01', null, 3000], ['2026-03-01', '2026-08-31', 2800]], array_map(static fn (array $rate): array => [$rate['effectiveFrom'], $rate['effectiveTo'], $rate['amount']], $employees));
    }

    /** @return iterable<string, array{array<string, mixed>, list<string>}> */
    public static function invalidRates(): iterable
    {
        yield 'nothing' => [[], ['userId', 'effectiveFrom', 'amount']];
        yield 'userId not a UUID' => [['userId' => 'anna', 'effectiveFrom' => '2026-12-01', 'amount' => 3000], ['userId']];
        yield 'no date' => [['userId' => '{user}', 'amount' => 3000], ['effectiveFrom']];
        yield 'not a date' => [['userId' => '{user}', 'effectiveFrom' => '2026-02-30', 'amount' => 3000], ['effectiveFrom']];
        yield 'a date-time' => [['userId' => '{user}', 'effectiveFrom' => '2026-12-01T00:00:00Z', 'amount' => 3000], ['effectiveFrom']];
        yield 'zero' => [['userId' => '{user}', 'effectiveFrom' => '2026-12-01', 'amount' => 0], ['amount']];
        yield 'negative' => [['userId' => '{user}', 'effectiveFrom' => '2026-12-01', 'amount' => -100], ['amount']];
        yield 'a decimal' => [['userId' => '{user}', 'effectiveFrom' => '2026-12-01', 'amount' => 30.5], ['amount']];
        yield 'a string' => [['userId' => '{user}', 'effectiveFrom' => '2026-12-01', 'amount' => '3000'], ['amount']];
        yield 'too large' => [['userId' => '{user}', 'effectiveFrom' => '2026-12-01', 'amount' => 2_147_483_648], ['amount']];
    }

    /**
     * @param array<string, mixed> $body
     * @param list<string>         $fields
     */
    #[DataProvider('invalidRates')]
    public function test_it_answers_422_with_violations_for_an_invalid_rate(array $body, array $fields): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        if (($body['userId'] ?? null) === '{user}') {
            $body['userId'] = $owner->getId()->toRfc4122();
        }

        $response = self::add($owner, $organization, $body);

        self::assertResponseStatusCodeSame(422);
        $paths = array_values(array_unique(array_column($response->toArray(false)['violations'] ?? [], 'propertyPath')));
        sort($paths);
        sort($fields);
        self::assertSame($fields, $paths);
        self::assertSame([], self::list($owner, $organization)->toArray());
    }

    public function test_it_answers_422_for_an_invalid_change_and_a_user_id_filter_that_is_not_a_uuid(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $rate = self::addHourlyRate($organization, $owner, '2026-03-01');

        foreach ([['amount' => 0], ['amount' => null], ['effectiveFrom' => 'soon'], ['effectiveFrom' => null]] as $body) {
            self::patch($owner, $organization, $rate->getId()->toRfc4122(), $body);
            self::assertResponseStatusCodeSame(422, (string) json_encode($body));
        }

        self::list($owner, $organization, 'anna');
        self::assertResponseStatusCodeSame(422);
    }

    public function test_it_answers_404_for_a_user_or_a_rate_that_is_not_the_organizations(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [$otherOwner, $otherOrganization] = self::memberWithRole(MembershipRole::Owner);
        $elsewhere = self::addHourlyRate($otherOrganization, $otherOwner, '2026-03-01');

        self::add($owner, $organization, ['userId' => $otherOwner->getId()->toRfc4122(), 'effectiveFrom' => '2026-12-01', 'amount' => 3000]);
        self::assertResponseStatusCodeSame(404);
        self::list($owner, $organization, $otherOwner->getId()->toRfc4122());
        self::assertResponseStatusCodeSame(404);

        foreach ([$elsewhere->getId()->toRfc4122(), Uuid::v7()->toRfc4122()] as $id) {
            self::get($owner, $organization, $id);
            self::assertResponseStatusCodeSame(404);
            self::patch($owner, $organization, $id, ['amount' => 1]);
            self::assertResponseStatusCodeSame(404);
            self::delete($owner, $organization, $id);
            self::assertResponseStatusCodeSame(404);
        }
    }

    public function test_it_never_shows_another_organizations_rates(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [$otherOwner, $otherOrganization] = self::memberWithRole(MembershipRole::Owner);
        // The same person in both organizations: each sees only its own rates of them.
        OrganizationMother::addMember($otherOrganization, $owner, MembershipRole::Employee);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $mine = self::addHourlyRate($organization, $owner, '2026-03-01', 3000);
        self::addHourlyRate($otherOrganization, $owner, '2026-03-01', 9000);
        self::addHourlyRate($otherOrganization, $otherOwner, '2026-03-01', 9000);

        self::assertSame([$mine->getId()->toRfc4122()], array_column(self::list($owner, $organization)->toArray(), 'id'));
        self::assertSame([3000], array_column(self::list($owner, $organization, $owner->getId()->toRfc4122())->toArray(), 'amount'));

        // A rate from the same date in another organization is no conflict.
        self::add($otherOwner, $otherOrganization, ['userId' => $otherOwner->getId()->toRfc4122(), 'effectiveFrom' => '2026-04-01', 'amount' => 1]);
        self::assertResponseStatusCodeSame(201);
    }

    public function test_it_answers_409_for_a_second_rate_from_the_same_date(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        self::addHourlyRate($organization, $employee, '2026-12-01');
        $other = self::addHourlyRate($organization, $employee, '2027-01-01');

        self::add($owner, $organization, ['userId' => $employee->getId()->toRfc4122(), 'effectiveFrom' => '2026-12-01', 'amount' => 3500]);
        self::assertResponseStatusCodeSame(409);
        self::patch($owner, $organization, $other->getId()->toRfc4122(), ['effectiveFrom' => '2026-12-01']);
        self::assertResponseStatusCodeSame(409);
    }

    public function test_it_changes_part_of_a_rate_and_deletes_one(): void
    {
        [$manager, $organization] = self::memberWithRole(MembershipRole::Manager);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $old = self::addHourlyRate($organization, $employee, '2026-03-01', 2800);
        $new = self::addHourlyRate($organization, $employee, '2026-12-01', 3000);
        self::mockTime(self::NOW);

        $changed = self::patch($manager, $organization, $new->getId()->toRfc4122(), ['amount' => 3100])->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['2026-12-01', 3100, 'PLN', '2026-11-20T10:00:00+00:00'], [$changed['effectiveFrom'], $changed['amount'], $changed['currency'], $changed['updatedAt']]);

        self::patch($manager, $organization, $new->getId()->toRfc4122(), ['effectiveFrom' => '2026-12-15']);
        self::assertSame('2026-12-14', self::get($manager, $organization, $old->getId()->toRfc4122())->toArray()['effectiveTo']);

        self::delete($manager, $organization, $new->getId()->toRfc4122());
        self::assertResponseStatusCodeSame(204);
        self::get($manager, $organization, $new->getId()->toRfc4122());
        self::assertResponseStatusCodeSame(404);
        self::assertNull(self::get($manager, $organization, $old->getId()->toRfc4122())->toArray()['effectiveTo']);
    }

    public function test_it_answers_409_for_a_change_that_alters_the_rate_of_a_locked_date(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $september = self::addHourlyRate($organization, $employee, '2026-09-01', 2800);
        self::lock($organization, '2026-10-01', '2026-10-31');
        $userId = $employee->getId()->toRfc4122();

        // In effect in October until the next rate: none follows, so every one of these touches October.
        self::add($owner, $organization, ['userId' => $userId, 'effectiveFrom' => '2026-10-15', 'amount' => 3000]);
        self::assertResponseStatusCodeSame(409);
        self::add($owner, $organization, ['userId' => $userId, 'effectiveFrom' => '2026-09-15', 'amount' => 3000]);
        self::assertResponseStatusCodeSame(409);
        self::patch($owner, $organization, $september->getId()->toRfc4122(), ['amount' => 2900]);
        self::assertResponseStatusCodeSame(409);
        self::delete($owner, $organization, $september->getId()->toRfc4122());
        self::assertResponseStatusCodeSame(409);

        // From the day after the locked month, nothing locked changes.
        $november = self::add($owner, $organization, ['userId' => $userId, 'effectiveFrom' => '2026-11-01', 'amount' => 3000])->toArray();
        self::assertResponseStatusCodeSame(201);
        self::patch($owner, $organization, $november['id'], ['amount' => 3100]);
        self::assertResponseStatusCodeSame(200);
        self::patch($owner, $organization, $november['id'], ['effectiveFrom' => '2026-10-31']);
        self::assertResponseStatusCodeSame(409);

        // Changing nothing is allowed.
        self::patch($owner, $organization, $september->getId()->toRfc4122(), ['amount' => 2800]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(2800, self::get($owner, $organization, $september->getId()->toRfc4122())->toArray()['amount']);
    }

    /** @param array<string, mixed> $body */
    private static function add(User $user, Organization $organization, array $body): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('POST', '/api/hourly-rates', ['json' => $body]);
    }

    private static function get(User $user, Organization $organization, string $id): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('GET', '/api/hourly-rates/'.$id);
    }

    private static function list(User $user, Organization $organization, ?string $userId = null): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('GET', '/api/hourly-rates', ['query' => null === $userId ? [] : ['userId' => $userId]]);
    }

    /** @param array<string, mixed> $body */
    private static function patch(User $user, Organization $organization, string $id, array $body): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('PATCH', '/api/hourly-rates/'.$id, ['json' => $body, 'headers' => self::MERGE_PATCH]);
    }

    private static function delete(User $user, Organization $organization, string $id): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('DELETE', '/api/hourly-rates/'.$id);
    }
}
