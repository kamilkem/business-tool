<?php

declare(strict_types=1);

namespace App\Tests\Unit\Expenses\Application;

use App\Expenses\Application\Command\AddExpenseCategory\AddExpenseCategoryCommand;
use App\Expenses\Application\Command\AddExpenseCategory\AddExpenseCategoryHandler;
use App\Expenses\Application\Command\ArchiveExpenseCategory\ArchiveExpenseCategoryCommand;
use App\Expenses\Application\Command\ArchiveExpenseCategory\ArchiveExpenseCategoryHandler;
use App\Expenses\Application\Command\RenameExpenseCategory\RenameExpenseCategoryCommand;
use App\Expenses\Application\Command\RenameExpenseCategory\RenameExpenseCategoryHandler;
use App\Expenses\Application\Command\RestoreExpenseCategory\RestoreExpenseCategoryCommand;
use App\Expenses\Application\Command\RestoreExpenseCategory\RestoreExpenseCategoryHandler;
use App\Expenses\Application\ExpensesActor;
use App\Expenses\Application\Query\GetExpenseCategory\ExpenseCategoryView;
use App\Expenses\Application\Query\GetExpenseCategory\GetExpenseCategoryHandler;
use App\Expenses\Application\Query\GetExpenseCategory\GetExpenseCategoryQuery;
use App\Expenses\Application\Query\ListExpenseCategories\ListExpenseCategoriesHandler;
use App\Expenses\Application\Query\ListExpenseCategories\ListExpenseCategoriesQuery;
use App\Expenses\Domain\Exception\ExpenseCategoryNameTakenException;
use App\Expenses\Domain\Exception\ExpenseCategoryNotFoundException;
use App\Expenses\Domain\Exception\ExpensesManagementForbiddenException;
use App\Expenses\Domain\Exception\InvalidExpenseCategoryNameException;
use App\Expenses\Domain\Model\ExpenseCategory;
use App\Organization\Application\Query\GetMember\GetMemberHandler;
use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Shared\Application\Query\QueryInterface;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\Organization\Application\InMemoryOrganizationRepository;
use App\Tests\Unit\Shared\Application\StubQueryBus;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * An organization with an owner, a manager, an employee and the category "Rent"; the Organization
 * module answers through its real query over an in-memory repository.
 */
#[CoversClass(AddExpenseCategoryHandler::class)]
#[CoversClass(RenameExpenseCategoryHandler::class)]
#[CoversClass(ArchiveExpenseCategoryHandler::class)]
#[CoversClass(RestoreExpenseCategoryHandler::class)]
#[CoversClass(GetExpenseCategoryHandler::class)]
#[CoversClass(ListExpenseCategoriesHandler::class)]
#[CoversClass(ExpenseCategoryView::class)]
#[CoversClass(ExpensesActor::class)]
final class ExpenseCategoryHandlersTest extends TestCase
{
    private const string NOW = '2026-11-13 10:00:00';
    private const string JOINED = '2026-01-05 09:00:00';

