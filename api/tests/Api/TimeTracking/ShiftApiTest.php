<?php

declare(strict_types=1);

namespace App\Tests\Api\TimeTracking;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Tests\Api\ApiTestCase;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixtures\OrganizationMother;
use App\User\Domain\Model\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\Uuid;

/**
 * POST /shifts/clock-in, POST /shifts/clock-out and GET /shifts/current: every member clocks in and
 * out for themselves, at the server's time (ADR 0016).
 */
final class ShiftApiTest extends ApiTestCase
{
    private const string START = '2026-11-11 16:00:00';
    private const string END = '2026-11-12 00:30:00';

    /** @return iterable<string, array{MembershipRole}> */
    public static function roles(): iterable
    {
        foreach (MembershipRole::cases() as $role) {
            yield $role->value => [$role];
        }
    }

    #[DataProvider('roles')]
    public function test_it_lets_any_member_clock_in_and_out_at_the_servers_time(MembershipRole $role): void
    {
        [$user, $organization] = self::memberWithRole($role);
        self::mockTime(self::START);

        $in = self::clockIn($user, $organization);

        self::assertResponseStatusCodeSame(201);
        self::assertTrue(Uuid::isValid($in['id']));
        self::assertSame([
            'id' => $in['id'],
            'userId' => $user->getId()->toRfc4122(),
            'startedAt' => '2026-11-11T16:00:00+00:00',
            'endedAt' => null,
            'note' => null,
        ], $in);

        self::mockTime(self::END);
        $out = self::clockOut($user, $organization);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(array_replace($in, ['endedAt' => '2026-11-12T00:30:00+00:00']), $out);
    }

    public function test_it_returns_the_current_shift_until_the_person_clocks_out(): void
    {
        [$user, $organization] = self::memberWithRole(MembershipRole::Employee);
        self::mockTime(self::START);
        $in = self::clockIn($user, $organization);

        self::mockTime('+2 hours');
        $current = static::clientFor($user, $organization)->request('GET', '/api/shifts/current');

        self::assertResponseStatusCodeSame(200);
        self::assertSame($in, $current->toArray());

        self::clockOut($user, $organization);
        static::clientFor($user, $organization)->request('GET', '/api/shifts/current');

        self::assertResponseStatusCodeSame(404);
    }

    public function test_it_answers_404_for_the_current_shift_of_a_person_who_never_clocked_in(): void
    {
        [$user, $organization] = self::memberWithRole(MembershipRole::Employee);

        static::clientFor($user, $organization)->request('GET', '/api/shifts/current');

        self::assertResponseStatusCodeSame(404);
    }

