<?php

declare(strict_types=1);

namespace App\Tests\Api\TimeTracking;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Tests\Api\ApiTestCase;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixtures\OrganizationMother;
use App\User\Domain\Model\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * POST /shifts and PATCH /shifts/{id}: owners and managers enter missing shifts and correct shifts
 * (ADR 0016 §5). The server's time is mocked at NOW (UTC); clocked shifts go through the clock-in and
 * clock-out endpoints at earlier mocked times.
 */
final class ShiftCorrectionApiTest extends ApiTestCase
{
    private const string NOW = '2026-11-13 12:00:00';
    private const array MERGE_PATCH = ['Content-Type' => 'application/merge-patch+json'];

    /** @return iterable<string, array{MembershipRole}> */
    public static function managingRoles(): iterable
    {
        yield 'owner' => [MembershipRole::Owner];
        yield 'manager' => [MembershipRole::Manager];
    }

    #[DataProvider('managingRoles')]
    public function test_it_lets_owners_and_managers_enter_a_missing_shift(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        $employee = self::addMember($organization, MembershipRole::Employee);
        self::mockTime(self::NOW);

        $response = self::add($actor, $organization, [
            'userId' => $employee->getId()->toRfc4122(),
            'startedAt' => '2026-11-12T17:00:00+01:00',
            'endedAt' => '2026-11-13T00:30:00+01:00',
            'note' => 'Her phone was dead',
        ]);

        self::assertResponseStatusCodeSame(201);
        $shift = $response->toArray();
        self::assertTrue(Uuid::isValid($shift['id']));
        self::assertSame([
            'id' => $shift['id'],
            'userId' => $employee->getId()->toRfc4122(),
            'startedAt' => '2026-11-12T16:00:00+00:00',
            'endedAt' => '2026-11-12T23:30:00+00:00',
            'note' => 'Her phone was dead',
            'source' => 'manager',
            'correctedBy' => $actor->getId()->toRfc4122(),
            'correctedAt' => '2026-11-13T12:00:00+00:00',
        ], $shift);

        $listed = self::list($actor, $organization, '2026-11-12');
        self::assertSame(
            [[$shift['id'], '2026-11-12', 450, 'manager', $actor->getId()->toRfc4122(), '2026-11-13T12:00:00+00:00']],
            array_map(static fn (array $item): array => [$item['id'], $item['date'], $item['durationMinutes'], $item['source'], $item['correctedBy'], $item['correctedAt']], $listed),
        );
    }

    public function test_it_accepts_times_as_javascript_sends_them_and_keeps_whole_seconds(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        self::mockTime(self::NOW);

        $shift = self::add($owner, $organization, self::body($owner, '2026-11-12T08:00:00.999Z', '2026-11-12T16:00:00.000Z'))->toArray();

        self::assertResponseStatusCodeSame(201);
        self::assertSame(['2026-11-12T08:00:00+00:00', '2026-11-12T16:00:00+00:00'], [$shift['startedAt'], $shift['endedAt']]);
    }

    public function test_it_lets_a_manager_enter_their_own_shift_and_a_deactivated_members_shift(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $manager = self::addMember($organization, MembershipRole::Manager);
        $leaver = self::addMember($organization, MembershipRole::Employee);
        OrganizationMother::deactivateMember($organization, $leaver);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        self::mockTime(self::NOW);

        foreach ([$manager, $leaver] as $person) {
            self::add($manager, $organization, self::body($person, '2026-11-12T08:00:00Z', '2026-11-12T16:00:00Z'));
            self::assertResponseStatusCodeSame(201);
        }

        self::assertCount(2, self::list($owner, $organization, '2026-11-12'));
    }

    public function test_it_refuses_a_missing_shift_entered_by_an_employee(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        self::mockTime(self::NOW);

        foreach ([$employee, $owner] as $person) {
            self::add($employee, $organization, self::body($person, '2026-11-12T08:00:00Z', '2026-11-12T16:00:00Z'));
            self::assertResponseStatusCodeSame(403);
        }

        self::assertSame([], self::list($owner, $organization, '2026-11-12'));
    }

