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
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * GET /revenue-months/{month}, POST …/close and …/reopen, the lock on a closed month's revenue
 * entries, and GET /revenue-entry-changes (ADR 0034). The server's time is mocked at NOW (UTC), which
 * is already 13 November in Europe/Warsaw: October has ended, November has not.
 */
final class RevenueMonthApiTest extends IncomeApiTestCase
{
    private const string NOW = '2026-11-12 23:30:00';
    private const array MERGE_PATCH = ['Content-Type' => 'application/merge-patch+json'];

    public function test_it_lets_an_owner_close_and_reopen_a_month_and_keeps_every_action(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $manager = self::addMember($organization, MembershipRole::Manager);
        self::mockTime(self::NOW);

        self::assertSame([
            'month' => '2026-10',
            'firstDate' => '2026-10-01',
            'lastDate' => '2026-10-31',
            'ended' => true,
            'closed' => false,
            'closedBy' => null,
            'closedAt' => null,
            'actions' => [],
        ], self::month($manager, $organization, '2026-10')->toArray());

        $closed = self::action($owner, $organization, '2026-10', 'close')->toArray();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['closed' => true, 'closedBy' => $owner->getId()->toRfc4122(), 'closedAt' => '2026-11-12T23:30:00+00:00'], array_intersect_key($closed, array_flip(['closed', 'closedBy', 'closedAt'])));

        self::mockTime('2026-11-13 08:00:00');
        self::action($owner, $organization, '2026-10', 'reopen');
        self::assertResponseStatusCodeSame(200);
        $month = self::action($owner, $organization, '2026-10', 'close')->toArray();