    public function test_it_ignores_a_time_sent_by_the_client(): void
    {
        [$user, $organization] = self::memberWithRole(MembershipRole::Employee);
        self::mockTime(self::START);

        $response = static::clientFor($user, $organization)->request('POST', '/api/shifts/clock-in', [
            'json' => ['startedAt' => '2026-11-11T08:00:00+00:00', 'userId' => Uuid::v7()->toRfc4122()],
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('2026-11-11T16:00:00+00:00', $response->toArray()['startedAt']);
        self::assertSame($user->getId()->toRfc4122(), $response->toArray()['userId']);
    }

    public function test_it_refuses_to_clock_in_a_person_who_is_already_clocked_in(): void
    {
        [$user, $organization] = self::memberWithRole(MembershipRole::Employee);
        self::mockTime(self::START);
        $in = self::clockIn($user, $organization);

        self::mockTime('+1 hour');
        self::clockIn($user, $organization);

        self::assertResponseStatusCodeSame(409);
        self::assertSame($in, static::clientFor($user, $organization)->request('GET', '/api/shifts/current')->toArray());
    }

    public function test_it_refuses_to_clock_out_a_person_who_is_not_clocked_in(): void
    {
        [$user, $organization] = self::memberWithRole(MembershipRole::Employee);

        self::clockOut($user, $organization);
        self::assertResponseStatusCodeSame(409);

        self::mockTime(self::START);
        self::clockIn($user, $organization);
        self::mockTime(self::END);
        self::clockOut($user, $organization);
        self::assertResponseStatusCodeSame(200);

        self::mockTime('+1 minute');
        self::clockOut($user, $organization);
        self::assertResponseStatusCodeSame(409);
    }

    public function test_it_refuses_to_clock_out_in_the_second_the_shift_started(): void
    {
        [$user, $organization] = self::memberWithRole(MembershipRole::Employee);
        self::mockTime(self::START);
        self::clockIn($user, $organization);

        self::clockOut($user, $organization);

        self::assertResponseStatusCodeSame(422);
    }

    public function test_it_lets_the_person_clock_in_again_after_clocking_out(): void
    {
        [$user, $organization] = self::memberWithRole(MembershipRole::Employee);
        self::mockTime(self::START);
        $first = self::clockIn($user, $organization);
        self::mockTime(self::END);
        self::clockOut($user, $organization);

        self::mockTime('2026-11-12 16:00:00');
        $second = self::clockIn($user, $organization);

        self::assertResponseStatusCodeSame(201);
        self::assertNotSame($first['id'], $second['id']);
        self::assertSame('2026-11-12T16:00:00+00:00', $second['startedAt']);
    }

    public function test_it_keeps_each_persons_shift_their_own(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        self::mockTime(self::START);
        $ownersShift = self::clockIn($owner, $organization);

        static::clientFor($employee, $organization)->request('GET', '/api/shifts/current');
        self::assertResponseStatusCodeSame(404);
        self::clockOut($employee, $organization);
        self::assertResponseStatusCodeSame(409);

        $employeesShift = self::clockIn($employee, $organization);
        self::assertResponseStatusCodeSame(201);
        self::assertSame($employee->getId()->toRfc4122(), $employeesShift['userId']);
        self::assertSame($ownersShift, static::clientFor($owner, $organization)->request('GET', '/api/shifts/current')->toArray());
    }

    public function test_it_keeps_shifts_in_another_organization_apart(): void
    {
        [$user, $here] = self::memberWithRole(MembershipRole::Employee);
        $elsewhere = OrganizationFactory::createOne();
        OrganizationMother::addMember($elsewhere, $user, MembershipRole::Employee);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        self::mockTime(self::START);
        $elsewhereShift = self::clockIn($user, $elsewhere);

        // Invisible here, and not something to clock out of here...
        static::clientFor($user, $here)->request('GET', '/api/shifts/current');
        self::assertResponseStatusCodeSame(404);
        self::mockTime('+1 hour');
        self::clockOut($user, $here);
        self::assertResponseStatusCodeSame(409);

        // ...and it does not stop clocking in here.
        $hereShift = self::clockIn($user, $here);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('2026-11-11T17:00:00+00:00', $hereShift['startedAt']);

        self::mockTime('+1 hour');
        self::clockOut($user, $here);
        self::assertResponseStatusCodeSame(200);
        self::assertSame($elsewhereShift, static::clientFor($user, $elsewhere)->request('GET', '/api/shifts/current')->toArray());
    }

    public function test_it_refuses_a_deactivated_member(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        self::mockTime(self::START);
        $shift = self::clockIn($employee, $organization);
        static::clientFor($owner, $organization)->request('POST', '/api/members/'.$employee->getId()->toRfc4122().'/deactivate');
        self::assertResponseStatusCodeSame(204);

        self::mockTime(self::END);
        foreach (self::endpoints() as [$method, $path]) {
            static::clientFor($employee, $organization)->request($method, $path);
            self::assertResponseStatusCodeSame(403, $method.' '.$path);
        }

        // The open shift is kept for the manager to close (#27).
        static::clientFor($owner, $organization)->request('POST', '/api/members/'.$employee->getId()->toRfc4122().'/reactivate');
        self::assertSame($shift, static::clientFor($employee, $organization)->request('GET', '/api/shifts/current')->toArray());
    }

    public function test_it_refuses_a_person_who_is_not_a_member(): void
    {
        $organization = OrganizationFactory::createOne();
        $stranger = UserFactory::createOne();

        foreach (self::endpoints() as [$method, $path]) {
            static::clientFor($stranger, $organization)->request($method, $path);
            self::assertResponseStatusCodeSame(403, $method.' '.$path);
        }
    }

    public function test_it_requires_an_organization(): void
    {
        [$user] = self::memberWithRole(MembershipRole::Employee);

        foreach (self::endpoints() as [$method, $path]) {
            static::clientFor($user)->request($method, $path);
            self::assertResponseStatusCodeSame(400, $method.' '.$path);
        }
    }

    public function test_it_requires_authentication(): void
    {
        foreach (self::endpoints() as [$method, $path]) {
            static::createClient()->request($method, $path, ['headers' => ['Accept' => 'application/json']]);
            self::assertResponseStatusCodeSame(401, $method.' '.$path);
        }
    }

    /** @return list<array{string, string}> */
    private static function endpoints(): array
    {
        return [
            ['GET', '/api/shifts/current'],
            ['POST', '/api/shifts/clock-in'],
            ['POST', '/api/shifts/clock-out'],
        ];
    }

    /** @return array<string, mixed> the response body; check the status code separately */
    private static function clockIn(User $user, Organization $organization): array
    {
        return static::clientFor($user, $organization)->request('POST', '/api/shifts/clock-in')->toArray(false);
    }

    /** @return array<string, mixed> the response body; check the status code separately */
    private static function clockOut(User $user, Organization $organization): array
    {
        return static::clientFor($user, $organization)->request('POST', '/api/shifts/clock-out')->toArray(false);
    }

    private static function addMember(Organization $organization, MembershipRole $role): User
    {
        $user = UserFactory::createOne();
        OrganizationMother::addMember($organization, $user, $role);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        return $user;
    }
}