    public function test_it_answers_404_when_the_person_is_not_a_member(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [$memberElsewhere] = self::memberWithRole(MembershipRole::Employee);
        self::mockTime(self::NOW);

        foreach ([$memberElsewhere->getId()->toRfc4122(), Uuid::v7()->toRfc4122()] as $userId) {
            self::add($owner, $organization, ['userId' => $userId, 'startedAt' => '2026-11-12T08:00:00Z', 'endedAt' => '2026-11-12T16:00:00Z']);
            self::assertResponseStatusCodeSame(404, $userId);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidNewShifts(): iterable
    {
        $valid = ['startedAt' => '2026-11-12T08:00:00Z', 'endedAt' => '2026-11-12T16:00:00Z'];

        yield 'no userId' => [$valid, 'userId'];
        yield 'userId not a UUID' => [['userId' => 'eve'] + $valid, 'userId'];
        yield 'no startedAt' => [['userId' => 'EMPLOYEE', 'endedAt' => $valid['endedAt']], 'startedAt'];
        yield 'startedAt not a date-time' => [['userId' => 'EMPLOYEE', 'startedAt' => 'yesterday at 8'] + $valid, 'startedAt'];
        yield 'startedAt relative' => [['userId' => 'EMPLOYEE', 'startedAt' => 'yesterday'] + $valid, 'startedAt'];
        yield 'startedAt without an offset' => [['userId' => 'EMPLOYEE', 'startedAt' => '2026-11-12T08:00:00'] + $valid, 'startedAt'];
        yield 'startedAt without seconds' => [['userId' => 'EMPLOYEE', 'startedAt' => '2026-11-12T08:00Z'] + $valid, 'startedAt'];
        yield 'startedAt on a date that does not exist' => [['userId' => 'EMPLOYEE', 'startedAt' => '2026-02-30T08:00:00Z'] + $valid, 'startedAt'];
        yield 'startedAt as a number' => [['userId' => 'EMPLOYEE', 'startedAt' => 1762934400] + $valid, 'startedAt'];
        yield 'no endedAt' => [['userId' => 'EMPLOYEE', 'startedAt' => $valid['startedAt']], 'endedAt'];
        yield 'endedAt null' => [['userId' => 'EMPLOYEE', 'endedAt' => null] + $valid, 'endedAt'];
        yield 'note too long' => [['userId' => 'EMPLOYEE', 'note' => str_repeat('a', 1001)] + $valid, 'note'];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidNewShifts')]
    public function test_it_answers_422_with_violations_for_an_invalid_new_shift(array $body, string $invalidField): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        self::mockTime(self::NOW);
        if ('EMPLOYEE' === ($body['userId'] ?? null)) {
            $body['userId'] = $employee->getId()->toRfc4122();
        }

        $response = self::add($owner, $organization, $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([$invalidField], array_values(array_unique(array_column($response->toArray(false)['violations'] ?? [], 'propertyPath'))));
    }

    /** @return iterable<string, array{string, string}> */
    public static function impossibleTimes(): iterable
    {
        yield 'ending when it starts' => ['2026-11-12T08:00:00Z', '2026-11-12T08:00:00Z'];
        yield 'ending before it starts' => ['2026-11-12T16:00:00Z', '2026-11-12T08:00:00Z'];
        yield 'ending after now' => ['2026-11-13T08:00:00Z', '2026-11-13T12:00:01Z'];
        yield 'tomorrow' => ['2026-11-14T08:00:00Z', '2026-11-14T16:00:00Z'];
    }

    #[DataProvider('impossibleTimes')]
    public function test_it_answers_422_for_a_shift_that_could_not_have_happened(string $startedAt, string $endedAt): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        self::mockTime(self::NOW);

        self::add($owner, $organization, self::body($employee, $startedAt, $endedAt));

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], self::list($owner, $organization, '2026-11-12', '2026-11-14'));
    }

    public function test_it_answers_409_for_a_shift_that_overlaps_another_one_of_the_person(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        self::workShift($employee, $organization, '2026-11-12 16:00:00', '2026-11-12 23:00:00');
        self::workShift($employee, $organization, '2026-11-13 08:00:00', null);
        self::mockTime(self::NOW);

        self::add($owner, $organization, self::body($employee, '2026-11-12T22:00:00Z', '2026-11-13T02:00:00Z'));
        self::assertResponseStatusCodeSame(409);
        self::add($owner, $organization, self::body($employee, '2026-11-13T07:00:00Z', '2026-11-13T08:00:01Z'));
        self::assertResponseStatusCodeSame(409);

        self::add($owner, $organization, self::body($employee, '2026-11-12T23:00:00Z', '2026-11-13T02:00:00Z'));
        self::assertResponseStatusCodeSame(201);
        self::add($owner, $organization, self::body($owner, '2026-11-12T22:00:00Z', '2026-11-13T02:00:00Z'));
        self::assertResponseStatusCodeSame(201);
    }

