<?php

declare(strict_types=1);

namespace App\Tests\Api\Payroll;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Tests\Fixtures\OrganizationMother;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * GET, POST, PATCH, DELETE /day-rates (#60, ADR 0027 §3 and §10). The server's time is mocked at NOW
 * (UTC); organizations are in PLN.
 */
final class DayRateApiTest extends PayrollApiTestCase
{
    /** @return iterable<string, array{MembershipRole}> */
    public static function managingRoles(): iterable
    {
        yield 'owner' => [MembershipRole::Owner];
        yield 'manager' => [MembershipRole::Manager];
    }

    #[DataProvider('managingRoles')]
    public function test_it_lets_owners_and_managers_set_a_day_rate(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        $employee = self::addMember($organization, MembershipRole::Employee);
        self::mockTime(self::NOW);

        $response = self::add($actor, $organization, ['userId' => $employee->getId()->toRfc4122(), 'date' => '2026-12-31', 'amount' => 4500]);

        self::assertResponseStatusCodeSame(201);
        $dayRate = $response->toArray();
        self::assertSame([
            'id' => $dayRate['id'],
            'userId' => $employee->getId()->toRfc4122(),
            'date' => '2026-12-31',
            'amount' => 4500,
            'currency' => 'PLN',
            'createdAt' => '2026-11-20T10:00:00+00:00',
            'updatedAt' => null,
        ], $dayRate);
        self::assertSame($dayRate, self::get($actor, $organization, $dayRate['id'])->toArray());
    }

    public function test_it_refuses_employees_any_write_and_someone_elses_day_rates(): void
    {
        [$employee, $organization] = self::memberWithRole(MembershipRole::Employee);
        $owner = OrganizationMother::ownerOf($organization);
        $own = self::addDayRate($organization, $employee, '2026-12-31');
        $owners = self::addDayRate($organization, $owner, '2026-12-31');

        self::add($employee, $organization, ['userId' => $employee->getId()->toRfc4122(), 'date' => '2026-12-24', 'amount' => 9999]);
        self::assertResponseStatusCodeSame(403);
        self::patch($employee, $organization, $own->getId()->toRfc4122(), ['amount' => 9999]);
        self::assertResponseStatusCodeSame(403);
        self::delete($employee, $organization, $own->getId()->toRfc4122());
        self::assertResponseStatusCodeSame(403);
        self::get($employee, $organization, $owners->getId()->toRfc4122());
        self::assertResponseStatusCodeSame(403);
        self::list($employee, $organization, $owner->getId()->toRfc4122());
        self::assertResponseStatusCodeSame(403);

        self::assertSame([$own->getId()->toRfc4122()], array_column(self::list($employee, $organization)->toArray(), 'id'));
        self::assertSame(4500, self::get($employee, $organization, $own->getId()->toRfc4122())->toArray()['amount']);
    }

    /** @return iterable<string, array{array<string, mixed>, list<string>}> */
    public static function invalidDayRates(): iterable
    {
        yield 'nothing' => [[], ['amount', 'date', 'userId']];
        yield 'not a date' => [['userId' => '{user}', 'date' => '2026-13-01', 'amount' => 4500], ['date']];
        yield 'zero' => [['userId' => '{user}', 'date' => '2026-12-31', 'amount' => 0], ['amount']];
        yield 'a string' => [['userId' => '{user}', 'date' => '2026-12-31', 'amount' => '4500'], ['amount']];
    }

    /**
     * @param array<string, mixed> $body
     * @param list<string>         $fields sorted
     */
    #[DataProvider('invalidDayRates')]
    public function test_it_answers_422_with_violations_for_an_invalid_day_rate(array $body, array $fields): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        if (($body['userId'] ?? null) === '{user}') {
            $body['userId'] = $owner->getId()->toRfc4122();
        }

        $response = self::add($owner, $organization, $body);

        self::assertResponseStatusCodeSame(422);
        $paths = array_values(array_unique(array_column($response->toArray(false)['violations'] ?? [], 'propertyPath')));
        sort($paths);
        self::assertSame($fields, $paths);

        self::list($owner, $organization, 'anna');
        self::assertResponseStatusCodeSame(422);
    }

    public function test_it_answers_404_for_a_user_or_a_day_rate_that_is_not_the_organizations(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [$otherOwner, $otherOrganization] = self::memberWithRole(MembershipRole::Owner);
        $elsewhere = self::addDayRate($otherOrganization, $otherOwner, '2026-12-31');

        self::add($owner, $organization, ['userId' => $otherOwner->getId()->toRfc4122(), 'date' => '2026-12-31', 'amount' => 4500]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame([], self::list($owner, $organization)->toArray());

        foreach ([$elsewhere->getId()->toRfc4122(), Uuid::v7()->toRfc4122()] as $id) {
            self::get($owner, $organization, $id);
            self::assertResponseStatusCodeSame(404);
            self::patch($owner, $organization, $id, ['amount' => 1]);
            self::assertResponseStatusCodeSame(404);
            self::delete($owner, $organization, $id);
            self::assertResponseStatusCodeSame(404);
        }
    }

    public function test_it_answers_409_for_a_second_day_rate_on_the_same_date(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        self::addDayRate($organization, $owner, '2026-12-31');
        $other = self::addDayRate($organization, $owner, '2026-12-24');

        self::add($owner, $organization, ['userId' => $owner->getId()->toRfc4122(), 'date' => '2026-12-31', 'amount' => 5000]);
        self::assertResponseStatusCodeSame(409);
        self::patch($owner, $organization, $other->getId()->toRfc4122(), ['date' => '2026-12-31']);
        self::assertResponseStatusCodeSame(409);
    }

    public function test_it_changes_and_deletes_a_day_rate(): void
    {
        [$manager, $organization] = self::memberWithRole(MembershipRole::Manager);
        $dayRate = self::addDayRate($organization, $manager, '2026-12-24');
        self::mockTime(self::NOW);

        $changed = self::patch($manager, $organization, $dayRate->getId()->toRfc4122(), ['date' => '2026-12-31'])->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['2026-12-31', 4500, '2026-11-20T10:00:00+00:00'], [$changed['date'], $changed['amount'], $changed['updatedAt']]);

        self::delete($manager, $organization, $dayRate->getId()->toRfc4122());
        self::assertResponseStatusCodeSame(204);
        self::assertSame([], self::list($manager, $organization)->toArray());
    }

    public function test_it_answers_409_for_a_day_rate_on_a_locked_date(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $october = self::addDayRate($organization, $owner, '2026-10-31');
        $november = self::addDayRate($organization, $owner, '2026-11-01');
        self::lock($organization, '2026-10-01', '2026-10-31');
        $userId = $owner->getId()->toRfc4122();

        self::add($owner, $organization, ['userId' => $userId, 'date' => '2026-10-01', 'amount' => 4500]);
        self::assertResponseStatusCodeSame(409);
        self::patch($owner, $organization, $october->getId()->toRfc4122(), ['amount' => 5000]);
        self::assertResponseStatusCodeSame(409);
        self::patch($owner, $organization, $november->getId()->toRfc4122(), ['date' => '2026-10-15']);
        self::assertResponseStatusCodeSame(409);
        self::delete($owner, $organization, $october->getId()->toRfc4122());
        self::assertResponseStatusCodeSame(409);

        self::add($owner, $organization, ['userId' => $userId, 'date' => '2026-09-30', 'amount' => 4500]);
        self::assertResponseStatusCodeSame(201);
        self::delete($owner, $organization, $november->getId()->toRfc4122());
        self::assertResponseStatusCodeSame(204);
    }

    /** @param array<string, mixed> $body */
    private static function add(User $user, Organization $organization, array $body): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('POST', '/api/day-rates', ['json' => $body]);
    }

    private static function get(User $user, Organization $organization, string $id): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('GET', '/api/day-rates/'.$id);
    }

    private static function list(User $user, Organization $organization, ?string $userId = null): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('GET', '/api/day-rates', ['query' => null === $userId ? [] : ['userId' => $userId]]);
    }

    /** @param array<string, mixed> $body */
    private static function patch(User $user, Organization $organization, string $id, array $body): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('PATCH', '/api/day-rates/'.$id, ['json' => $body, 'headers' => self::MERGE_PATCH]);
    }

    private static function delete(User $user, Organization $organization, string $id): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('DELETE', '/api/day-rates/'.$id);
    }
}