    private MockClock $clock;
    private InMemoryExpenseCategoryRepository $categories;
    private StubQueryBus $queryBus;
    private Organization $organization;
    private User $owner;
    private User $manager;
    private User $employee;
    private ExpenseCategory $rent;

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::NOW);
        $organizations = new InMemoryOrganizationRepository();
        $this->categories = new InMemoryExpenseCategoryRepository();
        $this->owner = UserMother::any('olivia@example.com');
        $this->manager = UserMother::any('mark@example.com');
        $this->employee = UserMother::any('eve@example.com');
        $joined = new \DateTimeImmutable(self::JOINED);
        $this->organization = OrganizationMother::ownedBy($this->owner, createdAt: $joined);
        OrganizationMother::addMember($this->organization, $this->manager, MembershipRole::Manager, $joined);
        OrganizationMother::addMember($this->organization, $this->employee, MembershipRole::Employee, $joined);
        $organizations->add($this->organization);
        $this->rent = ExpenseCategory::create(Uuid::v7(), 'Rent', null, $this->owner->getId(), MembershipRole::Owner, $joined);
        $this->categories->add($this->rent);

        $this->queryBus = new StubQueryBus(static fn (QueryInterface $query): mixed => match (true) {
            $query instanceof GetMemberQuery => (new GetMemberHandler($organizations))($query),
            default => throw new \LogicException(\sprintf('Unexpected query %s.', $query::class)),
        });
    }

    /** @return iterable<string, array{string}> */
    public static function managers(): iterable
    {
        yield 'owner' => ['owner'];
        yield 'manager' => ['manager'];
    }

    #[DataProvider('managers')]
    public function test_it_adds_a_category_for_an_owner_or_manager(string $actor): void
    {
        $id = Uuid::v7()->toRfc4122();

        $this->add($this->user($actor), $id, '  Food ');

        $category = $this->categories->findById(Uuid::fromString($id));
        self::assertNotNull($category);
        self::assertSame('Food', $category->getName());
        self::assertFalse($category->isArchived());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $category->getCreatedAt());
    }

    public function test_it_refuses_a_name_another_category_has_even_archived(): void
    {
        $this->add($this->owner, Uuid::v7()->toRfc4122(), 'Food');
        $food = $this->categories->findByName('Food') ?? self::fail('Category expected.');
        $this->archive($this->owner, $food->getId()->toRfc4122());

        foreach (['Rent', ' Rent ', 'Food'] as $name) {
            try {
                $this->add($this->owner, Uuid::v7()->toRfc4122(), $name);
                self::fail(\sprintf('"%s" should be taken.', $name));
            } catch (ExpenseCategoryNameTakenException) {
            }
        }

        $this->add($this->owner, Uuid::v7()->toRfc4122(), 'rent');
        self::assertCount(3, $this->categories->findAll(includeArchived: true));
    }

    public function test_it_refuses_a_blank_name(): void
    {
        $this->expectException(InvalidExpenseCategoryNameException::class);

        $this->add($this->owner, Uuid::v7()->toRfc4122(), '   ');
    }

    public function test_it_refuses_adding_to_an_employee(): void
    {
        $this->expectException(ExpensesManagementForbiddenException::class);

        $this->add($this->employee, Uuid::v7()->toRfc4122(), 'Food');
    }

    public function test_it_refuses_changes_to_a_deactivated_member_or_stranger(): void
    {
        $id = $this->rent->getId()->toRfc4122();
        foreach ([$this->deactivatedManager(), UserMother::any('stranger@example.com')] as $user) {
            foreach ([
                fn () => $this->add($user, Uuid::v7()->toRfc4122(), 'Food'),
                fn () => $this->rename($user, $id, 'Lease'),
                fn () => $this->archive($user, $id),
                fn () => $this->restore($user, $id),
            ] as $change) {
                try {
                    $change();
                    self::fail('Not found expected.');
                } catch (OrganizationNotFoundException) {
                }
            }
        }

        self::assertSame(['Rent'], $this->names($this->list($this->owner, includeArchived: true)));
    }

    #[DataProvider('managers')]
    public function test_it_renames_a_category(string $actor): void
    {
        $this->rename($this->user($actor), $this->rent->getId()->toRfc4122(), ' Lease ');

        self::assertSame('Lease', $this->rent->getName());
    }

    public function test_it_renames_a_category_to_its_own_name_and_an_archived_one(): void
    {
        $this->archive($this->owner, $this->rent->getId()->toRfc4122());

        $this->rename($this->owner, $this->rent->getId()->toRfc4122(), 'Rent');
        self::assertSame('Rent', $this->rent->getName());

        $this->rename($this->owner, $this->rent->getId()->toRfc4122(), 'Old rent');
        self::assertSame('Old rent', $this->rent->getName());
    }

    public function test_it_refuses_renaming_to_another_categorys_name(): void
    {
        $this->add($this->owner, Uuid::v7()->toRfc4122(), 'Food');

        $this->expectException(ExpenseCategoryNameTakenException::class);

        $this->rename($this->owner, $this->rent->getId()->toRfc4122(), 'Food');
    }

    public function test_it_refuses_an_employee_before_looking_for_the_category(): void
    {
        $unknown = Uuid::v7()->toRfc4122();
        $refused = 0;
        foreach ([
            fn () => $this->rename($this->employee, $unknown, 'Food'),
            fn () => $this->archive($this->employee, $unknown),
            fn () => $this->restore($this->employee, $unknown),
        ] as $change) {
            try {
                $change();
            } catch (ExpensesManagementForbiddenException) {
                ++$refused;
            }
        }

        self::assertSame(3, $refused);
    }

    /** @return iterable<string, array{string}> */
    public static function unknownIds(): iterable
    {
        yield 'unknown' => [Uuid::v7()->toRfc4122()];
        yield 'not a UUID' => ['rent'];
    }

    #[DataProvider('unknownIds')]
    public function test_it_reports_an_unknown_category_as_not_found(string $id): void
    {
        foreach ([
            fn () => $this->rename($this->owner, $id, 'Food'),
            fn () => $this->archive($this->owner, $id),
            fn () => $this->restore($this->owner, $id),
        ] as $action) {
            try {
                $action();
                self::fail('Not found expected.');
            } catch (ExpenseCategoryNotFoundException) {
            }
        }

        self::assertNull($this->get($this->owner, $id));
    }

    #[DataProvider('managers')]
    public function test_it_archives_a_category_once_and_restores_it(string $actor): void
    {
        $id = $this->rent->getId()->toRfc4122();

        $this->archive($this->user($actor), $id);
        $this->clock->modify('+1 day');
        $this->archive($this->owner, $id);

        self::assertTrue($this->rent->isArchived());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $this->rent->getArchivedAt());

        $this->restore($this->user($actor), $id);

        self::assertFalse($this->rent->isArchived());
        self::assertNull($this->rent->getArchivedAt());
    }

    public function test_it_returns_a_category(): void
    {
        $id = $this->rent->getId()->toRfc4122();
        self::assertEquals(
            new ExpenseCategoryView($id, 'Rent', false, new \DateTimeImmutable(self::JOINED), null),
            $this->get($this->manager, $id),
        );

        $this->archive($this->owner, $id);

        self::assertEquals(
            new ExpenseCategoryView($id, 'Rent', true, new \DateTimeImmutable(self::JOINED), new \DateTimeImmutable(self::NOW)),
            $this->get($this->manager, $id),
        );
    }

    public function test_it_lists_active_categories_by_name_and_the_archived_ones_last_when_asked(): void
    {
        foreach (['Utilities', 'Food', 'Beverages'] as $name) {
            $this->add($this->owner, Uuid::v7()->toRfc4122(), $name);
        }
        $this->archive($this->owner, ($this->categories->findByName('Beverages') ?? self::fail('Category expected.'))->getId()->toRfc4122());

        self::assertSame(['Food', 'Rent', 'Utilities'], $this->names($this->list($this->manager)));
        self::assertSame(['Food', 'Rent', 'Utilities', 'Beverages'], $this->names($this->list($this->manager, includeArchived: true)));
    }

    public function test_it_returns_null_to_a_non_member_or_deactivated_member(): void
    {
        foreach ([$this->deactivatedManager(), UserMother::any('stranger@example.com')] as $user) {
            self::assertNull($this->list($user));
            self::assertNull($this->get($user, $this->rent->getId()->toRfc4122()));
        }
    }

    public function test_it_refuses_reading_categories_to_an_employee(): void
    {
        $refused = 0;
        foreach ([fn () => $this->list($this->employee), fn () => $this->get($this->employee, $this->rent->getId()->toRfc4122())] as $read) {
            try {
                $read();
            } catch (ExpensesManagementForbiddenException) {
                ++$refused;
            }
        }

        self::assertSame(2, $refused);
    }

    private function user(string $actor): User
    {
        return 'owner' === $actor ? $this->owner : $this->manager;
    }

    private function deactivatedManager(): User
    {
        $former = UserMother::any('dora@example.com');
        OrganizationMother::addMember($this->organization, $former, MembershipRole::Manager, new \DateTimeImmutable(self::JOINED));
        OrganizationMother::deactivateMember($this->organization, $former, new \DateTimeImmutable('2026-11-01'));

        return $former;
    }

    private function organizationId(): string
    {
        return $this->organization->getId()->toRfc4122();
    }

    private function add(User $actor, string $id, string $name): void
    {
        (new AddExpenseCategoryHandler($this->categories, $this->queryBus, $this->clock))(
            new AddExpenseCategoryCommand($this->organizationId(), $actor->getId()->toRfc4122(), $id, $name),
        );
    }

    private function rename(User $actor, string $id, string $name): void
    {
        (new RenameExpenseCategoryHandler($this->categories, $this->queryBus))(
            new RenameExpenseCategoryCommand($this->organizationId(), $actor->getId()->toRfc4122(), $id, $name),
        );
    }

    private function archive(User $actor, string $id): void
    {
        (new ArchiveExpenseCategoryHandler($this->categories, $this->queryBus, $this->clock))(
            new ArchiveExpenseCategoryCommand($this->organizationId(), $actor->getId()->toRfc4122(), $id),
        );
    }

    private function restore(User $actor, string $id): void
    {
        (new RestoreExpenseCategoryHandler($this->categories, $this->queryBus))(
            new RestoreExpenseCategoryCommand($this->organizationId(), $actor->getId()->toRfc4122(), $id),
        );
    }

    private function get(User $actor, string $id): ?ExpenseCategoryView
    {
        return (new GetExpenseCategoryHandler($this->categories, $this->queryBus))(
            new GetExpenseCategoryQuery($this->organizationId(), $actor->getId()->toRfc4122(), $id),
        );
    }

    /** @return list<ExpenseCategoryView>|null */
    private function list(User $actor, bool $includeArchived = false): ?array
    {
        return (new ListExpenseCategoriesHandler($this->categories, $this->queryBus))(
            new ListExpenseCategoriesQuery($this->organizationId(), $actor->getId()->toRfc4122(), $includeArchived),
        );
    }

    /**
     * @param list<ExpenseCategoryView>|null $views
     *
     * @return list<string>
     */
    private function names(?array $views): array
    {
        return array_map(static fn (ExpenseCategoryView $view): string => $view->name, $views ?? []);
    }
}