    #[DataProvider('managingRoles')]
    public function test_it_lets_owners_and_managers_end_a_shift_the_person_forgot_to_clock_out_of(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $shiftId = self::workShift($employee, $organization, '2026-11-12 16:00:00', null);
        self::mockTime(self::NOW);

        $response = self::correct($actor, $organization, $shiftId, ['endedAt' => '2026-11-13T00:30:00+01:00', 'note' => 'Forgot to clock out']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([
            'id' => $shiftId,
            'userId' => $employee->getId()->toRfc4122(),
            'startedAt' => '2026-11-12T16:00:00+00:00',
            'endedAt' => '2026-11-12T23:30:00+00:00',
            'note' => 'Forgot to clock out',
            'source' => 'clock',
            'correctedBy' => $actor->getId()->toRfc4122(),
            'correctedAt' => '2026-11-13T12:00:00+00:00',
        ], $response->toArray());

        static::clientFor($employee, $organization)->request('GET', '/api/shifts/current');
        self::assertResponseStatusCodeSame(404);
        static::clientFor($employee, $organization)->request('POST', '/api/shifts/clock-in');
        self::assertResponseStatusCodeSame(201);
    }

    public function test_it_changes_only_the_fields_sent(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $shiftId = self::workShift($employee, $organization, '2026-11-12 16:00:00', '2026-11-12 23:00:00');
        self::mockTime(self::NOW);

        $unchanged = self::correct($owner, $organization, $shiftId, [])->toArray();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['2026-11-12T16:00:00+00:00', '2026-11-12T23:00:00+00:00', null, null, null], [
            $unchanged['startedAt'], $unchanged['endedAt'], $unchanged['note'], $unchanged['correctedBy'], $unchanged['correctedAt'],
        ]);

        $started = self::correct($owner, $organization, $shiftId, ['startedAt' => '2026-11-12T15:30:00Z'])->toArray();
        self::assertSame(['2026-11-12T15:30:00+00:00', '2026-11-12T23:00:00+00:00', null, $owner->getId()->toRfc4122()], [
            $started['startedAt'], $started['endedAt'], $started['note'], $started['correctedBy'],
        ]);

        $noted = self::correct($owner, $organization, $shiftId, ['note' => 'Opened the bar'])->toArray();
        self::assertSame(['2026-11-12T15:30:00+00:00', '2026-11-12T23:00:00+00:00', 'Opened the bar'], [
            $noted['startedAt'], $noted['endedAt'], $noted['note'],
        ]);

        $cleared = self::correct($owner, $organization, $shiftId, ['note' => null])->toArray();
        self::assertNull($cleared['note']);
    }

