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
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * /shift-change-requests: members ask owners and managers to enter a missing shift or to give one
 * of their shifts new times; owners and managers approve (which applies the change to the shift)
 * or reject them (ADR 0016 §5). The server's time is mocked at NOW (UTC); the organizations are in
 * Europe/Warsaw.
 */
final class ShiftChangeRequestApiTest extends ApiTestCase
{
    private const string NOW = '2026-11-13 12:00:00';

    /** @return iterable<string, array{MembershipRole}> */
    public static function managingRoles(): iterable
    {
        yield 'owner' => [MembershipRole::Owner];
        yield 'manager' => [MembershipRole::Manager];
    }

    public function test_it_lets_an_employee_ask_for_a_missing_shift_and_tells_owners_and_managers(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $manager = self::addMember($organization, MembershipRole::Manager);
        $employee = self::addMember($organization, MembershipRole::Employee);
        self::mockTime(self::NOW);

        $response = self::submit($employee, $organization, [
            'startedAt' => '2026-11-12T17:00:00+01:00',
            'endedAt' => '2026-11-13T00:30:00.250+01:00',
            'note' => ' My phone was dead ',
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertQueuedEmailCount(2);
        self::assertSame(
            [$owner->getEmail(), $manager->getEmail()],
            array_map(self::recipient(...), array_keys(self::getMailerMessages())),
        );
        self::assertEmailHtmlBodyContains(self::getMailerMessage(0) ?? self::fail(), 'Thu 12 Nov 2026, 17:00 to Fri 13 Nov 2026, 00:30');
        $request = $response->toArray();
        self::assertTrue(Uuid::isValid($request['id']));
        self::assertSame([
            'id' => $request['id'],
            'userId' => $employee->getId()->toRfc4122(),
            'firstName' => $employee->getFirstName(),
            'lastName' => $employee->getLastName(),
            'kind' => 'missing_shift',
            'shiftId' => null,
            'startedAt' => '2026-11-12T16:00:00+00:00',
            'endedAt' => '2026-11-12T23:30:00+00:00',
            'note' => 'My phone was dead',
            'status' => 'pending',
            'submittedAt' => '2026-11-13T12:00:00+00:00',
            'decidedBy' => null,
            'decidedAt' => null,
            'rejectionReason' => null,
            'withdrawnAt' => null,
            'shiftStartedAt' => null,
            'shiftEndedAt' => null,
        ], $request);

        self::assertSame($request, self::get($owner, $organization, $request['id'])->toArray());
        self::assertSame([], self::shifts($owner, $organization, '2026-11-12'), 'Submitting changes no shift.');
    }

    public function test_it_lets_an_employee_ask_for_new_times_for_their_own_shift(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $shiftId = self::workShift($employee, $organization, '2026-11-12 16:00:00', null);
        self::mockTime(self::NOW);

        $request = self::submit($employee, $organization, [
            'shiftId' => $shiftId,
            'startedAt' => '2026-11-12T16:00:00Z',
            'endedAt' => '2026-11-12T23:30:00Z',
            'note' => 'Forgot to clock out',
        ])->toArray();

        self::assertResponseStatusCodeSame(201);
        self::assertQueuedEmailCount(1);
        self::assertSame(
            ['new_times', $shiftId, '2026-11-12T23:30:00+00:00', '2026-11-12T16:00:00+00:00', null],
            [$request['kind'], $request['shiftId'], $request['endedAt'], $request['shiftStartedAt'], $request['shiftEndedAt']],
        );
    }

    public function test_it_lets_a_member_ask_for_a_new_start_of_an_open_shift_that_stays_open(): void
    {
        [, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $shiftId = self::workShift($employee, $organization, '2026-11-13 08:00:00', null);
        self::mockTime(self::NOW);

        $request = self::submit($employee, $organization, ['shiftId' => $shiftId, 'startedAt' => '2026-11-13T07:30:00Z', 'endedAt' => null, 'note' => 'Clocked in late'])->toArray();

        self::assertResponseStatusCodeSame(201);
        self::assertNull($request['endedAt']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidRequests(): iterable
    {
        $valid = ['startedAt' => '2026-11-12T08:00:00Z', 'endedAt' => '2026-11-12T16:00:00Z', 'note' => 'My phone was dead'];

        yield 'no note' => [['startedAt' => $valid['startedAt'], 'endedAt' => $valid['endedAt']], 'note'];
        yield 'blank note' => [['note' => "  \n"] + $valid, 'note'];
        yield 'note too long' => [['note' => str_repeat('a', 1001)] + $valid, 'note'];
        yield 'no startedAt' => [['endedAt' => $valid['endedAt'], 'note' => $valid['note']], 'startedAt'];
        yield 'startedAt relative' => [['startedAt' => 'yesterday'] + $valid, 'startedAt'];
        yield 'startedAt without an offset' => [['startedAt' => '2026-11-12T08:00:00'] + $valid, 'startedAt'];
        yield 'endedAt on a date that does not exist' => [['endedAt' => '2026-02-30T16:00:00Z'] + $valid, 'endedAt'];
        yield 'missing shift without endedAt' => [['startedAt' => $valid['startedAt'], 'note' => $valid['note']], 'endedAt'];
        yield 'missing shift with endedAt null' => [['endedAt' => null] + $valid, 'endedAt'];
        yield 'shiftId not a UUID' => [['shiftId' => 'yesterday'] + $valid, 'shiftId'];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidRequests')]
    public function test_it_answers_422_with_violations_for_an_invalid_request(array $body, string $invalidField): void
    {
        [, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        self::mockTime(self::NOW);

        $response = self::submit($employee, $organization, $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([$invalidField], array_values(array_unique(array_column($response->toArray(false)['violations'] ?? [], 'propertyPath'))));
        self::assertQueuedEmailCount(0);
    }

    /** @return iterable<string, array{?string, string, ?string}> */
    public static function impossibleChanges(): iterable
    {
        yield 'end before start' => [null, '2026-11-12T16:00:00Z', '2026-11-12T08:00:00Z'];
        yield 'end in the future' => [null, '2026-11-13T08:00:00Z', '2026-11-13T12:00:01Z'];
        yield 'an ended shift reopened' => ['ENDED', '2026-11-12T08:00:00Z', null];
        yield 'the shift\'s own times' => ['ENDED', '2026-11-12T08:00:00.300+00:00', '2026-11-12T16:00:00Z'];
    }

    #[DataProvider('impossibleChanges')]
    public function test_it_answers_422_for_a_change_that_could_not_be_right(?string $shift, string $startedAt, ?string $endedAt): void
    {
        [, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $shiftId = null === $shift ? null : self::workShift($employee, $organization, '2026-11-12 08:00:00', '2026-11-12 16:00:00');
        self::mockTime(self::NOW);

        self::submit($employee, $organization, ['shiftId' => $shiftId, 'startedAt' => $startedAt, 'endedAt' => $endedAt, 'note' => 'Please']);

        self::assertResponseStatusCodeSame(422);
        self::assertQueuedEmailCount(0);
    }

    public function test_it_answers_404_for_a_shift_that_is_not_the_members_own(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $colleaguesShift = self::workShift(self::addMember($organization, MembershipRole::Employee), $organization, '2026-11-12 08:00:00', '2026-11-12 16:00:00');
        self::mockTime(self::NOW);

        foreach ([[$employee, $colleaguesShift], [$owner, $colleaguesShift], [$employee, Uuid::v7()->toRfc4122()]] as [$user, $shiftId]) {
            self::submit($user, $organization, ['shiftId' => $shiftId, 'startedAt' => '2026-11-12T07:00:00Z', 'endedAt' => '2026-11-12T16:00:00Z', 'note' => 'Please']);
            self::assertResponseStatusCodeSame(404);
        }
    }

    #[DataProvider('managingRoles')]
    public function test_it_lets_owners_and_managers_approve_a_missing_shift_which_enters_it(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        $employee = self::addMember($organization, MembershipRole::Employee);
        self::mockTime('2026-11-13 08:00:00');
        $requestId = self::submitted($employee, $organization, null, '2026-11-12T16:00:00Z', '2026-11-12T23:30:00Z', 'My phone was dead');
        self::mockTime(self::NOW);

        $response = self::approve($actor, $organization, $requestId);

        self::assertResponseStatusCodeSame(200);
        self::assertQueuedEmailCount(1);
        self::assertSame($employee->getEmail(), self::recipient(0));
        $request = $response->toArray();
        self::assertSame(
            ['approved', $actor->getId()->toRfc4122(), '2026-11-13T12:00:00+00:00', null, '2026-11-12T16:00:00+00:00', '2026-11-12T23:30:00+00:00'],
            [$request['status'], $request['decidedBy'], $request['decidedAt'], $request['rejectionReason'], $request['shiftStartedAt'], $request['shiftEndedAt']],
        );
        $shifts = self::shifts($actor, $organization, '2026-11-12');
        self::assertSame(
            [[$request['shiftId'], $employee->getId()->toRfc4122(), 450, 'My phone was dead', 'manager', $actor->getId()->toRfc4122(), '2026-11-13T12:00:00+00:00']],
            array_map(static fn (array $shift): array => [$shift['id'], $shift['userId'], $shift['durationMinutes'], $shift['note'], $shift['source'], $shift['correctedBy'], $shift['correctedAt']], $shifts),
        );
    }

    public function test_it_gives_the_shift_its_new_times_on_approval(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $shiftId = self::workShift($employee, $organization, '2026-11-12 16:00:00', null);
        self::mockTime(self::NOW);
        $requestId = self::submitted($employee, $organization, $shiftId, '2026-11-12T16:00:00Z', '2026-11-12T23:30:00Z');

        $request = self::approve($owner, $organization, $requestId)->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame([$shiftId, '2026-11-12T23:30:00+00:00'], [$request['shiftId'], $request['shiftEndedAt']]);
        $shifts = self::shifts($owner, $organization, '2026-11-12');
        self::assertSame(
            [[$shiftId, '2026-11-12T23:30:00+00:00', null, 'clock', $owner->getId()->toRfc4122()]],
            array_map(static fn (array $shift): array => [$shift['id'], $shift['endedAt'], $shift['note'], $shift['source'], $shift['correctedBy']], $shifts),
        );

        // The forgotten shift is closed: the employee can clock in again.
        static::clientFor($employee, $organization)->request('POST', '/api/shifts/clock-in');
        self::assertResponseStatusCodeSame(201);
    }

    public function test_it_answers_409_for_an_overlap_found_at_approval_and_keeps_the_request_pending(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        self::mockTime(self::NOW);
        $requestId = self::submitted($employee, $organization, null, '2026-11-12T16:00:00Z', '2026-11-12T23:30:00Z');
        // Meanwhile a manager entered the shift themselves.
        static::clientFor($owner, $organization)->request('POST', '/api/shifts', ['json' => [
            'userId' => $employee->getId()->toRfc4122(), 'startedAt' => '2026-11-12T16:30:00Z', 'endedAt' => '2026-11-12T23:30:00Z',
        ]]);
        self::assertResponseStatusCodeSame(201);

        $response = self::approve($owner, $organization, $requestId);

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('overlap', $response->toArray(false)['detail'] ?? '');
        self::assertQueuedEmailCount(0);
        self::assertSame('pending', self::get($owner, $organization, $requestId)->toArray()['status']);
        self::assertCount(1, self::shifts($owner, $organization, '2026-11-12'));

        // It can still be rejected.
        self::reject($owner, $organization, $requestId, ['reason' => 'I entered it already']);
        self::assertResponseStatusCodeSame(200);
    }

    public function test_it_answers_422_when_the_shift_ended_since_the_request_kept_it_open(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $shiftId = self::workShift($employee, $organization, '2026-11-13 08:00:00', null);
        self::mockTime('2026-11-13 09:00:00');
        $requestId = self::submitted($employee, $organization, $shiftId, '2026-11-13T07:30:00Z', null, 'Clocked in late');
        self::mockTime(self::NOW);
        static::clientFor($employee, $organization)->request('POST', '/api/shifts/clock-out');
        self::assertResponseStatusCodeSame(200);

        self::approve($owner, $organization, $requestId);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('pending', self::get($owner, $organization, $requestId)->toArray()['status']);
    }

    public function test_it_lets_owners_and_managers_reject_a_request_with_a_reason_and_tells_the_employee(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $manager = self::addMember($organization, MembershipRole::Manager);
        $employee = self::addMember($organization, MembershipRole::Employee);
        self::mockTime(self::NOW);
        $first = self::submitted($employee, $organization, null, '2026-11-11T08:00:00Z', '2026-11-11T16:00:00Z');
        $second = self::submitted($employee, $organization, null, '2026-11-12T08:00:00Z', '2026-11-12T16:00:00Z');

        $rejected = self::reject($manager, $organization, $first, ['reason' => ' You were off that day '])->toArray();
        self::assertResponseStatusCodeSame(200);
        self::assertQueuedEmailCount(1);
        self::assertEmailHtmlBodyContains(self::getMailerMessage(0) ?? self::fail(), 'You were off that day');
        self::assertSame(
            ['rejected', $manager->getId()->toRfc4122(), '2026-11-13T12:00:00+00:00', 'You were off that day', null],
            [$rejected['status'], $rejected['decidedBy'], $rejected['decidedAt'], $rejected['rejectionReason'], $rejected['shiftId']],
        );

        self::reject($owner, $organization, $second, []);
        self::assertResponseStatusCodeSame(200);
        self::assertNull(self::get($owner, $organization, $second)->toArray()['rejectionReason']);
        self::assertSame([], self::shifts($owner, $organization, '2026-11-11', '2026-11-12'));
    }

    public function test_it_answers_422_for_a_reason_that_is_too_long(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        self::mockTime(self::NOW);
        $requestId = self::submitted($employee, $organization, null, '2026-11-12T08:00:00Z', '2026-11-12T16:00:00Z');

        $response = self::reject($owner, $organization, $requestId, ['reason' => str_repeat('a', 1001)]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['reason'], array_column($response->toArray(false)['violations'] ?? [], 'propertyPath'));
    }

    public function test_it_answers_409_for_a_request_that_was_already_decided(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        self::mockTime(self::NOW);
        $approved = self::submitted($employee, $organization, null, '2026-11-11T08:00:00Z', '2026-11-11T16:00:00Z');
        $rejected = self::submitted($employee, $organization, null, '2026-11-12T08:00:00Z', '2026-11-12T16:00:00Z');
        self::approve($owner, $organization, $approved);
        self::reject($owner, $organization, $rejected, []);

        foreach ([$approved, $rejected] as $requestId) {
            self::approve($owner, $organization, $requestId);
            self::assertResponseStatusCodeSame(409);
            self::reject($owner, $organization, $requestId, []);
            self::assertResponseStatusCodeSame(409);
        }

        self::assertCount(1, self::shifts($owner, $organization, '2026-11-11', '2026-11-12'));
    }

    #[DataProvider('managingRoles')]
    public function test_it_lets_owners_and_managers_decide_their_own_requests(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        self::mockTime(self::NOW);
        $approved = self::submitted($actor, $organization, null, '2026-11-11T08:00:00Z', '2026-11-11T16:00:00Z');
        // A requester is not told about their own request: the owner is told about the manager's.
        self::assertQueuedEmailCount(MembershipRole::Owner === $role ? 0 : 1);
        $rejected = self::submitted($actor, $organization, null, '2026-11-12T08:00:00Z', '2026-11-12T16:00:00Z');

        self::approve($actor, $organization, $approved);
        self::assertResponseStatusCodeSame(200);
        self::reject($actor, $organization, $rejected, []);
        self::assertResponseStatusCodeSame(200);
    }

    public function test_it_refuses_decisions_by_an_employee_and_hides_others_requests_from_them(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $colleague = self::addMember($organization, MembershipRole::Employee);
        self::mockTime(self::NOW);
        $own = self::submitted($employee, $organization, null, '2026-11-12T08:00:00Z', '2026-11-12T16:00:00Z');
        $colleagues = self::submitted($colleague, $organization, null, '2026-11-12T08:00:00Z', '2026-11-12T16:00:00Z');

        foreach ([[$own, 403], [$colleagues, 404]] as [$requestId, $status]) {
            self::approve($employee, $organization, $requestId);
            self::assertResponseStatusCodeSame($status);
            self::reject($employee, $organization, $requestId, []);
            self::assertResponseStatusCodeSame($status);
        }

        self::get($employee, $organization, $own);
        self::assertResponseStatusCodeSame(200);
        self::get($employee, $organization, $colleagues);
        self::assertResponseStatusCodeSame(404);
        self::assertSame([$own], array_column(self::list($employee, $organization), 'id'));
        self::assertSame(['pending', 'pending'], array_column(self::list($owner, $organization), 'status'));
        self::assertSame([], self::shifts($owner, $organization, '2026-11-12'));
    }

    public function test_it_lets_the_requester_withdraw_their_pending_request_and_tells_nobody(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $shiftId = self::workShift($employee, $organization, '2026-11-12 16:00:00', null);
        self::mockTime('2026-11-13 11:00:00');
        $requestId = self::submitted($employee, $organization, $shiftId, '2026-11-12T16:00:00Z', '2026-11-12T23:30:00Z');
        self::mockTime(self::NOW);

        $response = self::withdraw($employee, $organization, $requestId);

        self::assertResponseStatusCodeSame(200);
        self::assertQueuedEmailCount(0);
        $request = $response->toArray();
        self::assertSame(
            ['withdrawn', '2026-11-13T12:00:00+00:00', null, null, $shiftId, null],
            [$request['status'], $request['withdrawnAt'], $request['decidedBy'], $request['decidedAt'], $request['shiftId'], $request['shiftEndedAt']],
        );
        self::assertSame($request, self::get($employee, $organization, $requestId)->toArray());
        self::assertSame([], self::list($owner, $organization, 'pending'));
        self::assertSame([$requestId], array_column(self::list($owner, $organization, 'withdrawn'), 'id'));
        $shifts = self::shifts($owner, $organization, '2026-11-12');
        self::assertSame([[$shiftId, null]], array_map(static fn (array $shift): array => [$shift['id'], $shift['endedAt']], $shifts), 'Withdrawing changes no shift.');
    }

    #[DataProvider('managingRoles')]
    public function test_it_lets_owners_and_managers_withdraw_their_own_requests(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        self::mockTime(self::NOW);
        $requestId = self::submitted($actor, $organization, null, '2026-11-12T08:00:00Z', '2026-11-12T16:00:00Z');

        self::assertSame('withdrawn', self::withdraw($actor, $organization, $requestId)->toArray()['status']);
    }

    public function test_it_lets_nobody_but_the_requester_withdraw_a_request(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $manager = self::addMember($organization, MembershipRole::Manager);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $colleague = self::addMember($organization, MembershipRole::Employee);
        self::mockTime(self::NOW);
        $requestId = self::submitted($employee, $organization, null, '2026-11-12T08:00:00Z', '2026-11-12T16:00:00Z');
        $managersRequest = self::submitted($manager, $organization, null, '2026-11-11T08:00:00Z', '2026-11-11T16:00:00Z');

        // Owners and managers see the request (they reject it instead); a colleague does not.
        foreach ([[$owner, $requestId, 403], [$manager, $requestId, 403], [$owner, $managersRequest, 403], [$colleague, $requestId, 404], [$employee, $managersRequest, 404]] as [$actor, $id, $status]) {
            $problem = self::withdraw($actor, $organization, $id)->toArray(false);
            self::assertResponseStatusCodeSame($status);
            self::assertSame(403 === $status ? 'time_tracking.shift_change_request_withdrawal_forbidden' : 'time_tracking.shift_change_request_not_found', $problem['code']);
        }

        self::assertSame(['pending', 'pending'], array_column(self::list($owner, $organization), 'status'));
    }

    public function test_it_answers_409_for_withdrawing_a_request_that_is_no_longer_pending(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        self::mockTime(self::NOW);
        $approved = self::submitted($employee, $organization, null, '2026-11-10T08:00:00Z', '2026-11-10T16:00:00Z');
        $rejected = self::submitted($employee, $organization, null, '2026-11-11T08:00:00Z', '2026-11-11T16:00:00Z');
        $withdrawn = self::submitted($employee, $organization, null, '2026-11-12T08:00:00Z', '2026-11-12T16:00:00Z');
        self::approve($owner, $organization, $approved);
        self::reject($owner, $organization, $rejected, []);
        self::withdraw($employee, $organization, $withdrawn);

        foreach ([
            [$approved, 'time_tracking.shift_change_request_already_decided'],
            [$rejected, 'time_tracking.shift_change_request_already_decided'],
            [$withdrawn, 'time_tracking.shift_change_request_withdrawn'],
        ] as [$requestId, $code]) {
            $problem = self::withdraw($employee, $organization, $requestId)->toArray(false);
            self::assertResponseStatusCodeSame(409);
            self::assertSame($code, $problem['code']);
        }

        self::assertSame(['withdrawn', 'rejected', 'approved'], array_column(self::list($employee, $organization), 'status'));
    }

    public function test_it_answers_409_for_deciding_a_withdrawn_request(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        self::mockTime(self::NOW);
        $requestId = self::submitted($employee, $organization, null, '2026-11-12T08:00:00Z', '2026-11-12T16:00:00Z');
        self::withdraw($employee, $organization, $requestId);

        foreach ([self::approve($owner, $organization, $requestId), self::reject($owner, $organization, $requestId, [])] as $response) {
            self::assertSame(409, $response->getStatusCode());
            self::assertSame(['code' => 'time_tracking.shift_change_request_withdrawn', 'parameters' => ['requestId' => $requestId]], array_intersect_key($response->toArray(false), ['code' => true, 'parameters' => true]));
        }

        self::assertQueuedEmailCount(0); // the requester is not told about a refused decision
        self::assertSame([], self::shifts($owner, $organization, '2026-11-12'));
    }

    public function test_it_lists_requests_newest_first_and_by_status(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $manager = self::addMember($organization, MembershipRole::Manager);
        $employee = self::addMember($organization, MembershipRole::Employee);
        self::mockTime('2026-11-13 09:00:00');
        $first = self::submitted($employee, $organization, null, '2026-11-10T08:00:00Z', '2026-11-10T16:00:00Z');
        self::mockTime('2026-11-13 10:00:00');
        $second = self::submitted($manager, $organization, null, '2026-11-11T08:00:00Z', '2026-11-11T16:00:00Z');
        self::mockTime('2026-11-13 11:00:00');
        $third = self::submitted($employee, $organization, null, '2026-11-12T08:00:00Z', '2026-11-12T16:00:00Z');
        self::mockTime(self::NOW);
        self::reject($owner, $organization, $second, []);

        self::assertSame([$third, $second, $first], array_column(self::list($manager, $organization), 'id'));
        self::assertSame([$third, $first], array_column(self::list($owner, $organization, 'pending'), 'id'));
        self::assertSame([$second], array_column(self::list($owner, $organization, 'rejected'), 'id'));
        self::assertSame([], self::list($employee, $organization, 'rejected'));
        self::assertSame([$third, $first], array_column(self::list($employee, $organization), 'id'));

        $response = static::clientFor($owner, $organization)->request('GET', '/api/shift-change-requests', ['query' => ['status' => 'maybe']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['status'], array_column($response->toArray(false)['violations'] ?? [], 'propertyPath'));
    }

    public function test_it_answers_404_for_an_unknown_request(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        self::mockTime(self::NOW);

        foreach ([Uuid::v7()->toRfc4122(), 'not-a-uuid'] as $requestId) {
            self::get($owner, $organization, $requestId);
            self::assertResponseStatusCodeSame(404);
            self::approve($owner, $organization, $requestId);
            self::assertResponseStatusCodeSame(404);
            self::reject($owner, $organization, $requestId, []);
            self::assertResponseStatusCodeSame(404);
            self::withdraw($owner, $organization, $requestId);
            self::assertResponseStatusCodeSame(404);
        }
    }

    public function test_it_keeps_deactivated_members_out_but_still_decides_their_requests(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $deactivatedManager = self::addMember($organization, MembershipRole::Manager);
        $leaver = self::addMember($organization, MembershipRole::Employee);
        OrganizationMother::deactivateMember($organization, $deactivatedManager);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        self::mockTime(self::NOW);
        $requestId = self::submitted($leaver, $organization, null, '2026-11-12T08:00:00Z', '2026-11-12T16:00:00Z');
        self::assertQueuedEmailCount(1); // the owner; a deactivated manager is not told
        self::deactivate($organization, $leaver);

        foreach ([$deactivatedManager, $leaver, UserFactory::createOne()] as $user) {
            self::submit($user, $organization, ['startedAt' => '2026-11-11T08:00:00Z', 'endedAt' => '2026-11-11T16:00:00Z', 'note' => 'Please']);
            self::assertResponseStatusCodeSame(403);
            self::list($user, $organization, expectedStatus: 403);
            self::get($user, $organization, $requestId);
            self::assertResponseStatusCodeSame(403);
            self::approve($user, $organization, $requestId);
            self::assertResponseStatusCodeSame(403);
            self::reject($user, $organization, $requestId, []);
            self::assertResponseStatusCodeSame(403);
            self::withdraw($user, $organization, $requestId);
            self::assertResponseStatusCodeSame(403);
        }

        // The leaver's last shifts still count: their request is approved, and they are told.
        self::approve($owner, $organization, $requestId);
        self::assertResponseStatusCodeSame(200);
        self::assertQueuedEmailCount(1);
        self::assertSame($leaver->getEmail(), self::recipient(0));
        self::assertCount(1, self::shifts($owner, $organization, '2026-11-12'));
    }

    public function test_it_keeps_another_organizations_requests_invisible_and_undecided(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [$otherOwner, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        $employeeElsewhere = self::addMember($elsewhere, MembershipRole::Employee);
        // The owner is also an employee elsewhere: their own request there stays there.
        OrganizationMother::addMember($elsewhere, $owner, MembershipRole::Employee);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $shiftElsewhere = self::workShift($employeeElsewhere, $elsewhere, '2026-11-12 08:00:00', '2026-11-12 16:00:00');
        self::mockTime(self::NOW);
        $requestElsewhere = self::submitted($employeeElsewhere, $elsewhere, null, '2026-11-11T08:00:00Z', '2026-11-11T16:00:00Z');
        $ownRequestElsewhere = self::submitted($owner, $elsewhere, null, '2026-11-11T08:00:00Z', '2026-11-11T16:00:00Z');

        self::assertSame([], self::list($owner, $organization));
        foreach ([$requestElsewhere, $ownRequestElsewhere] as $requestId) {
            self::get($owner, $organization, $requestId);
            self::assertResponseStatusCodeSame(404);
            self::approve($owner, $organization, $requestId);
            self::assertResponseStatusCodeSame(404);
            self::reject($owner, $organization, $requestId, []);
            self::assertResponseStatusCodeSame(404);
            self::withdraw($owner, $organization, $requestId);
            self::assertResponseStatusCodeSame(404);
        }

        // A shift elsewhere cannot be asked about here.
        self::submit($owner, $organization, ['shiftId' => $shiftElsewhere, 'startedAt' => '2026-11-12T07:00:00Z', 'endedAt' => '2026-11-12T16:00:00Z', 'note' => 'Please']);
        self::assertResponseStatusCodeSame(404);

        self::assertSame(['pending', 'pending'], array_column(self::list($otherOwner, $elsewhere), 'status'));
        self::assertSame([], self::shifts($otherOwner, $elsewhere, '2026-11-11'));
    }

    public function test_it_requires_an_organization_and_authentication(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        self::mockTime(self::NOW);
        $requestId = self::submitted($owner, $organization, null, '2026-11-12T08:00:00Z', '2026-11-12T16:00:00Z');
        $body = ['startedAt' => '2026-11-11T08:00:00Z', 'endedAt' => '2026-11-11T16:00:00Z', 'note' => 'Please'];

        foreach ([[$owner, 400], [null, 401]] as [$user, $status]) {
            $client = null === $user
                ? static::createClient(defaultOptions: ['headers' => ['Accept' => 'application/json']])
                : static::clientFor($user);
            $client->request('POST', '/api/shift-change-requests', ['json' => $body]);
            self::assertResponseStatusCodeSame($status);
            $client->request('GET', '/api/shift-change-requests');
            self::assertResponseStatusCodeSame($status);
            $client->request('GET', '/api/shift-change-requests/'.$requestId);
            self::assertResponseStatusCodeSame($status);
            $client->request('POST', '/api/shift-change-requests/'.$requestId.'/approve');
            self::assertResponseStatusCodeSame($status);
            $client->request('POST', '/api/shift-change-requests/'.$requestId.'/reject', ['json' => []]);
            self::assertResponseStatusCodeSame($status);
            $client->request('POST', '/api/shift-change-requests/'.$requestId.'/withdraw');
            self::assertResponseStatusCodeSame($status);
        }
    }

    /** The address the queued e-mail at this index goes to. */
    private static function recipient(int $index): string
    {
        $email = self::getMailerMessage($index);
        self::assertInstanceOf(Email::class, $email);

        return $email->getTo()[0]->getAddress();
    }

    /** @param array<string, mixed> $body */
    private static function submit(User $user, Organization $organization, array $body): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('POST', '/api/shift-change-requests', ['json' => $body]);
    }

    /** Submits a request and returns its id. */
    private static function submitted(User $user, Organization $organization, ?string $shiftId, string $startedAt, ?string $endedAt, string $note = 'Please fix it'): string
    {
        $response = self::submit($user, $organization, ['shiftId' => $shiftId, 'startedAt' => $startedAt, 'endedAt' => $endedAt, 'note' => $note]);
        self::assertResponseStatusCodeSame(201);

        return $response->toArray()['id'];
    }

    private static function get(User $user, Organization $organization, string $requestId): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('GET', '/api/shift-change-requests/'.$requestId);
    }

    /** @return list<array<string, mixed>> */
    private static function list(User $user, Organization $organization, ?string $status = null, int $expectedStatus = 200): array
    {
        $response = static::clientFor($user, $organization)->request('GET', '/api/shift-change-requests', ['query' => null === $status ? [] : ['status' => $status]]);
        self::assertResponseStatusCodeSame($expectedStatus);

        /** @var list<array<string, mixed>> */
        return 200 === $expectedStatus ? $response->toArray() : [];
    }

    private static function approve(User $user, Organization $organization, string $requestId): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('POST', '/api/shift-change-requests/'.$requestId.'/approve');
    }

    private static function withdraw(User $user, Organization $organization, string $requestId): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('POST', '/api/shift-change-requests/'.$requestId.'/withdraw');
    }

    /** @param array<string, mixed> $body */
    private static function reject(User $user, Organization $organization, string $requestId, array $body): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('POST', '/api/shift-change-requests/'.$requestId.'/reject', ['json' => $body]);
    }

    /** @return list<array<string, mixed>> the shifts that started on these business dates (Europe/Warsaw) */
    private static function shifts(User $user, Organization $organization, string $from, ?string $to = null): array
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

    /** Deactivates a member after requests were made (the organization from the arrangement is detached by then). */
    private static function deactivate(Organization $organization, User $user): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $managed = $entityManager->find(Organization::class, $organization->getId()) ?? throw new \LogicException('Organization expected.');
        OrganizationMother::deactivateMember($managed, $user);
        $entityManager->flush();
    }
}
