<?php

declare(strict_types=1);

namespace App\Tests\Api\Income;

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
 * GET, POST, PATCH /registers and POST /registers/{id}/deactivate (ADR 0022 §2). The server's time
 * is mocked at NOW (UTC), already TODAY in the organizations' time zone, Europe/Warsaw.
 */
final class RegisterApiTest extends IncomeApiTestCase
{
    private const string NOW = '2026-11-12 23:30:00';
    private const string TODAY = '2026-11-13';
    private const array MERGE_PATCH = ['Content-Type' => 'application/merge-patch+json'];

    /** @return iterable<string, array{MembershipRole}> */
    public static function managingRoles(): iterable
    {
        yield 'owner' => [MembershipRole::Owner];
        yield 'manager' => [MembershipRole::Manager];
    }

    #[DataProvider('managingRoles')]
    public function test_it_lets_owners_and_managers_add_a_register(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        self::mockTime(self::NOW);

        $response = self::add($actor, $organization, ['name' => '  Kitchen  ']);

        self::assertResponseStatusCodeSame(201);
        $register = $response->toArray();
        self::assertTrue(Uuid::isValid($register['id']));
        self::assertInstanceOf(UuidV7::class, Uuid::fromString($register['id']));
        self::assertSame([
            'id' => $register['id'],
            'name' => 'Kitchen',
            'active' => true,
            'createdAt' => '2026-11-12T23:30:00+00:00',
            'deactivatedAt' => null,
        ], $register);

        self::assertSame($register, self::get($actor, $organization, $register['id'])->toArray());
        self::assertResponseStatusCodeSame(200);
    }

    public function test_it_answers_409_for_a_name_another_register_has_even_deactivated(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        self::addRegister($organization, 'Bar');
        self::deactivateRegister($organization, self::addRegister($organization, 'Terrace'));
        self::mockTime(self::NOW);

        foreach (['Bar', ' Bar ', 'Terrace'] as $name) {
            self::add($owner, $organization, ['name' => $name]);
            self::assertResponseStatusCodeSame(409, $name);
        }

        self::add($owner, $organization, ['name' => 'bar']);
        self::assertResponseStatusCodeSame(201);
    }