    public function test_it_keeps_an_open_shift_open_with_a_corrected_start(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $shiftId = self::workShift($employee, $organization, '2026-11-13 10:00:00', null);
        self::mockTime(self::NOW);

        $shift = self::correct($owner, $organization, $shiftId, ['startedAt' => '2026-11-13T09:00:00Z'])->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['2026-11-13T09:00:00+00:00', null], [$shift['startedAt'], $shift['endedAt']]);
        self::assertSame($shiftId, static::clientFor($employee, $organization)->request('GET', '/api/shifts/current')->toArray()['id']);
    }

    public function test_it_lets_a_manager_correct_their_own_shift_and_an_owners(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $manager = self::addMember($organization, MembershipRole::Manager);
        $own = self::workShift($manager, $organization, '2026-11-12 08:00:00', '2026-11-12 16:00:00');
        $owners = self::workShift($owner, $organization, '2026-11-12 09:00:00', '2026-11-12 17:00:00');
        self::mockTime(self::NOW);

        foreach ([$own, $owners] as $shiftId) {
            self::correct($manager, $organization, $shiftId, ['note' => 'Checked']);
            self::assertResponseStatusCodeSame(200);
        }
    }

    public function test_it_refuses_a_correction_by_an_employee(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $own = self::workShift($employee, $organization, '2026-11-12 16:00:00', null);
        $owners = self::workShift($owner, $organization, '2026-11-12 09:00:00', '2026-11-12 17:00:00');
        self::mockTime(self::NOW);

        foreach ([$own, $owners, Uuid::v7()->toRfc4122()] as $shiftId) {
            self::correct($employee, $organization, $shiftId, ['endedAt' => '2026-11-12T23:00:00Z']);
            self::assertResponseStatusCodeSame(403, $shiftId);
        }

        self::assertNull(static::clientFor($employee, $organization)->request('GET', '/api/shifts/current')->toArray()['endedAt']);
    }

    /** @return iterable<string, array{array<string, mixed>, int}> */
    public static function invalidCorrections(): iterable
    {
        yield 'startedAt null' => [['startedAt' => null], 422];
        yield 'startedAt not a date-time' => [['startedAt' => 'noon'], 422];
        yield 'endedAt without an offset' => [['endedAt' => '2026-11-12 23:30:00'], 422];
        yield 'endedAt removed' => [['endedAt' => null], 422];
        yield 'ending when it starts' => [['endedAt' => '2026-11-12T16:00:00Z'], 422];
        yield 'starting after the end' => [['startedAt' => '2026-11-12T23:30:00Z'], 422];
        yield 'ending after now' => [['endedAt' => '2026-11-13T12:00:01Z'], 422];
        yield 'note too long' => [['note' => str_repeat('a', 1001)], 422];
        yield 'overlapping the next shift' => [['endedAt' => '2026-11-13T08:00:01Z'], 409];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidCorrections')]
    public function test_it_refuses_an_invalid_correction_and_keeps_the_shift(array $body, int $status): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $shiftId = self::workShift($employee, $organization, '2026-11-12 16:00:00', '2026-11-12 23:00:00');
        self::workShift($employee, $organization, '2026-11-13 08:00:00', null);
        self::mockTime(self::NOW);

        self::correct($owner, $organization, $shiftId, $body);

        self::assertResponseStatusCodeSame($status);
        $listed = self::list($owner, $organization, '2026-11-12');
        self::assertSame([[$shiftId, '2026-11-12T23:00:00+00:00', null]], array_map(
            static fn (array $item): array => [$item['id'], $item['endedAt'], $item['correctedBy']],
            $listed,
        ));
    }

    public function test_it_answers_404_for_an_unknown_shift(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        self::mockTime(self::NOW);

        foreach ([Uuid::v7()->toRfc4122(), 'not-a-uuid'] as $shiftId) {
            self::correct($owner, $organization, $shiftId, ['note' => 'Hello']);
            self::assertResponseStatusCodeSame(404, $shiftId);
        }
    }

    public function test_it_keeps_another_organizations_shifts_invisible_and_unchanged(): void
    {
        [$owner, $here] = self::memberWithRole(MembershipRole::Owner);
        [$ownerElsewhere, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        OrganizationMother::addMember($elsewhere, $owner, MembershipRole::Employee);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $theirs = self::workShift($ownerElsewhere, $elsewhere, '2026-11-12 16:00:00', null);
        $mineThere = self::workShift($owner, $elsewhere, '2026-11-12 08:00:00', '2026-11-12 12:00:00');
        self::mockTime(self::NOW);

        foreach ([$theirs, $mineThere] as $shiftId) {
            self::correct($owner, $here, $shiftId, ['endedAt' => '2026-11-12T23:00:00Z']);
            self::assertResponseStatusCodeSame(404, $shiftId);
        }

        // The person is an owner here and only an employee there: no correction there either.
        self::correct($owner, $elsewhere, $theirs, ['endedAt' => '2026-11-12T23:00:00Z']);
        self::assertResponseStatusCodeSame(403);

        // A member of the other organization cannot be given a shift here, and theirs are not overlaps here.
        self::add($owner, $here, self::body($ownerElsewhere, '2026-11-12T08:00:00Z', '2026-11-12T16:00:00Z'));
        self::assertResponseStatusCodeSame(404);
        self::add($owner, $here, self::body($owner, '2026-11-12T08:00:00Z', '2026-11-12T16:00:00Z'));
        self::assertResponseStatusCodeSame(201);

        $listed = self::list($ownerElsewhere, $elsewhere, '2026-11-12');
        self::assertSame([[$theirs, null, null], [$mineThere, '2026-11-12T12:00:00+00:00', null]], array_map(
            static fn (array $item): array => [$item['id'], $item['endedAt'], $item['correctedBy']],
            $listed,
        ));
    }

    public function test_it_refuses_a_person_who_is_not_an_active_member(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $deactivated = self::addMember($organization, MembershipRole::Manager);
        OrganizationMother::deactivateMember($organization, $deactivated);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $shiftId = self::workShift($owner, $organization, '2026-11-12 08:00:00', '2026-11-12 16:00:00');
        self::mockTime(self::NOW);

        foreach ([UserFactory::createOne(), $deactivated] as $user) {
            self::add($user, $organization, self::body($owner, '2026-11-11T08:00:00Z', '2026-11-11T16:00:00Z'));
            self::assertResponseStatusCodeSame(403);
            self::correct($user, $organization, $shiftId, ['note' => 'Hello']);
            self::assertResponseStatusCodeSame(403);
        }
    }

    public function test_it_requires_an_organization_and_authentication(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $shiftId = self::workShift($owner, $organization, '2026-11-12 08:00:00', '2026-11-12 16:00:00');
        self::mockTime(self::NOW);
        $body = self::body($owner, '2026-11-11T08:00:00Z', '2026-11-11T16:00:00Z');

        static::clientFor($owner)->request('POST', '/api/shifts', ['json' => $body]);
        self::assertResponseStatusCodeSame(400);
        static::clientFor($owner)->request('PATCH', '/api/shifts/'.$shiftId, ['json' => ['note' => 'Hello'], 'headers' => self::MERGE_PATCH]);
        self::assertResponseStatusCodeSame(400);

        static::createClient()->request('POST', '/api/shifts', ['json' => $body, 'headers' => ['Accept' => 'application/json']]);
        self::assertResponseStatusCodeSame(401);
        static::createClient()->request('PATCH', '/api/shifts/'.$shiftId, ['json' => ['note' => 'Hello'], 'headers' => ['Accept' => 'application/json'] + self::MERGE_PATCH]);
        self::assertResponseStatusCodeSame(401);
    }

    /** @param array<string, mixed> $body */
    private static function add(User $user, Organization $organization, array $body): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('POST', '/api/shifts', ['json' => $body]);
    }

    /** @param array<string, mixed> $body */
    private static function correct(User $user, Organization $organization, string $shiftId, array $body): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('PATCH', '/api/shifts/'.$shiftId, [
            'json' => $body,
            'headers' => self::MERGE_PATCH,
        ]);
    }

    /** @return array{userId: string, startedAt: string, endedAt: string} */
    private static function body(User $person, string $startedAt, string $endedAt): array
    {
        return ['userId' => $person->getId()->toRfc4122(), 'startedAt' => $startedAt, 'endedAt' => $endedAt];
    }

    /** @return list<array<string, mixed>> the shifts that started on these business dates (Europe/Warsaw) */
    private static function list(User $user, Organization $organization, string $from, ?string $to = null): array
    {
        /** @var list<array<string, mixed>> */
        return static::clientFor($user, $organization)
            ->request('GET', '/api/shifts', ['query' => ['from' => $from, 'to' => $to ?? $from]])
            ->toArray();
    }

    /** Clocks the person in and, unless $endedAt is null, out, at those server times (UTC); returns the shift's id. */
    private static function workShift(User $user, Organization $organization, string $startedAt, ?string $endedAt): string
    {
        self::mockTime($startedAt);
        $shift = static::clientFor($user, $organization)->request('POST', '/api/shifts/clock-in')->toArray();

        if (null !== $endedAt) {
            self::mockTime($endedAt);
            static::clientFor($user, $organization)->request('POST', '/api/shifts/clock-out');
            self::assertResponseStatusCodeSame(200);
        }

        return $shift['id'];
    }

    private static function addMember(Organization $organization, MembershipRole $role): User
    {
        $user = UserFactory::createOne();
        OrganizationMother::addMember($organization, $user, $role);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        return $user;
    }
}