        self::assertTrue($month['closed']);
        self::assertSame([
            ['action' => 'closed', 'actorId' => $owner->getId()->toRfc4122(), 'at' => '2026-11-12T23:30:00+00:00'],
            ['action' => 'reopened', 'actorId' => $owner->getId()->toRfc4122(), 'at' => '2026-11-13T08:00:00+00:00'],
            ['action' => 'closed', 'actorId' => $owner->getId()->toRfc4122(), 'at' => '2026-11-13T08:00:00+00:00'],
        ], $month['actions']);
        self::assertSame($month, self::month($manager, $organization, '2026-10')->toArray());
    }

    public function test_it_answers_409_for_a_month_that_has_not_ended_or_a_close_or_reopen_that_does_not_fit(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        self::mockTime(self::NOW);

        self::assertSame('income.revenue_month_not_ended', self::action($owner, $organization, '2026-11', 'close')->toArray(false)['code']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('income.revenue_month_not_closed', self::action($owner, $organization, '2026-10', 'reopen')->toArray(false)['code']);
        self::assertResponseStatusCodeSame(409);

        self::action($owner, $organization, '2026-10', 'close');
        self::assertSame('income.revenue_month_already_closed', self::action($owner, $organization, '2026-10', 'close')->toArray(false)['code']);
        self::assertResponseStatusCodeSame(409);
    }

    public function test_it_answers_404_for_a_month_that_is_not_yyyy_mm_and_422_for_one_that_does_not_exist(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        self::mockTime(self::NOW);

        self::month($owner, $organization, '2026-1');
        self::assertResponseStatusCodeSame(404);
        self::action($owner, $organization, 'october', 'close');
        self::assertResponseStatusCodeSame(404);
        self::month($owner, $organization, '2026-13');
        self::assertResponseStatusCodeSame(422);
    }

    /** @return iterable<string, array{MembershipRole|null, int, int}> role (null: a deactivated manager), GET status, close status */
    public static function roles(): iterable
    {
        yield 'owner' => [MembershipRole::Owner, 200, 200];
        yield 'manager' => [MembershipRole::Manager, 200, 403];
        yield 'employee' => [MembershipRole::Employee, 403, 403];
        yield 'deactivated manager' => [null, 403, 403];
    }

    #[DataProvider('roles')]
    public function test_it_lets_owners_and_managers_read_a_month_and_only_owners_close_and_reopen_it(?MembershipRole $role, int $readStatus, int $closeStatus): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $user = MembershipRole::Owner === $role ? $owner : self::addMember($organization, $role ?? MembershipRole::Manager);
        if (null === $role) {
            OrganizationMother::deactivateMember($organization, $user);
            static::getContainer()->get(EntityManagerInterface::class)->flush();
        }
        self::mockTime(self::NOW);

        self::month($user, $organization, '2026-10');
        self::assertResponseStatusCodeSame($readStatus);
        self::action($user, $organization, '2026-10', 'close');
        self::assertResponseStatusCodeSame($closeStatus);

        if (200 !== $closeStatus) {
            self::action($owner, $organization, '2026-10', 'close');
            if (MembershipRole::Manager === $role) {
                self::assertSame('income.revenue_month_close_forbidden', self::action($user, $organization, '2026-10', 'reopen')->toArray(false)['code']);
            } else {
                self::action($user, $organization, '2026-10', 'reopen');
            }
            self::assertResponseStatusCodeSame(403);
        }
        self::assertTrue(self::month($owner, $organization, '2026-10')->toArray()['closed']);
    }

    public function test_it_locks_the_entries_of_a_closed_month_and_unlocks_them_on_reopen(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $register = self::addRegister($organization);
        self::mockTime(self::NOW);
        $entry = self::record($owner, $organization, self::body($register, '2026-10-20'))->toArray();
        self::assertFalse($entry['monthClosed']);
        self::action($owner, $organization, '2026-10', 'close');

        $locked = self::get($owner, $organization, $entry['id'])->toArray();
        self::assertTrue($locked['monthClosed']);
        $listed = static::clientFor($owner, $organization)->request('GET', '/api/revenue-entries?from=2026-10-01&to=2026-10-31')->toArray();
        self::assertTrue($listed[0]['monthClosed']);

        foreach ([
            fn (): ResponseInterface => self::record($owner, $organization, self::body($register, '2026-10-21')),
            fn (): ResponseInterface => self::correct($owner, $organization, $entry['id'], ['note' => 'Late']),
            fn (): ResponseInterface => self::correct($owner, $organization, $entry['id'], []),
            fn (): ResponseInterface => self::delete($owner, $organization, $entry['id']),
        ] as $request) {
            $error = $request()->toArray(false);
            self::assertResponseStatusCodeSame(409);
            self::assertSame('income.revenue_month_closed', $error['code']);
        }
        self::assertSame(['month' => '2026-10', 'businessDate' => '2026-10-20'], $error['parameters'] ?? null);

        self::action($owner, $organization, '2026-10', 'reopen');
        self::correct($owner, $organization, $entry['id'], ['note' => 'Late']);
        self::assertResponseStatusCodeSame(200);
    }

    public function test_it_keeps_the_history_of_an_entry_after_it_is_deleted(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $manager = self::addMember($organization, MembershipRole::Manager);
        $register = self::addRegister($organization);
        self::mockTime(self::NOW);
        $entry = self::record($manager, $organization, self::body($register, '2026-11-12'))->toArray();
        self::mockTime('2026-11-13 08:00:00');
        self::correct($owner, $organization, $entry['id'], ['payments' => [self::pay($organization, 'Cash', 12_000)], 'note' => 'Recount']);
        self::mockTime('2026-11-13 09:00:00');
        self::delete($manager, $organization, $entry['id']);

        $history = self::changes($owner, $organization, '?entryId='.$entry['id'])->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['deleted', 'changed', 'created'], array_column($history, 'kind'));
        self::assertSame([
            'id' => $history[1]['id'],
            'entryId' => $entry['id'],
            'registerId' => $register->getId()->toRfc4122(),
            'registerName' => 'Bar',
            'businessDate' => '2026-11-12',
            'currency' => 'PLN',
            'kind' => 'changed',
            'dayCloseId' => null,
            'before' => ['payments' => [self::paid($organization, 'Cash', 10_000)], 'total' => 10_000, 'vatLines' => [], 'note' => null],
            'after' => ['payments' => [self::paid($organization, 'Cash', 12_000)], 'total' => 12_000, 'vatLines' => [], 'note' => 'Recount'],
            'changedBy' => $owner->getId()->toRfc4122(),
            'changedAt' => '2026-11-13T08:00:00+00:00',
        ], $history[1]);
        self::assertNull($history[0]['after']);
        self::assertSame($manager->getId()->toRfc4122(), $history[0]['changedBy']);
        self::assertNull($history[2]['before']);

        self::assertSame(array_column($history, 'id'), array_column(self::changes($manager, $organization, '?from=2026-11-01&to=2026-11-30')->toArray(), 'id'));
        self::assertSame([], self::changes($manager, $organization, '?from=2026-10-01&to=2026-10-31')->toArray());
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidHistoryQueries(): iterable
    {
        yield 'nothing' => ['', 'from'];
        yield 'from only' => ['?from=2026-11-01', 'to'];
        yield 'invalid entry id' => ['?entryId=nope', 'entryId'];
        yield 'invalid date' => ['?from=2026-11-01&to=2026-11-31', 'to'];
        yield 'too long' => ['?from=2026-01-01&to=2026-11-30', 'to'];
    }

    #[DataProvider('invalidHistoryQueries')]
    public function test_it_answers_422_for_an_invalid_history_query(string $query, string $invalidField): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        $violations = self::changes($owner, $organization, $query)->toArray(false)['violations'] ?? [];

        self::assertResponseStatusCodeSame(422);
        self::assertContains($invalidField, array_column($violations, 'propertyPath'));
    }

    public function test_it_refuses_the_history_to_employees(): void
    {
        [, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);

        self::changes($employee, $organization, '?from=2026-11-01&to=2026-11-30');
        self::assertResponseStatusCodeSame(403);
    }

    public function test_it_keeps_another_organizations_months_and_history_apart(): void
    {
        // Each organization is arranged in turn: arranging one clears the entity manager.
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $body = self::body(self::addRegister($organization), '2026-10-20');
        [$ownerElsewhere, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        $bodyElsewhere = self::body(self::addRegister($elsewhere), '2026-10-20');
        self::mockTime(self::NOW);
        $entry = self::record($owner, $organization, $body)->toArray();
        $entryElsewhere = self::record($ownerElsewhere, $elsewhere, $bodyElsewhere)->toArray();

        self::action($ownerElsewhere, $elsewhere, '2026-10', 'close');

        // Closed elsewhere only: here the month is open and the entry can change.
        self::assertFalse(self::month($owner, $organization, '2026-10')->toArray()['closed']);
        self::correct($owner, $organization, $entry['id'], ['note' => 'Still open here']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], self::changes($owner, $organization, '?entryId='.$entryElsewhere['id'])->toArray());
        self::assertSame([$entry['id']], array_unique(array_column(self::changes($owner, $organization, '?from=2026-10-01&to=2026-10-31')->toArray(), 'entryId')));
        self::assertTrue(self::month($ownerElsewhere, $elsewhere, '2026-10')->toArray()['closed']);
    }

    private static function month(User $user, Organization $organization, string $month): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('GET', '/api/revenue-months/'.$month);
    }

    private static function action(User $user, Organization $organization, string $month, string $action): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('POST', '/api/revenue-months/'.$month.'/'.$action);
    }

    private static function changes(User $user, Organization $organization, string $query): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('GET', '/api/revenue-entry-changes'.$query);
    }

    /** @param array<string, mixed> $body */
    private static function record(User $user, Organization $organization, array $body): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('POST', '/api/revenue-entries', ['json' => $body]);
    }

    private static function get(User $user, Organization $organization, string $id): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('GET', '/api/revenue-entries/'.$id);
    }

    /** @param array<string, mixed> $body */
    private static function correct(User $user, Organization $organization, string $id, array $body): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('PATCH', '/api/revenue-entries/'.$id, ['json' => $body, 'headers' => self::MERGE_PATCH]);
    }

    private static function delete(User $user, Organization $organization, string $id): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('DELETE', '/api/revenue-entries/'.$id);
    }

    /** @return array{registerId: string, businessDate: string, payments: list<array{paymentMethodId: string, amount: int}>} */
    private static function body(Register $register, string $businessDate): array
    {
        $organization = $register->getOrganization() ?? throw new \LogicException('A stored register expected.');

        return ['registerId' => $register->getId()->toRfc4122(), 'businessDate' => $businessDate, 'payments' => [self::pay($organization, 'Cash', 10_000)]];
    }
}