    public function test_it_allows_the_same_name_in_another_organization(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        self::addRegister($elsewhere, 'Bar');
        self::mockTime(self::NOW);

        self::add($owner, $organization, ['name' => 'Bar']);

        self::assertResponseStatusCodeSame(201);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidNames(): iterable
    {
        yield 'no name' => [[]];
        yield from self::invalidRenames();
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidRenames(): iterable
    {
        yield 'null' => [['name' => null]];
        yield 'empty' => [['name' => '']];
        yield 'blank' => [['name' => '   ']];
        yield 'too long' => [['name' => str_repeat('a', 65)]];
        yield 'a number' => [['name' => 123]];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidNames')]
    public function test_it_answers_422_with_violations_for_an_invalid_new_register(array $body): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        self::mockTime(self::NOW);

        $response = self::add($owner, $organization, $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['name'], array_values(array_unique(array_column($response->toArray(false)['violations'] ?? [], 'propertyPath'))));
        self::assertSame([], self::list($owner, $organization, true)->toArray());
    }

    public function test_it_accepts_a_name_of_64_characters_after_trimming(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        self::mockTime(self::NOW);

        $register = self::add($owner, $organization, ['name' => ' '.str_repeat('ł', 64).' '])->toArray();

        self::assertResponseStatusCodeSame(201);
        self::assertSame(str_repeat('ł', 64), $register['name']);
    }

    public function test_it_lists_active_registers_by_name_and_all_with_include_deactivated(): void
    {
        [$manager, $organization] = self::memberWithRole(MembershipRole::Manager);
        $terrace = self::addRegister($organization, 'Terrace');
        $kitchen = self::addRegister($organization, 'Kitchen');
        $garden = self::addRegister($organization, 'Garden');
        $bar = self::addRegister($organization, 'Bar');
        self::mockTime(self::NOW);
        self::deactivate($manager, $organization, $garden->getId()->toRfc4122());

        self::assertSame(
            [[$bar->getId()->toRfc4122(), 'Bar', true], [$kitchen->getId()->toRfc4122(), 'Kitchen', true], [$terrace->getId()->toRfc4122(), 'Terrace', true]],
            self::summary(self::list($manager, $organization)->toArray()),
        );
        self::assertSame(self::list($manager, $organization)->toArray(), self::list($manager, $organization, false)->toArray());

        $all = self::list($manager, $organization, true)->toArray();
        self::assertSame(['Bar', 'Kitchen', 'Terrace', 'Garden'], array_column($all, 'name'));
        self::assertSame([false, '2026-11-12T23:30:00+00:00'], [$all[3]['active'], $all[3]['deactivatedAt']]);
    }

    public function test_it_lists_no_registers_for_an_organization_without_any(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        self::assertSame([], self::list($owner, $organization)->toArray());
        self::assertResponseStatusCodeSame(200);
    }

    #[DataProvider('managingRoles')]
    public function test_it_lets_owners_and_managers_rename_a_register(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        $register = self::addRegister($organization, 'Bar');
        $id = $register->getId()->toRfc4122();

        $renamed = self::rename($actor, $organization, $id, ['name' => ' Main bar '])->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Main bar', $renamed['name']);
        self::assertSame($renamed, self::get($actor, $organization, $id)->toArray());
    }

    public function test_it_keeps_the_name_when_the_body_does_not_send_it_or_sends_the_same(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $id = self::addRegister($organization, 'Bar')->getId()->toRfc4122();

        self::assertSame('Bar', self::rename($owner, $organization, $id, [])->toArray()['name']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Bar', self::rename($owner, $organization, $id, ['name' => 'Bar'])->toArray()['name']);
        self::assertResponseStatusCodeSame(200);
    }

    public function test_it_answers_409_for_renaming_to_another_registers_name(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $bar = self::addRegister($organization, 'Bar');
        self::deactivateRegister($organization, self::addRegister($organization, 'Terrace'));

        self::rename($owner, $organization, $bar->getId()->toRfc4122(), ['name' => 'Terrace']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('Bar', self::get($owner, $organization, $bar->getId()->toRfc4122())->toArray()['name']);
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidRenames')]
    public function test_it_answers_422_with_violations_for_an_invalid_rename(array $body): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $id = self::addRegister($organization, 'Bar')->getId()->toRfc4122();

        $response = self::rename($owner, $organization, $id, $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['name'], array_values(array_unique(array_column($response->toArray(false)['violations'] ?? [], 'propertyPath'))));
        self::assertSame('Bar', self::get($owner, $organization, $id)->toArray()['name']);
    }

    public function test_it_renames_a_deactivated_register_and_its_entries_show_the_new_name(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $register = self::addRegister($organization, 'Bar');
        $id = $register->getId()->toRfc4122();
        self::mockTime(self::NOW);
        $entry = self::recordEntry($owner, $organization, $id, '2026-11-12')->toArray();
        self::deactivate($owner, $organization, $id);

        $renamed = self::rename($owner, $organization, $id, ['name' => 'Old bar'])->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Old bar', false], [$renamed['name'], $renamed['active']]);
        self::assertSame('Old bar', self::getEntry($owner, $organization, $entry['id'])->toArray()['registerName']);
    }

    #[DataProvider('managingRoles')]
    public function test_it_lets_owners_and_managers_deactivate_a_register(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        $id = self::addRegister($organization, 'Bar')->getId()->toRfc4122();
        self::mockTime(self::NOW);

        $response = self::deactivate($actor, $organization, $id);

        self::assertResponseStatusCodeSame(200);
        $register = $response->toArray();
        self::assertSame([false, '2026-11-12T23:30:00+00:00'], [$register['active'], $register['deactivatedAt']]);
        self::assertSame($register, self::get($actor, $organization, $id)->toArray());
    }

    public function test_it_keeps_the_first_deactivation(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $id = self::addRegister($organization, 'Bar')->getId()->toRfc4122();
        self::mockTime(self::NOW);
        $first = self::deactivate($owner, $organization, $id)->toArray();

        self::mockTime('2026-11-14 10:00:00');
        $again = self::deactivate($owner, $organization, $id)->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame($first, $again);
    }

    public function test_it_keeps_the_entries_of_a_deactivated_register_and_takes_no_new_ones(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $id = self::addRegister($organization, 'Bar')->getId()->toRfc4122();
        self::mockTime(self::NOW);
        $entry = self::recordEntry($owner, $organization, $id, '2026-11-12')->toArray();

        self::deactivate($owner, $organization, $id);

        self::assertSame($entry, self::getEntry($owner, $organization, $entry['id'])->toArray());
        static::clientFor($owner, $organization)->request('PATCH', '/api/revenue-entries/'.$entry['id'], ['json' => ['note' => 'Still editable'], 'headers' => self::MERGE_PATCH]);
        self::assertResponseStatusCodeSame(200);
        self::recordEntry($owner, $organization, $id, self::TODAY);
        self::assertResponseStatusCodeSame(422);
    }

    public function test_it_answers_404_for_an_unknown_register(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $unknown = Uuid::v7()->toRfc4122();

        self::get($owner, $organization, $unknown);
        self::assertResponseStatusCodeSame(404);
        self::rename($owner, $organization, $unknown, ['name' => 'Bar']);
        self::assertResponseStatusCodeSame(404);
        self::deactivate($owner, $organization, $unknown);
        self::assertResponseStatusCodeSame(404);
        static::clientFor($owner, $organization)->request('GET', '/api/registers/not-a-uuid');
        self::assertResponseStatusCodeSame(404);
    }

    public function test_it_hides_another_organizations_registers(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [$ownerElsewhere, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        self::addRegister($organization, 'Bar');
        $id = self::addRegister($elsewhere, 'Kitchen')->getId()->toRfc4122();
        OrganizationMother::addMember($elsewhere, $owner, MembershipRole::Employee);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $before = self::get($ownerElsewhere, $elsewhere, $id)->toArray();

        self::assertSame(['Bar'], array_column(self::list($owner, $organization, true)->toArray(), 'name'));
        self::get($owner, $organization, $id);
        self::assertResponseStatusCodeSame(404);
        self::rename($owner, $organization, $id, ['name' => 'Mine now']);
        self::assertResponseStatusCodeSame(404);
        self::deactivate($owner, $organization, $id);
        self::assertResponseStatusCodeSame(404);

        self::assertSame($before, self::get($ownerElsewhere, $elsewhere, $id)->toArray());
    }

    /** @return iterable<string, array{\Closure(User, Organization, string): ResponseInterface}> */
    public static function requests(): iterable
    {
        yield 'GET collection' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::list($user, $organization, true)];
        yield 'GET' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::get($user, $organization, $id)];
        yield 'POST' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::add($user, $organization, ['name' => 'Kitchen'])];
        yield 'PATCH' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::rename($user, $organization, $id, ['name' => 'Kitchen'])];
        yield 'deactivate' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::deactivate($user, $organization, $id)];
    }

