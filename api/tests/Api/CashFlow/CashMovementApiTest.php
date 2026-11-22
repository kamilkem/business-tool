<?php

declare(strict_types=1);

namespace App\Tests\Api\CashFlow;

use App\CashFlow\Domain\Model\CashStorage;
use App\CashFlow\Domain\Model\CashStorageMode;
use App\CashFlow\Domain\Model\Denomination;
use App\CashFlow\Domain\Model\DenominationKind;
use App\CashFlow\Domain\Repository\CashStorageRepositoryInterface;
use App\CashFlow\Domain\Repository\DenominationRepositoryInterface;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;
use App\Tests\Api\ApiTestCase;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixtures\OrganizationMother;
use App\User\Domain\Model\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * POST /cash-movements/adjustments, /transfers and /counts, GET and DELETE /cash-movements and
 * GET /cash-balances (ADR 0033 §4, §6). The organization is Polish (PLN, Europe/Warsaw), with a
 * counted "Kasetka", a deposit "Sejf" and the 50, 100 and 200 zł notes. The server's time is mocked
 * at NOW (UTC).
 */
final class CashMovementApiTest extends ApiTestCase
{
    private const string NOW = '2026-11-22 10:00:00';

    /** @return iterable<string, array{MembershipRole}> */
    public static function managingRoles(): iterable
    {
        yield 'owner' => [MembershipRole::Owner];
        yield 'manager' => [MembershipRole::Manager];
    }

    #[DataProvider('managingRoles')]
    public function test_it_lets_owners_and_managers_adjust_a_storage_and_see_its_balance_and_history(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        $cash = self::cash($organization);
        self::mockTime(self::NOW);

        $response = self::post($actor, $organization, 'adjustments', [
            'storageId' => $cash['safe'],
            'lines' => [['denominationId' => $cash[100], 'pieces' => 10], ['denominationId' => $cash[50], 'pieces' => 4]],
            'note' => ' Opening float ',
        ]);

        self::assertResponseStatusCodeSame(201);
        $movement = $response->toArray();
        self::assertInstanceOf(UuidV7::class, Uuid::fromString($movement['id']));
        self::assertSame([
            'id' => $movement['id'],
            'kind' => 'adjustment',
            'occurredAt' => '2026-11-22T10:00:00+00:00',
            'storageId' => $cash['safe'],
            'storageName' => 'Sejf',
            'targetStorageId' => null,
            'targetStorageName' => null,
            'currency' => 'PLN',
            'lines' => [
                ['denominationId' => $cash[50], 'value' => 5_000, 'kind' => 'note', 'pieces' => 4, 'amount' => 20_000],
                ['denominationId' => $cash[100], 'value' => 10_000, 'kind' => 'note', 'pieces' => 10, 'amount' => 100_000],
            ],
            'total' => 120_000,
            'discrepancy' => null,
            'note' => 'Opening float',
            'createdBy' => $actor->getId()->toRfc4122(),
            'createdByFirstName' => $actor->getFirstName(),
            'createdByLastName' => $actor->getLastName(),
            'createdAt' => '2026-11-22T10:00:00+00:00',
        ], $movement);
        self::assertSame($movement, self::get($actor, $organization, $movement['id'])->toArray());
        self::assertSame([$movement], self::history($actor, $organization, '2026-11-22', '2026-11-22')->toArray());

        $balances = self::balances($actor, $organization)->toArray();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Kasetka', 'Sejf'], array_column($balances, 'name'));
        self::assertSame([
            'storageId' => $cash['safe'],
            'name' => 'Sejf',
            'mode' => 'deposit',
            'active' => true,
            'currency' => 'PLN',
            'lines' => [
                ['denominationId' => $cash[50], 'currency' => 'PLN', 'value' => 5_000, 'kind' => 'note', 'active' => true, 'pieces' => 4, 'amount' => 20_000],
                ['denominationId' => $cash[100], 'currency' => 'PLN', 'value' => 10_000, 'kind' => 'note', 'active' => true, 'pieces' => 10, 'amount' => 100_000],
                ['denominationId' => $cash[200], 'currency' => 'PLN', 'value' => 20_000, 'kind' => 'note', 'active' => true, 'pieces' => 0, 'amount' => 0],
            ],
            'total' => 120_000,
            'estimate' => false,
            'countedAt' => null,
        ], $balances[1]);
        self::assertSame([0, true], [$balances[0]['total'], $balances[0]['estimate']]);
    }

