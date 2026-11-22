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
 * GET, POST, PATCH, DELETE /cash-storages and POST /cash-storages/{id}/deactivate and /reactivate
 * (ADR 0033 §3). The server's time is mocked at NOW (UTC).
 */
final class CashStorageApiTest extends ApiTestCase
{
    private const string NOW = '2026-11-22 10:00:00';

    /** @return iterable<string, array{MembershipRole}> */
    public static function managingRoles(): iterable
    {
        yield 'owner' => [MembershipRole::Owner];
        yield 'manager' => [MembershipRole::Manager];
    }

    #[DataProvider('managingRoles')]
    public function test_it_lets_owners_and_managers_add_a_storage(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        self::addStorage($organization, 'Sejf', CashStorageMode::Deposit);
        self::mockTime(self::NOW);

        $response = self::add($actor, $organization, ['name' => '  Kasetka ', 'mode' => 'counted']);

        self::assertResponseStatusCodeSame(201);
        $storage = $response->toArray();
        self::assertInstanceOf(UuidV7::class, Uuid::fromString($storage['id']));
        self::assertSame([
            'id' => $storage['id'],
            'name' => 'Kasetka',
            'mode' => 'counted',
            'active' => true,
            'used' => false,
            'createdAt' => '2026-11-22T10:00:00+00:00',
            'deactivatedAt' => null,
        ], $storage);
        self::assertSame($storage, self::get($actor, $organization, $storage['id'])->toArray());
        self::assertSame([['Kasetka', 'counted'], ['Sejf', 'deposit']], self::namesAndModes(self::list($actor, $organization)));
    }

    public function test_it_answers_409_for_a_name_the_organization_has_even_deactivated(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $id = self::addStorage($organization, 'Sejf')->getId()->toRfc4122();
        self::deactivate($owner, $organization, $id);

        $response = self::add($owner, $organization, ['name' => 'Sejf ', 'mode' => 'counted']);

        self::assertResponseStatusCodeSame(409);
        $problem = $response->toArray(false);
        self::assertSame('cash_flow.storage_name_taken', $problem['code']);
        self::assertSame(['name' => 'Sejf'], $problem['parameters']);
    }

    public function test_it_allows_the_same_name_in_another_organization_and_another_case(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        self::addStorage($elsewhere, 'Sejf');
        self::addStorage($organization, 'Safe');

        self::add($owner, $organization, ['name' => 'Sejf', 'mode' => 'deposit']);
        self::assertResponseStatusCodeSame(201);
        self::add($owner, $organization, ['name' => 'safe', 'mode' => 'deposit']);
        self::assertResponseStatusCodeSame(201);
    }

    /** @return iterable<string, array{array<string, mixed>, list<string>}> */
    public static function invalidBodies(): iterable
    {
        yield 'empty' => [[], ['mode', 'name']];
        yield 'a blank name' => [['name' => '   ', 'mode' => 'counted'], ['name']];
        yield 'a name too long' => [['name' => str_repeat('a', CashStorage::NAME_MAX_LENGTH + 1), 'mode' => 'counted'], ['name']];
        yield 'a number as name' => [['name' => 5, 'mode' => 'counted'], ['name']];
        yield 'an unknown mode' => [['name' => 'Kasetka', 'mode' => 'audit'], ['mode']];
        yield 'a blank mode' => [['name' => 'Kasetka', 'mode' => ''], ['mode']];
        yield 'a null mode' => [['name' => 'Kasetka', 'mode' => null], ['mode']];
    }

    /**
     * @param array<string, mixed> $body
     * @param list<string>         $paths
     */
    #[DataProvider('invalidBodies')]
    public function test_it_answers_422_with_violations_for_an_invalid_storage(array $body, array $paths): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        $response = self::add($owner, $organization, $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($paths, self::violationPaths($response));
        self::assertSame([], self::list($owner, $organization, true)->toArray());
    }

    public function test_it_translates_violations_into_polish(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        $response = static::clientFor($owner, $organization)->request('POST', '/api/cash-storages', [
            'json' => ['name' => '', 'mode' => 'counted'],
            'headers' => ['Accept-Language' => 'pl'],
        ]);

        self::assertResponseStatusCodeSame(422);
        $message = $response->toArray(false)['violations'][0]['message'] ?? '';
        self::assertIsString($message);
        self::assertStringContainsString('nie powinna być pusta', $message);
    }