    /** @param \Closure(User, Organization, string): ResponseInterface $request */
    #[DataProvider('requests')]
    public function test_it_refuses_registers_to_employees_and_deactivated_members(\Closure $request): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $formerManager = self::addMember($organization, MembershipRole::Manager);
        $formerOwner = self::addMember($organization, MembershipRole::Owner);
        OrganizationMother::deactivateMember($organization, $formerManager);
        OrganizationMother::deactivateMember($organization, $formerOwner);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $id = self::addRegister($organization, 'Bar')->getId()->toRfc4122();
        self::mockTime(self::NOW);
        $before = self::list($owner, $organization, true)->toArray();

        foreach ([$employee, $formerManager, $formerOwner] as $user) {
            $request($user, $organization, $id);
            self::assertResponseStatusCodeSame(403);
        }

        self::assertSame($before, self::list($owner, $organization, true)->toArray());
    }

    public function test_it_refuses_an_employee_before_looking_at_the_register(): void
    {
        [, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $unknown = Uuid::v7()->toRfc4122();

        self::get($employee, $organization, $unknown);
        self::assertResponseStatusCodeSame(403);
        self::rename($employee, $organization, $unknown, ['name' => 'Kitchen']);
        self::assertResponseStatusCodeSame(403);
        self::deactivate($employee, $organization, $unknown);
        self::assertResponseStatusCodeSame(403);
    }

    private static function list(User $user, Organization $organization, ?bool $includeDeactivated = null): ResponseInterface
    {
        $query = null === $includeDeactivated ? '' : '?includeDeactivated='.($includeDeactivated ? 'true' : 'false');

        return static::clientFor($user, $organization)->request('GET', '/api/registers'.$query);
    }

    private static function get(User $user, Organization $organization, string $id): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('GET', '/api/registers/'.$id);
    }

    /** @param array<string, mixed> $body */
    private static function add(User $user, Organization $organization, array $body): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('POST', '/api/registers', ['json' => $body]);
    }

    /** @param array<string, mixed> $body */
    private static function rename(User $user, Organization $organization, string $id, array $body): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('PATCH', '/api/registers/'.$id, [
            'json' => $body,
            'headers' => self::MERGE_PATCH,
        ]);
    }

    private static function deactivate(User $user, Organization $organization, string $id): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('POST', '/api/registers/'.$id.'/deactivate');
    }

    private static function recordEntry(User $user, Organization $organization, string $registerId, string $businessDate): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('POST', '/api/revenue-entries', ['json' => [
            'registerId' => $registerId,
            'businessDate' => $businessDate,
            'payments' => [['method' => 'cash', 'amount' => 10_000]],
        ]]);
    }

    private static function getEntry(User $user, Organization $organization, string $id): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('GET', '/api/revenue-entries/'.$id);
    }

    /**
     * @param array<array<string, mixed>> $registers
     *
     * @return list<array{mixed, mixed, mixed}>
     */
    private static function summary(array $registers): array
    {
        return array_values(array_map(static fn (array $register): array => [$register['id'], $register['name'], $register['active']], $registers));
    }
}
