<?php

declare(strict_types=1);

namespace App\Tests\Api\TimeTracking;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Tests\Api\ApiTestCase;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixtures\OrganizationMother;
use App\TimeTracking\Application\Command\LockShiftPeriod\LockShiftPeriodCommand;
use App\User\Domain\Model\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * DELETE /shifts/{id}: owners and managers delete a shift for good (#28), except a running one, one in
 * a month locked by an approved payroll run (ADR 0027 §8) and one with a pending change request. The
 * organization is in Europe/Warsaw; the server's time is mocked at NOW (UTC), and shifts are clocked
 * through the clock-in and clock-out endpoints at earlier mocked times.
 */
final class ShiftDeletionApiTest extends ApiTestCase
{
    private const string NOW = '2026-11-13 12:00:00';

    /** @return iterable<string, array{MembershipRole}> */
    public static function managingRoles(): iterable
    {
        yield 'owner' => [MembershipRole::Owner];
        yield 'manager' => [MembershipRole::Manager];
    }

    #[DataProvider('managingRoles')]
    public function test_it_lets_owners_and_managers_delete_a_shift(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $deleted = self::workShift($employee, $organization, '2026-11-12 16:00:00', '2026-11-12 23:00:00');
        $kept = self::workShift($employee, $organization, '2026-11-12 08:00:00', '2026-11-12 12:00:00');
        self::mockTime(self::NOW);

        $response = self::delete($actor, $organization, $deleted);

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $response->getContent());
        self::assertSame([$kept], array_column(self::list($actor, $organization, '2026-11-12'), 'id'));

        self::delete($actor, $organization, $deleted);
        self::assertResponseStatusCodeSame(404);
    }

    public function test_it_lets_a_manager_delete_their_own_shift_an_owners_and_a_deactivated_members(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $manager = self::addMember($organization, MembershipRole::Manager);
        $leaver = self::addMember($organization, MembershipRole::Employee);
        $shifts = [
            self::workShift($manager, $organization, '2026-11-12 08:00:00', '2026-11-12 12:00:00'),
            self::workShift($owner, $organization, '2026-11-12 09:00:00', '2026-11-12 17:00:00'),
            self::workShift($leaver, $organization, '2026-11-12 16:00:00', '2026-11-12 23:00:00'),
        ];
        // The requests above rebooted the kernel and detached $organization and $leaver: reload them.
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        OrganizationMother::deactivateMember(
            $entityManager->find(Organization::class, $organization->getId()) ?? throw new \LogicException('Organization expected.'),
            $entityManager->find(User::class, $leaver->getId()) ?? throw new \LogicException('User expected.'),
        );
        $entityManager->flush();
        self::mockTime(self::NOW);
        static::clientFor($leaver, $organization)->request('POST', '/api/shifts/clock-in');
        self::assertResponseStatusCodeSame(403);

        foreach ($shifts as $shiftId) {
            self::delete($manager, $organization, $shiftId);
            self::assertResponseStatusCodeSame(204, $shiftId);
        }

        self::assertSame([], self::list($owner, $organization, '2026-11-12'));
    }

    public function test_it_refuses_an_employee_whatever_the_shift(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $own = self::workShift($employee, $organization, '2026-11-12 16:00:00', '2026-11-12 23:00:00');
        $owners = self::workShift($owner, $organization, '2026-11-12 09:00:00', '2026-11-12 17:00:00');
        self::mockTime(self::NOW);

        foreach ([$own, $owners, Uuid::v7()->toRfc4122()] as $shiftId) {
            $response = self::delete($employee, $organization, $shiftId);
            self::assertResponseStatusCodeSame(403, $shiftId);
            self::assertSame('time_tracking.shift_change_forbidden', $response->toArray(false)['code']);
        }

        self::assertCount(2, self::list($owner, $organization, '2026-11-12'));
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
            self::delete($user, $organization, $shiftId);
            self::assertResponseStatusCodeSame(403);
        }

        self::assertCount(1, self::list($owner, $organization, '2026-11-12'));
    }

    public function test_it_answers_404_for_an_unknown_shift(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        self::mockTime(self::NOW);

        $response = self::delete($owner, $organization, Uuid::v7()->toRfc4122());
        self::assertResponseStatusCodeSame(404);
        self::assertSame('time_tracking.shift_not_found.with_id', $response->toArray(false)['code']);

        self::delete($owner, $organization, 'not-a-uuid');
        self::assertResponseStatusCodeSame(404);
    }

    public function test_it_refuses_to_delete_a_running_shift(): void
    {
        [$manager, $organization] = self::memberWithRole(MembershipRole::Manager);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $running = self::workShift($employee, $organization, '2026-11-13 08:00:00', null);
        self::mockTime(self::NOW);

        $response = self::delete($manager, $organization, $running);

        self::assertResponseStatusCodeSame(409);
        $problem = $response->toArray(false);
        self::assertSame('time_tracking.open_shift_cannot_be_deleted', $problem['code']);
        self::assertSame(['shiftId' => $running], $problem['parameters']);
        self::assertSame($running, static::clientFor($employee, $organization)->request('GET', '/api/shifts/current')->toArray()['id']);
    }

    public function test_it_refuses_to_delete_a_shift_of_a_locked_month_and_deletes_one_of_the_next(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        // A night that started on the last day of October belongs to October, though it ends in November.
        $october = self::workShift($employee, $organization, '2026-10-31 19:00:00', '2026-11-01 02:00:00');
        // 23:30 UTC on 31 October is already 1 November in Warsaw.
        $november = self::workShift($employee, $organization, '2026-10-31 23:30:00', '2026-11-01 06:00:00');
        self::lockOctober($organization);
        self::mockTime(self::NOW);

        $response = self::delete($owner, $organization, $october);
        self::assertResponseStatusCodeSame(409);
        $problem = $response->toArray(false);
        self::assertSame('time_tracking.shift_period_locked', $problem['code']);
        self::assertSame(['date' => '2026-10-31'], $problem['parameters']);

        self::delete($owner, $organization, $november);
        self::assertResponseStatusCodeSame(204);

        self::assertSame([$october], array_column(self::list($owner, $organization, '2026-10-31', '2026-11-01'), 'id'));
    }

    public function test_it_refuses_to_delete_a_shift_with_a_pending_change_request_until_it_is_decided(): void
    {
        [$manager, $organization] = self::memberWithRole(MembershipRole::Manager);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $shiftId = self::workShift($employee, $organization, '2026-11-12 16:00:00', '2026-11-12 23:00:00');
        self::mockTime(self::NOW);
        $requestId = self::submitted($employee, $organization, [
            'shiftId' => $shiftId,
            'startedAt' => '2026-11-12T15:30:00Z',
            'endedAt' => '2026-11-12T23:00:00Z',
        ]);

        $response = self::delete($manager, $organization, $shiftId);
        self::assertResponseStatusCodeSame(409);
        $problem = $response->toArray(false);
        self::assertSame('time_tracking.shift_has_pending_change_request', $problem['code']);
        self::assertSame(['shiftId' => $shiftId, 'requestId' => $requestId], $problem['parameters']);

        static::clientFor($manager, $organization)->request('POST', '/api/shift-change-requests/'.$requestId.'/reject', ['json' => []]);
        self::assertResponseStatusCodeSame(200);

        self::delete($manager, $organization, $shiftId);
        self::assertResponseStatusCodeSame(204);

        // The decided request stays, with its shift's id; the shift's own times are gone.
        $request = static::clientFor($manager, $organization)->request('GET', '/api/shift-change-requests/'.$requestId)->toArray();
        self::assertSame(['rejected', $shiftId, null, null], [$request['status'], $request['shiftId'], $request['shiftStartedAt'], $request['shiftEndedAt']]);
    }

    public function test_it_deletes_a_shift_once_its_change_request_is_withdrawn(): void
    {
        [$manager, $organization] = self::memberWithRole(MembershipRole::Manager);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $shiftId = self::workShift($employee, $organization, '2026-11-12 16:00:00', '2026-11-12 23:00:00');
        self::mockTime(self::NOW);
        $requestId = self::submitted($employee, $organization, [
            'shiftId' => $shiftId,
            'startedAt' => '2026-11-12T15:30:00Z',
            'endedAt' => '2026-11-12T23:00:00Z',
        ]);

        static::clientFor($employee, $organization)->request('POST', '/api/shift-change-requests/'.$requestId.'/withdraw');
        self::assertResponseStatusCodeSame(200);

        self::delete($manager, $organization, $shiftId);
        self::assertResponseStatusCodeSame(204);
    }

    public function test_it_keeps_another_organizations_shifts_invisible_and_undeleted(): void
    {
        [$owner, $here] = self::memberWithRole(MembershipRole::Owner);
        [$ownerElsewhere, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        OrganizationMother::addMember($elsewhere, $owner, MembershipRole::Employee);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $theirs = self::workShift($ownerElsewhere, $elsewhere, '2026-11-12 16:00:00', '2026-11-12 23:00:00');
        $mineThere = self::workShift($owner, $elsewhere, '2026-11-12 08:00:00', '2026-11-12 12:00:00');
        self::mockTime(self::NOW);

        foreach ([$theirs, $mineThere] as $shiftId) {
            self::delete($owner, $here, $shiftId);
            self::assertResponseStatusCodeSame(404, $shiftId);
        }

        // The person is an owner here and only an employee there: no deleting there either.
        self::delete($owner, $elsewhere, $theirs);
        self::assertResponseStatusCodeSame(403);

        self::assertSame([$theirs, $mineThere], array_column(self::list($ownerElsewhere, $elsewhere, '2026-11-12'), 'id'));
    }

    public function test_it_requires_an_organization_and_authentication(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $shiftId = self::workShift($owner, $organization, '2026-11-12 08:00:00', '2026-11-12 16:00:00');
        self::mockTime(self::NOW);

        static::clientFor($owner)->request('DELETE', '/api/shifts/'.$shiftId);
        self::assertResponseStatusCodeSame(400);

        static::createClient()->request('DELETE', '/api/shifts/'.$shiftId, ['headers' => ['Accept' => 'application/json']]);
        self::assertResponseStatusCodeSame(401);

        self::assertCount(1, self::list($owner, $organization, '2026-11-12'));
    }

    private static function delete(User $user, Organization $organization, string $shiftId): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('DELETE', '/api/shifts/'.$shiftId);
    }

    /** @param array<string, mixed> $body */
    private static function submitted(User $user, Organization $organization, array $body): string
    {
        $response = static::clientFor($user, $organization)->request('POST', '/api/shift-change-requests', ['json' => $body + ['note' => 'I started earlier']]);
        self::assertResponseStatusCodeSame(201);

        return $response->toArray()['id'];
    }

    /** Locks October 2026 as Payroll's event handler does on approval: in the organization's tenant context. */
    private static function lockOctober(Organization $organization): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $tenantContext = static::getContainer()->get(TenantContextInterface::class);
        $tenantContext->switchTo($entityManager->find(Organization::class, $organization->getId()) ?? throw new \LogicException('Organization expected.'));
        try {
            static::getContainer()->get(CommandBusInterface::class)->handle(new LockShiftPeriodCommand(
                $organization->getId()->toRfc4122(),
                Uuid::v7()->toRfc4122(),
                Uuid::v7()->toRfc4122(),
                '2026-10-01',
                '2026-10-31',
            ));
        } finally {
            $tenantContext->reset();
            $entityManager->clear();
        }
    }

    /** @return list<array<string, mixed>> the shifts that started on these business dates (Europe/Warsaw), newest first */
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