    #[DataProvider('managingRoles')]
    public function test_it_lets_owners_and_managers_rename_a_storage_and_change_its_mode(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        $id = self::addStorage($organization, 'Kasetka', CashStorageMode::Counted)->getId()->toRfc4122();

        $renamed = self::patch($actor, $organization, $id, ['name' => ' Kasetka bar ']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Kasetka bar', 'counted'], [$renamed->toArray()['name'], $renamed->toArray()['mode']]);

        $changed = self::patch($actor, $organization, $id, ['mode' => 'deposit']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Kasetka bar', 'deposit'], [$changed->toArray()['name'], $changed->toArray()['mode']]);

        self::patch($actor, $organization, $id, ['name' => 'Kasetka', 'mode' => 'counted']);
        self::assertSame([['Kasetka', 'counted']], self::namesAndModes(self::list($actor, $organization)));
    }

    public function test_it_lets_a_deactivated_storage_be_changed_and_keeps_it_deactivated(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $id = self::addStorage($organization, 'Sejf')->getId()->toRfc4122();
        self::deactivate($owner, $organization, $id);

        $response = self::patch($owner, $organization, $id, ['name' => 'Stary sejf', 'mode' => 'counted']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Stary sejf', 'counted', false], [$response->toArray()['name'], $response->toArray()['mode'], $response->toArray()['active']]);
    }

    public function test_it_answers_409_for_a_rename_to_another_storages_name_and_changes_nothing(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $id = self::addStorage($organization, 'Kasetka', CashStorageMode::Counted)->getId()->toRfc4122();
        self::addStorage($organization, 'Sejf');

        $response = self::patch($owner, $organization, $id, ['name' => 'Sejf', 'mode' => 'deposit']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('cash_flow.storage_name_taken', $response->toArray(false)['code']);
        self::assertSame(['Kasetka', 'counted'], [self::get($owner, $organization, $id)->toArray()['name'], self::get($owner, $organization, $id)->toArray()['mode']]);
    }

    /** @return iterable<string, array{array<string, mixed>, list<string>}> */
    public static function invalidPatches(): iterable
    {
        yield 'a blank name' => [['name' => ' '], ['name']];
        yield 'a null name' => [['name' => null], ['name']];
        yield 'a name too long' => [['name' => str_repeat('a', CashStorage::NAME_MAX_LENGTH + 1)], ['name']];
        yield 'an unknown mode' => [['mode' => 'audit'], ['mode']];
        yield 'a null mode' => [['mode' => null], ['mode']];
    }

    /**
     * @param array<string, mixed> $body
     * @param list<string>         $paths
     */
    #[DataProvider('invalidPatches')]
    public function test_it_answers_422_for_an_invalid_change(array $body, array $paths): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $id = self::addStorage($organization, 'Sejf')->getId()->toRfc4122();

        $response = self::patch($owner, $organization, $id, $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($paths, self::violationPaths($response));
        self::assertSame([['Sejf', 'deposit']], self::namesAndModes(self::list($owner, $organization)));
    }

    #[DataProvider('managingRoles')]
    public function test_it_lets_owners_and_managers_deactivate_and_reactivate_a_storage(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        $id = self::addStorage($organization, 'Sejf')->getId()->toRfc4122();
        self::addStorage($organization, 'Kasetka', CashStorageMode::Counted);
        self::mockTime(self::NOW);

        $deactivated = self::deactivate($actor, $organization, $id);

        self::assertResponseStatusCodeSame(200);
        self::assertFalse($deactivated->toArray()['active']);
        self::assertSame('2026-11-22T10:00:00+00:00', $deactivated->toArray()['deactivatedAt']);
        self::assertSame(['Kasetka'], array_column(self::list($actor, $organization)->toArray(), 'name'));
        self::assertSame(['Kasetka', 'Sejf'], array_column(self::list($actor, $organization, true)->toArray(), 'name'));

        $reactivated = self::reactivate($actor, $organization, $id);

        self::assertResponseStatusCodeSame(200);
        self::assertTrue($reactivated->toArray()['active']);
        self::assertNull($reactivated->toArray()['deactivatedAt']);
        self::assertSame(['Kasetka', 'Sejf'], array_column(self::list($actor, $organization)->toArray(), 'name'));
    }

    public function test_it_lists_active_storages_first_then_by_name(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        self::addStorage($organization, 'Sejf');
        $archive = self::addStorage($organization, 'Archiwum')->getId()->toRfc4122();
        self::addStorage($organization, 'Kasetka', CashStorageMode::Counted);
        self::deactivate($owner, $organization, $archive);

        self::assertSame(['Kasetka', 'Sejf', 'Archiwum'], array_column(self::list($owner, $organization, true)->toArray(), 'name'));
    }

    #[DataProvider('managingRoles')]
    public function test_it_lets_owners_and_managers_delete_an_unused_storage(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        $id = self::addStorage($organization, 'Sejf')->getId()->toRfc4122();

        self::delete($actor, $organization, $id);

        self::assertResponseStatusCodeSame(204);
        self::get($actor, $organization, $id);
        self::assertResponseStatusCodeSame(404);
        // The name is free again.
        self::add($actor, $organization, ['name' => 'Sejf', 'mode' => 'deposit']);
        self::assertResponseStatusCodeSame(201);
    }

    public function test_it_answers_409_for_deleting_a_storage_a_cash_record_refers_to(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $id = self::addStorage($organization, 'Sejf')->getId()->toRfc4122();
        $denominationId = self::addDenomination($organization, 10_000)->getId()->toRfc4122();
        static::clientFor($owner, $organization)->request('POST', '/api/cash-movements/adjustments', ['json' => ['storageId' => $id, 'lines' => [['denominationId' => $denominationId, 'pieces' => 1]]]]);
        self::assertResponseStatusCodeSame(201);
        self::assertTrue(self::get($owner, $organization, $id)->toArray()['used']);

        $response = self::delete($owner, $organization, $id);

        self::assertResponseStatusCodeSame(409);
        $problem = $response->toArray(false);
        self::assertSame('cash_flow.storage_in_use', $problem['code']);
        self::assertSame(['storageId' => $id, 'name' => 'Sejf'], $problem['parameters']);
        self::assertSame('Sejf', self::get($owner, $organization, $id)->toArray()['name']);
    }

    public function test_it_lets_employees_read_the_storages_for_the_close(): void
    {
        [, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $id = self::addStorage($organization, 'Sejf')->getId()->toRfc4122();

        self::assertSame([['Sejf', 'deposit']], self::namesAndModes(self::list($employee, $organization)));
        self::assertResponseStatusCodeSame(200);
        self::assertSame('deposit', self::get($employee, $organization, $id)->toArray()['mode']);
        // No balance in the response: the employee sees only what the close needs.
        self::assertSame(['id', 'name', 'mode', 'active', 'used', 'createdAt', 'deactivatedAt'], array_keys(self::get($employee, $organization, $id)->toArray()));
    }

    public function test_it_answers_404_for_an_unknown_storage(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $id = Uuid::v7()->toRfc4122();

        foreach (self::requestsById() as $case => [$request]) {
            $response = $request($owner, $organization, $id);
            self::assertResponseStatusCodeSame(404, $case);
            self::assertSame('cash_flow.storage_not_found', $response->toArray(false)['code'], $case);
        }
    }

    public function test_it_hides_another_organizations_storages(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [$ownerElsewhere, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        self::addStorage($organization, 'Kasetka', CashStorageMode::Counted);
        $id = self::addStorage($elsewhere, 'Sejf')->getId()->toRfc4122();
        // A member of both: what counts is the organization selected by the header.
        OrganizationMother::addMember($elsewhere, $owner, MembershipRole::Employee);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $before = self::get($ownerElsewhere, $elsewhere, $id)->toArray();

        self::assertSame(['Kasetka'], array_column(self::list($owner, $organization, true)->toArray(), 'name'));
        foreach (self::requestsById() as $case => [$request]) {
            $request($owner, $organization, $id);
            self::assertResponseStatusCodeSame(404, $case);
        }
        // Its name is not taken in this organization.
        self::add($owner, $organization, ['name' => 'Sejf', 'mode' => 'deposit']);
        self::assertResponseStatusCodeSame(201);

        self::assertSame($before, self::get($ownerElsewhere, $elsewhere, $id)->toArray());
    }

    public function test_it_gives_a_new_organization_the_default_storages_of_its_country(): void
    {
        $user = UserFactory::createOne();
        self::mockTime(self::NOW);

        $polish = self::createOrganization($user, null, ['name' => 'Corner Pub']);
        $german = self::createOrganization($user, null, ['name' => 'Eckkneipe', 'countryCode' => 'DE', 'currency' => 'EUR', 'timezone' => 'Europe/Berlin']);

        $storages = self::list($user, $polish)->toArray();
        self::assertSame([['Kasetka', 'counted'], ['Sejf', 'deposit']], array_map(static fn (array $s): array => [$s['name'], $s['mode']], $storages));
        self::assertSame(['2026-11-22T10:00:00+00:00'], array_values(array_unique(array_column($storages, 'createdAt'))));
        self::assertSame([['Cash box', 'counted'], ['Safe', 'deposit']], self::namesAndModes(self::list($user, $german)));
    }

    public function test_it_adds_the_defaults_to_the_new_organization_while_another_is_selected(): void
    {
        [$owner, $selected] = self::memberWithRole(MembershipRole::Owner);
        self::addStorage($selected, 'Skarbiec');

        $created = self::createOrganization($owner, $selected, ['name' => 'Second Bar', 'countryCode' => 'DE', 'currency' => 'EUR', 'timezone' => 'Europe/Berlin']);

        self::assertSame(['Skarbiec'], array_column(self::list($owner, $selected, true)->toArray(), 'name'));
        self::assertSame(['Cash box', 'Safe'], array_column(self::list($owner, $created)->toArray(), 'name'));
    }

    /** @return iterable<string, array{\Closure(User, Organization, string): ResponseInterface}> */
    public static function requestsById(): iterable
    {
        yield 'GET' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::get($user, $organization, $id)];
        yield 'PATCH' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::patch($user, $organization, $id, ['name' => 'Skarbiec'])];
        yield 'deactivate' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::deactivate($user, $organization, $id)];
        yield 'reactivate' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::reactivate($user, $organization, $id)];
        yield 'DELETE' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::delete($user, $organization, $id)];
    }

    /** @return iterable<string, array{\Closure(User, Organization, string): ResponseInterface}> */
    public static function writes(): iterable
    {
        yield 'POST' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::add($user, $organization, ['name' => 'Skarbiec', 'mode' => 'deposit'])];
        yield 'PATCH' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::patch($user, $organization, $id, ['name' => 'Skarbiec'])];
        yield 'PATCH with an invalid body' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::patch($user, $organization, $id, ['mode' => 'audit'])];
        yield 'deactivate' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::deactivate($user, $organization, $id)];
        yield 'reactivate' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::reactivate($user, $organization, $id)];
        yield 'DELETE' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::delete($user, $organization, $id)];
    }

    /** @param \Closure(User, Organization, string): ResponseInterface $request */
    #[DataProvider('writes')]
    public function test_it_refuses_changes_to_employees_with_a_code(\Closure $request): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $id = self::addStorage($organization, 'Sejf')->getId()->toRfc4122();
        $before = self::list($owner, $organization, true)->toArray();

        $response = $request($employee, $organization, $id);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('cash_flow.access_forbidden.to_manage', $response->toArray(false)['code']);
        // Before looking at the storage: an unknown one is a 403 too.
        $request($employee, $organization, Uuid::v7()->toRfc4122());
        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, self::list($owner, $organization, true)->toArray());
    }

    /** @return iterable<string, array{\Closure(User, Organization, string): ResponseInterface}> */
    public static function requests(): iterable
    {
        yield 'GET collection' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::list($user, $organization, true)];
        yield from self::writes();
        yield 'GET' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::get($user, $organization, $id)];
    }

    /** @param \Closure(User, Organization, string): ResponseInterface $request */
    #[DataProvider('requests')]
    public function test_it_refuses_deactivated_members_and_outsiders(\Closure $request): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $formerManager = self::addMember($organization, MembershipRole::Manager);
        OrganizationMother::deactivateMember($organization, $formerManager);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $outsider = UserFactory::createOne();
        $id = self::addStorage($organization, 'Sejf')->getId()->toRfc4122();
        $before = self::list($owner, $organization, true)->toArray();

        foreach ([$formerManager, $outsider] as $user) {
            $request($user, $organization, $id);
            self::assertResponseStatusCodeSame(403);
        }

        self::assertSame($before, self::list($owner, $organization, true)->toArray());
    }

    private static function addStorage(Organization $organization, string $name, CashStorageMode $mode = CashStorageMode::Deposit): CashStorage
    {
        $owner = OrganizationMother::ownerOf($organization);
        $storage = CashStorage::create(Uuid::v7(), $name, $mode, null, $owner->getId(), MembershipRole::Owner, Clock::get()->now());

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $tenantContext = static::getContainer()->get(TenantContextInterface::class);
        $tenantContext->switchTo($entityManager->find(Organization::class, $organization->getId()) ?? throw new \LogicException('Organization expected.'));
        try {
            static::getContainer()->get(CashStorageRepositoryInterface::class)->add($storage);
        } finally {
            $tenantContext->reset();
            $entityManager->clear();
        }

        return $storage;
    }

    private static function addDenomination(Organization $organization, int $value): Denomination
    {
        $owner = OrganizationMother::ownerOf($organization);
        $denomination = Denomination::create(Uuid::v7(), 'PLN', $value, DenominationKind::Note, null, $owner->getId(), MembershipRole::Owner, Clock::get()->now());

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $tenantContext = static::getContainer()->get(TenantContextInterface::class);
        $tenantContext->switchTo($entityManager->find(Organization::class, $organization->getId()) ?? throw new \LogicException('Organization expected.'));
        try {
            static::getContainer()->get(DenominationRepositoryInterface::class)->add($denomination);
        } finally {
            $tenantContext->reset();
            $entityManager->clear();
        }

        return $denomination;
    }

    /** @param array<string, mixed> $body */
    private static function createOrganization(User $user, ?Organization $selected, array $body): Organization
    {
        $data = static::clientFor($user, $selected)->request('POST', '/api/organizations', ['json' => $body])->toArray();
        self::assertResponseStatusCodeSame(201);

        return static::getContainer()->get(EntityManagerInterface::class)->find(Organization::class, Uuid::fromString($data['id']))
            ?? throw new \LogicException('Organization expected.');
    }

    private static function addMember(Organization $organization, MembershipRole $role): User
    {
        $user = UserFactory::createOne();
        OrganizationMother::addMember($organization, $user, $role);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        return $user;
    }

    /** @return list<array{mixed, mixed}> */
    private static function namesAndModes(ResponseInterface $response): array
    {
        return array_values(array_map(static fn (array $s): array => [$s['name'], $s['mode']], $response->toArray()));
    }

    /** @return list<string> */
    private static function violationPaths(ResponseInterface $response): array
    {
        $found = array_values(array_unique(array_column($response->toArray(false)['violations'] ?? [], 'propertyPath')));
        sort($found);

        return $found;
    }

    private static function list(User $user, Organization $organization, bool $includeDeactivated = false): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('GET', '/api/cash-storages'.($includeDeactivated ? '?includeDeactivated=true' : ''));
    }

    private static function get(User $user, Organization $organization, string $id): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('GET', '/api/cash-storages/'.$id);
    }

    /** @param array<string, mixed> $body */
    private static function add(User $user, Organization $organization, array $body): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('POST', '/api/cash-storages', ['json' => $body]);
    }

    /** @param array<string, mixed> $body */
    private static function patch(User $user, Organization $organization, string $id, array $body): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('PATCH', '/api/cash-storages/'.$id, [
            'json' => $body,
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
    }

    private static function deactivate(User $user, Organization $organization, string $id): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('POST', '/api/cash-storages/'.$id.'/deactivate');
    }

    private static function reactivate(User $user, Organization $organization, string $id): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('POST', '/api/cash-storages/'.$id.'/reactivate');
    }

    private static function delete(User $user, Organization $organization, string $id): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('DELETE', '/api/cash-storages/'.$id);
    }
}
