<?php

declare(strict_types=1);

namespace App\Tests\Api\Expenses;

use App\Expenses\Domain\Model\ExpenseCategory;
use App\Expenses\Domain\Repository\ExpenseCategoryRepositoryInterface;
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
 * GET, POST, PATCH /expense-categories, POST /expense-categories/{id}/archive and /restore, and the
 * default categories of a new organization (#48). Organizations arranged by the factories start
 * without categories; the server's time is mocked at NOW (UTC).
 */
final class ExpenseCategoryApiTest extends ApiTestCase
{
    private const string NOW = '2026-11-13 10:00:00';
    private const array MERGE_PATCH = ['Content-Type' => 'application/merge-patch+json'];

    /** @return iterable<string, array{MembershipRole}> */
    public static function managingRoles(): iterable
    {
        yield 'owner' => [MembershipRole::Owner];
        yield 'manager' => [MembershipRole::Manager];
    }

    #[DataProvider('managingRoles')]
    public function test_it_lets_owners_and_managers_add_a_category(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        self::mockTime(self::NOW);

        $response = self::add($actor, $organization, ['name' => '  Food  ']);

        self::assertResponseStatusCodeSame(201);
        $category = $response->toArray();
        self::assertInstanceOf(UuidV7::class, Uuid::fromString($category['id']));
        self::assertSame([
            'id' => $category['id'],
            'name' => 'Food',
            'archived' => false,
            'createdAt' => '2026-11-13T10:00:00+00:00',
            'archivedAt' => null,
        ], $category);

        self::assertSame($category, self::get($actor, $organization, $category['id'])->toArray());
        self::assertResponseStatusCodeSame(200);
    }

    public function test_it_answers_409_for_a_name_another_category_has_even_archived(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        self::addCategory($organization, 'Rent');
        self::archiveCategory($organization, self::addCategory($organization, 'Food'));
        self::mockTime(self::NOW);

        foreach (['Rent', ' Rent ', 'Food'] as $name) {
            self::add($owner, $organization, ['name' => $name]);
            self::assertResponseStatusCodeSame(409, $name);
        }

        self::add($owner, $organization, ['name' => 'rent']);
        self::assertResponseStatusCodeSame(201);
    }

    public function test_it_allows_the_same_name_in_another_organization(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        self::addCategory($elsewhere, 'Rent');

        self::add($owner, $organization, ['name' => 'Rent']);

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
    public function test_it_answers_422_with_violations_for_an_invalid_new_category(array $body): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        $response = self::add($owner, $organization, $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['name'], array_values(array_unique(array_column($response->toArray(false)['violations'] ?? [], 'propertyPath'))));
        self::assertSame([], self::list($owner, $organization, true)->toArray());
    }

    public function test_it_accepts_a_name_of_64_characters_after_trimming(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        $category = self::add($owner, $organization, ['name' => ' '.str_repeat('ż', 64).' '])->toArray();

        self::assertResponseStatusCodeSame(201);
        self::assertSame(str_repeat('ż', 64), $category['name']);
    }

    public function test_it_lists_active_categories_by_name_and_all_with_include_archived(): void
    {
        [$manager, $organization] = self::memberWithRole(MembershipRole::Manager);
        $utilities = self::addCategory($organization, 'Utilities');
        $food = self::addCategory($organization, 'Food');
        $beverages = self::addCategory($organization, 'Beverages');
        $rent = self::addCategory($organization, 'Rent');
        self::mockTime(self::NOW);
        self::archive($manager, $organization, $beverages->getId()->toRfc4122());

        self::assertSame(
            [[$food->getId()->toRfc4122(), 'Food', false], [$rent->getId()->toRfc4122(), 'Rent', false], [$utilities->getId()->toRfc4122(), 'Utilities', false]],
            array_map(static fn (array $category): array => [$category['id'], $category['name'], $category['archived']], self::list($manager, $organization)->toArray()),
        );
        self::assertSame(self::list($manager, $organization)->toArray(), self::list($manager, $organization, false)->toArray());

        $all = self::list($manager, $organization, true)->toArray();
        self::assertSame(['Food', 'Rent', 'Utilities', 'Beverages'], array_column($all, 'name'));
        self::assertSame([true, '2026-11-13T10:00:00+00:00'], [$all[3]['archived'], $all[3]['archivedAt']]);
    }

    #[DataProvider('managingRoles')]
    public function test_it_lets_owners_and_managers_rename_a_category(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        $id = self::addCategory($organization, 'Rent')->getId()->toRfc4122();

        $renamed = self::rename($actor, $organization, $id, ['name' => ' Lease '])->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Lease', $renamed['name']);
        self::assertSame($renamed, self::get($actor, $organization, $id)->toArray());
    }

    public function test_it_keeps_the_name_when_the_body_does_not_send_it_or_sends_the_same(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $id = self::addCategory($organization, 'Rent')->getId()->toRfc4122();

        self::assertSame('Rent', self::rename($owner, $organization, $id, [])->toArray()['name']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Rent', self::rename($owner, $organization, $id, ['name' => 'Rent'])->toArray()['name']);
        self::assertResponseStatusCodeSame(200);
    }

    public function test_it_answers_409_for_renaming_to_another_categorys_name(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $rent = self::addCategory($organization, 'Rent');
        self::archiveCategory($organization, self::addCategory($organization, 'Food'));

        self::rename($owner, $organization, $rent->getId()->toRfc4122(), ['name' => 'Food']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('Rent', self::get($owner, $organization, $rent->getId()->toRfc4122())->toArray()['name']);
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidRenames')]
    public function test_it_answers_422_with_violations_for_an_invalid_rename(array $body): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $id = self::addCategory($organization, 'Rent')->getId()->toRfc4122();

        $response = self::rename($owner, $organization, $id, $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['name'], array_values(array_unique(array_column($response->toArray(false)['violations'] ?? [], 'propertyPath'))));
        self::assertSame('Rent', self::get($owner, $organization, $id)->toArray()['name']);
    }

    #[DataProvider('managingRoles')]
    public function test_it_lets_owners_and_managers_archive_and_restore_a_category(MembershipRole $role): void
    {
        [$actor, $organization] = self::memberWithRole($role);
        $id = self::addCategory($organization, 'Rent')->getId()->toRfc4122();
        self::mockTime(self::NOW);

        $archived = self::archive($actor, $organization, $id)->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame([true, '2026-11-13T10:00:00+00:00'], [$archived['archived'], $archived['archivedAt']]);
        self::assertSame($archived, self::get($actor, $organization, $id)->toArray());
        self::assertSame([], self::list($actor, $organization)->toArray());

        $restored = self::restore($actor, $organization, $id)->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame([false, null], [$restored['archived'], $restored['archivedAt']]);
        self::assertSame([$restored], self::list($actor, $organization)->toArray());
    }

    public function test_it_keeps_the_first_archiving_and_restores_an_active_category_as_is(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $id = self::addCategory($organization, 'Rent')->getId()->toRfc4122();
        $active = self::get($owner, $organization, $id)->toArray();

        self::assertSame($active, self::restore($owner, $organization, $id)->toArray());
        self::assertResponseStatusCodeSame(200);

        self::mockTime(self::NOW);
        $first = self::archive($owner, $organization, $id)->toArray();
        self::mockTime('2026-11-14 10:00:00');
        self::assertSame($first, self::archive($owner, $organization, $id)->toArray());
        self::assertResponseStatusCodeSame(200);
    }

    public function test_it_renames_an_archived_category(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $category = self::addCategory($organization, 'Rent');
        self::archiveCategory($organization, $category);

        $renamed = self::rename($owner, $organization, $category->getId()->toRfc4122(), ['name' => 'Old rent'])->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Old rent', true], [$renamed['name'], $renamed['archived']]);
    }

    public function test_it_answers_404_for_an_unknown_category(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $unknown = Uuid::v7()->toRfc4122();

        self::get($owner, $organization, $unknown);
        self::assertResponseStatusCodeSame(404);
        self::rename($owner, $organization, $unknown, ['name' => 'Rent']);
        self::assertResponseStatusCodeSame(404);
        self::archive($owner, $organization, $unknown);
        self::assertResponseStatusCodeSame(404);
        self::restore($owner, $organization, $unknown);
        self::assertResponseStatusCodeSame(404);
        static::clientFor($owner, $organization)->request('GET', '/api/expense-categories/not-a-uuid');
        self::assertResponseStatusCodeSame(404);
    }

    public function test_it_hides_another_organizations_categories(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [$ownerElsewhere, $elsewhere] = self::memberWithRole(MembershipRole::Owner);
        self::addCategory($organization, 'Rent');
        $foreign = self::addCategory($elsewhere, 'Food');
        self::archiveCategory($elsewhere, self::addCategory($elsewhere, 'Beverages'));
        $id = $foreign->getId()->toRfc4122();
        // A member elsewhere too, as an employee: the selected organization decides what is visible.
        OrganizationMother::addMember($elsewhere, $owner, MembershipRole::Employee);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $before = self::list($ownerElsewhere, $elsewhere, true)->toArray();

        self::assertSame(['Rent'], array_column(self::list($owner, $organization, true)->toArray(), 'name'));
        self::get($owner, $organization, $id);
        self::assertResponseStatusCodeSame(404);
        self::rename($owner, $organization, $id, ['name' => 'Mine now']);
        self::assertResponseStatusCodeSame(404);
        self::archive($owner, $organization, $id);
        self::assertResponseStatusCodeSame(404);
        self::restore($owner, $organization, $id);
        self::assertResponseStatusCodeSame(404);

        self::assertSame($before, self::list($ownerElsewhere, $elsewhere, true)->toArray());
    }

    /** @return iterable<string, array{\Closure(User, Organization, string): ResponseInterface}> */
    public static function requests(): iterable
    {
        yield 'GET collection' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::list($user, $organization, true)];
        yield 'GET' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::get($user, $organization, $id)];
        yield 'POST' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::add($user, $organization, ['name' => 'Food'])];
        yield 'PATCH' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::rename($user, $organization, $id, ['name' => 'Food'])];
        yield 'archive' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::archive($user, $organization, $id)];
        yield 'restore' => [static fn (User $user, Organization $organization, string $id): ResponseInterface => self::restore($user, $organization, $id)];
    }

    /** @param \Closure(User, Organization, string): ResponseInterface $request */
    #[DataProvider('requests')]
    public function test_it_refuses_categories_to_employees_and_deactivated_members(\Closure $request): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $formerManager = self::addMember($organization, MembershipRole::Manager);
        $formerOwner = self::addMember($organization, MembershipRole::Owner);
        OrganizationMother::deactivateMember($organization, $formerManager);
        OrganizationMother::deactivateMember($organization, $formerOwner);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $category = self::addCategory($organization, 'Rent');
        self::archiveCategory($organization, self::addCategory($organization, 'Beverages'));
        $before = self::list($owner, $organization, true)->toArray();

        foreach ([$employee, $formerManager, $formerOwner] as $user) {
            $request($user, $organization, $category->getId()->toRfc4122());
            self::assertResponseStatusCodeSame(403);
        }

        self::assertSame($before, self::list($owner, $organization, true)->toArray());
    }

    public function test_it_refuses_an_employee_before_looking_at_the_category(): void
    {
        [, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $unknown = Uuid::v7()->toRfc4122();

        self::get($employee, $organization, $unknown);
        self::assertResponseStatusCodeSame(403);
        self::rename($employee, $organization, $unknown, ['name' => 'Food']);
        self::assertResponseStatusCodeSame(403);
        self::archive($employee, $organization, $unknown);
        self::assertResponseStatusCodeSame(403);
        self::restore($employee, $organization, $unknown);
        self::assertResponseStatusCodeSame(403);
    }

    public function test_it_gives_a_new_organization_the_default_categories_of_its_country(): void
    {
        $user = UserFactory::createOne();
        self::mockTime(self::NOW);

        $polish = self::createOrganization($user, null, ['name' => 'Corner Pub']);
        $german = self::createOrganization($user, null, ['name' => 'Eckkneipe', 'countryCode' => 'DE', 'currency' => 'EUR', 'timezone' => 'Europe/Berlin']);

        $categories = self::list($user, $polish)->toArray();
        self::assertSame(
            ['Czynsz', 'Inne', 'Marketing', 'Media', 'Napoje', 'Towary do odsprzedaży', 'Usługi', 'Wyposażenie', 'Żywność'],
            array_column($categories, 'name'),
        );
        self::assertSame([[false, '2026-11-13T10:00:00+00:00']], array_values(array_unique(array_map(
            static fn (array $category): array => [$category['archived'], $category['createdAt']],
            $categories,
        ), \SORT_REGULAR)));
        self::assertSame(
            ['Beverages', 'Equipment', 'Food', 'Goods for resale', 'Marketing', 'Other', 'Rent', 'Services', 'Utilities'],
            array_column(self::list($user, $german)->toArray(), 'name'),
        );
    }

    public function test_it_adds_the_defaults_to_the_new_organization_while_another_is_selected(): void
    {
        [$owner, $selected] = self::memberWithRole(MembershipRole::Owner);
        self::addCategory($selected, 'Rent');

        $created = self::createOrganization($owner, $selected, ['name' => 'Second Bar', 'countryCode' => 'DE', 'currency' => 'EUR', 'timezone' => 'Europe/Berlin']);

        self::assertSame(['Rent'], array_column(self::list($owner, $selected, true)->toArray(), 'name'));
        self::assertCount(9, self::list($owner, $created)->toArray());
    }

    private static function list(User $user, Organization $organization, ?bool $includeArchived = null): ResponseInterface
    {
        $query = null === $includeArchived ? '' : '?includeArchived='.($includeArchived ? 'true' : 'false');

        return static::clientFor($user, $organization)->request('GET', '/api/expense-categories'.$query);
    }

    private static function get(User $user, Organization $organization, string $id): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('GET', '/api/expense-categories/'.$id);
    }

    /** @param array<string, mixed> $body */
    private static function add(User $user, Organization $organization, array $body): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('POST', '/api/expense-categories', ['json' => $body]);
    }

    /** @param array<string, mixed> $body */
    private static function rename(User $user, Organization $organization, string $id, array $body): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('PATCH', '/api/expense-categories/'.$id, [
            'json' => $body,
            'headers' => self::MERGE_PATCH,
        ]);
    }

    private static function archive(User $user, Organization $organization, string $id): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('POST', '/api/expense-categories/'.$id.'/archive');
    }

    private static function restore(User $user, Organization $organization, string $id): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('POST', '/api/expense-categories/'.$id.'/restore');
    }

    /** @param array<string, mixed> $body */
    private static function createOrganization(User $user, ?Organization $selected, array $body): Organization
    {
        $data = static::clientFor($user, $selected)->request('POST', '/api/organizations', ['json' => $body])->toArray();
        self::assertResponseStatusCodeSame(201);

        return static::getContainer()->get(EntityManagerInterface::class)->find(Organization::class, Uuid::fromString($data['id']))
            ?? throw new \LogicException('Organization expected.');
    }

    private static function addCategory(Organization $organization, string $name): ExpenseCategory
    {
        $owner = OrganizationMother::ownerOf($organization);
        $category = ExpenseCategory::create(Uuid::v7(), $name, null, $owner->getId(), MembershipRole::Owner, Clock::get()->now());

        self::inTenant($organization, static fn () => static::getContainer()->get(ExpenseCategoryRepositoryInterface::class)->add($category));

        return $category;
    }

    private static function archiveCategory(Organization $organization, ExpenseCategory $category): void
    {
        $owner = OrganizationMother::ownerOf($organization);

        self::inTenant($organization, static function () use ($category, $owner): void {
            $categories = static::getContainer()->get(ExpenseCategoryRepositoryInterface::class);
            $managed = $categories->findById($category->getId()) ?? throw new \LogicException('Category expected.');
            $managed->archive($owner->getId(), MembershipRole::Owner, Clock::get()->now());
            $categories->save($managed);
        });
    }

    private static function addMember(Organization $organization, MembershipRole $role): User
    {
        $user = UserFactory::createOne();
        OrganizationMother::addMember($organization, $user, $role);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        return $user;
    }

    private static function inTenant(Organization $organization, \Closure $action): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $tenantContext = static::getContainer()->get(TenantContextInterface::class);
        $tenantContext->switchTo($entityManager->find(Organization::class, $organization->getId()) ?? throw new \LogicException('Organization expected.'));
        try {
            $action();
        } finally {
            $tenantContext->reset();
            // A request starts with an empty identity map: an arranged entity must not be found by
            // id there without the TenantFilter (EntityManager::find() reads the map first).
            $entityManager->clear();
        }
    }
}