    public function test_it_transfers_cash_and_takes_an_audit_count_with_its_discrepancy(): void
    {
        [$manager, $organization] = self::memberWithRole(MembershipRole::Manager);
        $cash = self::cash($organization);
        self::mockTime('2026-11-21 20:00:00');
        self::post($manager, $organization, 'adjustments', ['storageId' => $cash['box'], 'lines' => [['denominationId' => $cash[100], 'pieces' => 6]]]);
        self::mockTime(self::NOW);

        $transfer = self::post($manager, $organization, 'transfers', [
            'fromStorageId' => $cash['box'],
            'toStorageId' => $cash['safe'],
            'lines' => [['denominationId' => $cash[100], 'pieces' => 5]],
            'occurredAt' => '2026-11-21T23:30:00+01:00',
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(
            ['transfer', '2026-11-21T22:30:00+00:00', $cash['box'], 'Kasetka', $cash['safe'], 'Sejf', 50_000, [5]],
            [...array_values(array_intersect_key($transfer->toArray(), array_flip(['kind', 'occurredAt', 'storageId', 'storageName', 'targetStorageId', 'targetStorageName', 'total']))), array_column($transfer->toArray()['lines'], 'pieces')],
        );

        $count = self::post($manager, $organization, 'counts', ['storageId' => $cash['safe'], 'lines' => [['denominationId' => $cash[100], 'pieces' => 4], ['denominationId' => $cash[50], 'pieces' => 1]]]);
        self::assertResponseStatusCodeSame(201);
        // 4 × 100 + 1 × 50 counted, 5 × 100 expected.
        self::assertSame(['count', 45_000, -5_000, [1, 4, 0]], [$count->toArray()['kind'], $count->toArray()['total'], $count->toArray()['discrepancy'], array_column($count->toArray()['lines'], 'pieces')]);

        $balances = self::balances($manager, $organization)->toArray();
        self::assertSame(
            [['Kasetka', 10_000, null], ['Sejf', 45_000, '2026-11-22T10:00:00+00:00']],
            array_map(static fn (array $balance): array => [$balance['name'], $balance['total'], $balance['countedAt']], $balances),
        );
        self::assertSame(['count', 'transfer'], array_column(self::history($manager, $organization, '2026-11-21', '2026-11-22', $cash['safe'])->toArray(), 'kind'));
    }

    public function test_it_answers_409_when_a_deposit_storage_would_go_below_zero_on_a_write_or_a_delete(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $cash = self::cash($organization);
        self::mockTime('2026-11-21 08:00:00');
        $float = self::post($owner, $organization, 'adjustments', ['storageId' => $cash['safe'], 'lines' => [['denominationId' => $cash[100], 'pieces' => 2]]])->toArray()['id'];
        self::mockTime(self::NOW);
        self::post($owner, $organization, 'adjustments', ['storageId' => $cash['safe'], 'lines' => [['denominationId' => $cash[100], 'pieces' => -2]], 'note' => 'Bank run']);
        self::assertResponseStatusCodeSame(201);

        // A backdated withdrawal leaves the bank run without cover.
        $response = self::post($owner, $organization, 'transfers', [
            'fromStorageId' => $cash['safe'],
            'toStorageId' => $cash['box'],
            'lines' => [['denominationId' => $cash[100], 'pieces' => 1]],
            'occurredAt' => '2026-11-21T12:00:00+01:00',
        ]);

        self::assertResponseStatusCodeSame(409);
        $problem = $response->toArray(false);
        self::assertSame('cash_flow.storage_balance_negative', $problem['code']);
        self::assertSame([
            'storageId' => $cash['safe'],
            'name' => 'Sejf',
            'denominationId' => $cash[100],
            'currency' => 'PLN',
            'value' => 10_000,
            'pieces' => -1,
            'at' => '2026-11-22T10:00:00+00:00',
        ], $problem['parameters']);
        self::assertCount(2, self::history($owner, $organization, '2026-11-21', '2026-11-22')->toArray());

        self::delete($owner, $organization, $float);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('cash_flow.storage_balance_negative', self::delete($owner, $organization, $float)->toArray(false)['code']);
        self::get($owner, $organization, $float);
        self::assertResponseStatusCodeSame(200);
    }

    public function test_it_deletes_a_movement_and_frees_the_denomination_it_used(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $cash = self::cash($organization);
        $id = self::post($owner, $organization, 'adjustments', ['storageId' => $cash['box'], 'lines' => [['denominationId' => $cash[200], 'pieces' => 1]]])->toArray()['id'];

        static::clientFor($owner, $organization)->request('DELETE', '/api/denominations/'.$cash[200]);
        self::assertResponseStatusCodeSame(409);

        self::delete($owner, $organization, $id);
        self::assertResponseStatusCodeSame(204);
        self::get($owner, $organization, $id);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, self::balances($owner, $organization)->toArray()[0]['total']);
        static::clientFor($owner, $organization)->request('DELETE', '/api/denominations/'.$cash[200]);
        self::assertResponseStatusCodeSame(204);
    }

    public function test_it_answers_409_for_an_audit_count_of_a_counted_storage(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $cash = self::cash($organization);

        $response = self::post($owner, $organization, 'counts', ['storageId' => $cash['box'], 'lines' => []]);

        self::assertResponseStatusCodeSame(409);
        $problem = $response->toArray(false);
        self::assertSame('cash_flow.audit_count_needs_deposit_storage', $problem['code']);
        self::assertSame(['storageId' => $cash['box'], 'name' => 'Kasetka'], $problem['parameters']);
    }

    /** @return iterable<string, array{string, array<string, mixed>, list<string>}> */
    public static function invalidBodies(): iterable
    {
        yield 'no field' => ['adjustments', [], ['lines', 'storageId']];
        yield 'no line' => ['adjustments', ['storageId' => 'SAFE', 'lines' => []], ['lines']];
        yield 'zero pieces' => ['adjustments', ['storageId' => 'SAFE', 'lines' => [['denominationId' => 'D100', 'pieces' => 0]]], ['lines[0].pieces']];
        yield 'a fraction of a piece' => ['adjustments', ['storageId' => 'SAFE', 'lines' => [['denominationId' => 'D100', 'pieces' => 1.5]]], ['lines[0].pieces']];
        yield 'a repeated denomination' => ['adjustments', ['storageId' => 'SAFE', 'lines' => [['denominationId' => 'D100', 'pieces' => 1], ['denominationId' => 'D100', 'pieces' => 2]]], ['lines']];
        yield 'a moment without an offset' => ['adjustments', ['storageId' => 'SAFE', 'lines' => [['denominationId' => 'D100', 'pieces' => 1]], 'occurredAt' => '2026-11-22 09:00'], ['occurredAt']];
        yield 'a too long note' => ['adjustments', ['storageId' => 'SAFE', 'lines' => [['denominationId' => 'D100', 'pieces' => 1]], 'note' => str_repeat('a', 256)], ['note']];
        yield 'a transfer into the same storage' => ['transfers', ['fromStorageId' => 'SAFE', 'toStorageId' => 'SAFE', 'lines' => [['denominationId' => 'D100', 'pieces' => 1]]], ['toStorageId']];
        yield 'a transfer of negative pieces' => ['transfers', ['fromStorageId' => 'SAFE', 'toStorageId' => 'BOX', 'lines' => [['denominationId' => 'D100', 'pieces' => -1]]], ['lines[0].pieces']];
        yield 'a negative count' => ['counts', ['storageId' => 'SAFE', 'lines' => [['denominationId' => 'D100', 'pieces' => -1]]], ['lines[0].pieces']];
        yield 'a count without lines' => ['counts', ['storageId' => 'SAFE'], ['lines']];
    }

    /**
     * @param array<string, mixed> $body
     * @param list<string>         $paths
     */
    #[DataProvider('invalidBodies')]
    public function test_it_answers_422_for_an_invalid_body(string $kind, array $body, array $paths): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $cash = self::cash($organization);
        array_walk_recursive($body, static function (mixed &$value) use ($cash): void {
            $value = match ($value) {
                'SAFE' => $cash['safe'],
                'BOX' => $cash['box'],
                'D100' => $cash[100],
                default => $value,
            };
        });

        $response = self::post($owner, $organization, $kind, $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($paths, self::violationPaths($response));
    }

    public function test_it_answers_422_for_a_moment_after_now_or_a_denomination_not_offered_and_translates_violations(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $cash = self::cash($organization);
        $euro = self::addDenomination($organization, 100, 'EUR')->getId()->toRfc4122();
        self::mockTime(self::NOW);

        $future = self::post($owner, $organization, 'adjustments', ['storageId' => $cash['safe'], 'lines' => [['denominationId' => $cash[100], 'pieces' => 1]], 'occurredAt' => '2026-11-22T11:00:01+01:00']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['cash_flow.movement_in_the_future', ['occurredAt' => '2026-11-22T10:00:01+00:00', 'now' => '2026-11-22T10:00:00+00:00']], [$future->toArray(false)['code'], $future->toArray(false)['parameters']]);

        $other = self::post($owner, $organization, 'adjustments', ['storageId' => $cash['safe'], 'lines' => [['denominationId' => $euro, 'pieces' => 1]]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('cash_flow.denomination_not_offered.other_currency', $other->toArray(false)['code']);

        $polish = static::clientFor($owner, $organization)->request('POST', '/api/cash-movements/adjustments', [
            'json' => ['storageId' => $cash['safe'], 'lines' => [['denominationId' => $cash[100], 'pieces' => 1], ['denominationId' => $cash[100], 'pieces' => 1]]],
            'headers' => ['Accept-Language' => 'pl'],
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Każdy nominał może wystąpić tylko raz.', $polish->toArray(false)['violations'][0]['message']);
    }

    public function test_it_answers_422_for_an_invalid_history_period(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        foreach ([['', '2026-11-30', ['from']], ['2026-11-30', '2026-11-01', ['to']], ['2026-01-01', '2026-12-31', ['to']], ['2026-11-01', '2026-11-31', ['to']]] as [$from, $to, $paths]) {
            $response = self::history($owner, $organization, $from, $to);
            self::assertResponseStatusCodeSame(422);
            self::assertSame($paths, self::violationPaths($response), $from.'..'.$to);
        }
        self::history($owner, $organization, '2026-11-01', '2026-11-30', 'not-a-uuid');
        self::assertResponseStatusCodeSame(422);
    }

    public function test_it_lists_the_history_by_business_date_in_the_organizations_time_zone(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $cash = self::cash($organization);
        self::mockTime(self::NOW);
        // 23:30 UTC on 21 November is already the 22nd in Warsaw.
        $late = self::post($owner, $organization, 'adjustments', ['storageId' => $cash['box'], 'lines' => [['denominationId' => $cash[50], 'pieces' => 1]], 'occurredAt' => '2026-11-21T23:30:00Z'])->toArray()['id'];
        $early = self::post($owner, $organization, 'adjustments', ['storageId' => $cash['box'], 'lines' => [['denominationId' => $cash[50], 'pieces' => 1]], 'occurredAt' => '2026-11-21T22:59:59Z'])->toArray()['id'];

        self::assertSame([$late], array_column(self::history($owner, $organization, '2026-11-22', '2026-11-22')->toArray(), 'id'));
        self::assertSame([$early], array_column(self::history($owner, $organization, '2026-11-21', '2026-11-21')->toArray(), 'id'));
        self::assertSame([], self::history($owner, $organization, '2026-11-21', '2026-11-22', $cash['safe'])->toArray());
    }

    public function test_it_answers_404_for_another_organizations_storage_denomination_or_movement(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $cash = self::cash($organization);
        [$otherOwner, $other] = self::memberWithRole(MembershipRole::Owner);
        $theirs = self::cash($other);
        $theirMovement = self::post($otherOwner, $other, 'adjustments', ['storageId' => $theirs['safe'], 'lines' => [['denominationId' => $theirs[100], 'pieces' => 3]]])->toArray()['id'];

        $cases = [
            'storage' => [fn () => self::post($owner, $organization, 'adjustments', ['storageId' => $theirs['safe'], 'lines' => [['denominationId' => $cash[100], 'pieces' => 1]]]), 'cash_flow.storage_not_found'],
            'target storage' => [fn () => self::post($owner, $organization, 'transfers', ['fromStorageId' => $cash['box'], 'toStorageId' => $theirs['safe'], 'lines' => [['denominationId' => $cash[100], 'pieces' => 1]]]), 'cash_flow.storage_not_found'],
            'denomination' => [fn () => self::post($owner, $organization, 'adjustments', ['storageId' => $cash['safe'], 'lines' => [['denominationId' => $theirs[100], 'pieces' => 1]]]), 'cash_flow.denomination_not_found'],
            'movement' => [fn () => self::get($owner, $organization, $theirMovement), 'cash_flow.movement_not_found'],
            'deleted movement' => [fn () => self::delete($owner, $organization, $theirMovement), 'cash_flow.movement_not_found'],
            'unknown movement' => [fn () => self::get($owner, $organization, Uuid::v7()->toRfc4122()), 'cash_flow.movement_not_found'],
            'history storage' => [fn () => self::history($owner, $organization, '2026-11-01', '2026-11-30', $theirs['safe']), 'cash_flow.storage_not_found'],
        ];
        foreach ($cases as $case => [$request, $code]) {
            $response = $request();
            self::assertResponseStatusCodeSame(404, $case);
            self::assertSame($code, $response->toArray(false)['code'], $case);
        }

        // Nothing of theirs shows, and their movement is still there.
        self::assertSame([], self::history($owner, $organization, '2026-11-01', '2026-11-30')->toArray());
        self::assertSame([0, 0], array_column(self::balances($owner, $organization)->toArray(), 'total'));
        self::assertSame(30_000, self::balances($otherOwner, $other)->toArray()[1]['total']);
        self::assertSame($theirMovement, self::get($otherOwner, $other, $theirMovement)->toArray()['id']);
    }

    public function test_it_refuses_employees_every_ledger_endpoint(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = UserFactory::createOne();
        OrganizationMother::addMember($organization, $employee, MembershipRole::Employee);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $cash = self::cash($organization);
        $id = self::post($owner, $organization, 'adjustments', ['storageId' => $cash['safe'], 'lines' => [['denominationId' => $cash[100], 'pieces' => 1]]])->toArray()['id'];

        $line = [['denominationId' => $cash[100], 'pieces' => 1]];
        $requests = [
            'balances' => [fn () => self::balances($employee, $organization), 'to_read_ledger'],
            'history' => [fn () => self::history($employee, $organization, '2026-11-01', '2026-11-30'), 'to_read_ledger'],
            'movement' => [fn () => self::get($employee, $organization, $id), 'to_read_ledger'],
            'adjustment' => [fn () => self::post($employee, $organization, 'adjustments', ['storageId' => $cash['safe'], 'lines' => $line]), 'to_manage'],
            'transfer' => [fn () => self::post($employee, $organization, 'transfers', ['fromStorageId' => $cash['safe'], 'toStorageId' => $cash['box'], 'lines' => $line]), 'to_manage'],
            'count' => [fn () => self::post($employee, $organization, 'counts', ['storageId' => $cash['safe'], 'lines' => []]), 'to_manage'],
            'delete' => [fn () => self::delete($employee, $organization, $id), 'to_manage'],
        ];
        foreach ($requests as $name => [$request, $variant]) {
            $response = $request();
            self::assertResponseStatusCodeSame(403, $name);
            self::assertSame('cash_flow.access_forbidden.'.$variant, $response->toArray(false)['code'], $name);
        }

        // A user who is not a member is stopped by the organization header (403) before anything.
        self::balances(UserFactory::createOne(), $organization);
        self::assertResponseStatusCodeSame(403);
        self::assertCount(1, self::history($owner, $organization, '2026-11-01', '2026-11-30')->toArray());
    }

    /** @return array{safe: string, box: string, 50: string, 100: string, 200: string} */
    private static function cash(Organization $organization): array
    {
        $owner = OrganizationMother::ownerOf($organization);
        $now = Clock::get()->now();
        $safe = CashStorage::create(Uuid::v7(), 'Sejf', CashStorageMode::Deposit, null, $owner->getId(), MembershipRole::Owner, $now);
        $box = CashStorage::create(Uuid::v7(), 'Kasetka', CashStorageMode::Counted, null, $owner->getId(), MembershipRole::Owner, $now);

        self::inOrganization($organization, static function () use ($safe, $box): void {
            static::getContainer()->get(CashStorageRepositoryInterface::class)->add($safe);
            static::getContainer()->get(CashStorageRepositoryInterface::class)->add($box);
        });

        return [
            'safe' => $safe->getId()->toRfc4122(),
            'box' => $box->getId()->toRfc4122(),
            50 => self::addDenomination($organization, 5_000)->getId()->toRfc4122(),
            100 => self::addDenomination($organization, 10_000)->getId()->toRfc4122(),
            200 => self::addDenomination($organization, 20_000)->getId()->toRfc4122(),
        ];
    }

    private static function addDenomination(Organization $organization, int $value, string $currency = 'PLN'): Denomination
    {
        $owner = OrganizationMother::ownerOf($organization);
        $denomination = Denomination::create(Uuid::v7(), $currency, $value, DenominationKind::Note, null, $owner->getId(), MembershipRole::Owner, Clock::get()->now());
        self::inOrganization($organization, static fn () => static::getContainer()->get(DenominationRepositoryInterface::class)->add($denomination));

        return $denomination;
    }

    /** @param \Closure(): mixed $write */
    private static function inOrganization(Organization $organization, \Closure $write): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $tenantContext = static::getContainer()->get(TenantContextInterface::class);
        $tenantContext->switchTo($entityManager->find(Organization::class, $organization->getId()) ?? throw new \LogicException('Organization expected.'));
        try {
            $write();
        } finally {
            $tenantContext->reset();
            $entityManager->clear();
        }
    }

    /** @return list<string> */
    private static function violationPaths(ResponseInterface $response): array
    {
        $found = array_values(array_unique(array_column($response->toArray(false)['violations'] ?? [], 'propertyPath')));
        sort($found);

        return $found;
    }

    /** @param array<string, mixed> $body */
    private static function post(User $user, Organization $organization, string $kind, array $body): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('POST', '/api/cash-movements/'.$kind, ['json' => $body]);
    }

    private static function get(User $user, Organization $organization, string $id): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('GET', '/api/cash-movements/'.$id);
    }

    private static function delete(User $user, Organization $organization, string $id): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('DELETE', '/api/cash-movements/'.$id);
    }

    private static function history(User $user, Organization $organization, string $from, string $to, ?string $storageId = null): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('GET', '/api/cash-movements', ['query' => array_filter(['from' => $from, 'to' => $to, 'storageId' => $storageId], static fn (?string $value): bool => null !== $value)]);
    }

    private static function balances(User $user, Organization $organization): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('GET', '/api/cash-balances');
    }
}
