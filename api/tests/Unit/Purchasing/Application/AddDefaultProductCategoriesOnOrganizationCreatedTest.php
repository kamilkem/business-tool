<?php

declare(strict_types=1);

namespace App\Tests\Unit\Purchasing\Application;

use App\Organization\Application\Query\GetMember\GetMemberHandler;
use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Domain\Event\OrganizationCreated;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Purchasing\Application\Command\AddProductCategory\AddProductCategoryCommand;
use App\Purchasing\Application\Command\AddProductCategory\AddProductCategoryHandler;
use App\Purchasing\Application\EventHandler\AddDefaultProductCategoriesOnOrganizationCreated;
use App\Purchasing\Application\Query\ListProductCategories\ListProductCategoriesHandler;
use App\Purchasing\Application\Query\ListProductCategories\ListProductCategoriesQuery;
use App\Purchasing\Domain\Model\ProductCategory;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Command\CommandInterface;
use App\Shared\Application\Query\QueryInterface;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\Organization\Application\InMemoryOrganizationRepository;
use App\Tests\Unit\Organization\Application\InMemoryTenantContext;
use App\Tests\Unit\Shared\Application\StubQueryBus;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/** The command bus runs the real AddProductCategoryHandler and notes which organization was selected for each command. */
#[CoversClass(AddDefaultProductCategoriesOnOrganizationCreated::class)]
final class AddDefaultProductCategoriesOnOrganizationCreatedTest extends TestCase
{
    private const string NOW = '2026-11-13 10:00:00';

    private InMemoryOrganizationRepository $organizations;
    private InMemoryProductCategoryRepository $categories;
    private InMemoryTenantContext $tenantContext;
    /** @var list<?string> the selected organization's id when each command ran */
    private array $selectedWhileHandling = [];
    private User $owner;

    protected function setUp(): void
    {
        $this->organizations = new InMemoryOrganizationRepository();
        $this->categories = new InMemoryProductCategoryRepository();
        $this->tenantContext = new InMemoryTenantContext();
        $this->owner = UserMother::any('olivia@example.com');
    }

    public function test_it_adds_the_countrys_tree_as_the_owner_in_the_new_organization(): void
    {
        $organization = $this->organization('PL');

        $this->handle($organization);

        self::assertSame(
            ['Alkohol' => ['Gin', 'Likiery', 'Piwo', 'Rum', 'Whisky', 'Wino', 'Wódka'], 'Media' => ['Gaz', 'Internet i telefon', 'Prąd', 'Woda'], 'Usługi' => ['Księgowość', 'Serwis', 'Transport']],
            array_intersect_key($this->tree(), array_flip(['Alkohol', 'Media', 'Usługi'])),
        );
        self::assertCount(12, array_filter($this->categories->findAll(), static fn (ProductCategory $category): bool => null === $category->getParent()));
        self::assertCount(30, $this->categories->findAll());
        self::assertSame(array_fill(0, 30, $organization->getId()->toRfc4122()), $this->selectedWhileHandling);
        foreach ($this->categories->findAll() as $category) {
            self::assertEquals(new \DateTimeImmutable(self::NOW), $category->getCreatedAt());
        }
        self::assertFalse($this->tenantContext->hasOrganization());
    }

    public function test_it_adds_the_english_tree_for_a_country_without_a_list(): void
    {
        $this->handle($this->organization('DE'));

        self::assertSame(['Beer', 'Gin', 'Liqueurs', 'Rum', 'Vodka', 'Whisky', 'Wine'], $this->tree()['Alcohol'] ?? null);
        self::assertCount(30, $this->categories->findAll());
    }

    public function test_it_selects_the_previously_selected_organization_again(): void
    {
        $previous = OrganizationMother::ownedBy($this->owner, 'Other Bar');
        $this->tenantContext->switchTo($previous);
        $organization = $this->organization('PL');

        $this->handle($organization);

        self::assertSame($previous, $this->tenantContext->getOrganization());
        self::assertSame($organization->getId()->toRfc4122(), $this->selectedWhileHandling[0]);
    }

    public function test_it_adds_nothing_when_the_organization_already_has_categories_so_handling_twice_adds_nothing(): void
    {
        $organization = $this->organization('PL');

        $this->handle($organization);
        $this->handle($organization);

        self::assertCount(30, $this->categories->findAll());

        $other = new InMemoryProductCategoryRepository();
        $other->add(ProductCategory::create(Uuid::v7(), 'Mine', null, null, $this->owner->getId(), MembershipRole::Owner, new \DateTimeImmutable('2026-11-01')));
        $this->categories = $other;
        $this->handle($organization);

        self::assertCount(1, $this->categories->findAll());
    }

    public function test_it_restores_the_selection_when_a_command_fails(): void
    {
        $organization = $this->organization('PL');
        $organizations = $this->organizations;
        $handler = new AddDefaultProductCategoriesOnOrganizationCreated(
            $this->organizations,
            $this->tenantContext,
            new class implements CommandBusInterface {
                public function handle(CommandInterface $command): void
                {
                    throw new \RuntimeException('Database down.');
                }
            },
            new StubQueryBus(fn (QueryInterface $query): mixed => $this->answer($organizations, $query)),
        );

        try {
            $handler(new OrganizationCreated($organization->getId()->toRfc4122(), $this->owner->getId()->toRfc4122()));
            self::fail('The failure should propagate.');
        } catch (\RuntimeException) {
        }

        self::assertFalse($this->tenantContext->hasOrganization());
    }

    public function test_it_reports_an_unknown_organization(): void
    {
        $this->expectException(OrganizationNotFoundException::class);

        $this->handle(OrganizationMother::ownedBy($this->owner));
    }

    private function organization(string $countryCode): Organization
    {
        $organization = new Organization(Uuid::v7(), 'Corner Pub', $this->owner, new \DateTimeImmutable('2026-11-13 09:59:00'), countryCode: $countryCode);
        $this->organizations->add($organization);

        return $organization;
    }

    private function answer(InMemoryOrganizationRepository $organizations, QueryInterface $query): mixed
    {
        return match (true) {
            $query instanceof GetMemberQuery => (new GetMemberHandler($organizations))($query),
            $query instanceof ListProductCategoriesQuery => (new ListProductCategoriesHandler($this->categories, new StubQueryBus(fn (QueryInterface $inner): mixed => $this->answer($organizations, $inner))))($query),
            default => throw new \LogicException(\sprintf('Unexpected query %s.', $query::class)),
        };
    }

    private function handle(Organization $organization): void
    {
        $organizations = $this->organizations;
        $queryBus = new StubQueryBus(fn (QueryInterface $query): mixed => $this->answer($organizations, $query));
        $addCategory = new AddProductCategoryHandler($this->categories, $queryBus, new MockClock(self::NOW));
        $commandBus = new class($addCategory, $this->tenantContext) implements CommandBusInterface {
            /** @var list<?string> */
            public array $selected = [];

            public function __construct(
                private readonly AddProductCategoryHandler $addCategory,
                private readonly InMemoryTenantContext $tenantContext,
            ) {
            }

            public function handle(CommandInterface $command): void
            {
                \assert($command instanceof AddProductCategoryCommand);
                $this->selected[] = $this->tenantContext->hasOrganization() ? $this->tenantContext->getOrganization()->getId()->toRfc4122() : null;
                ($this->addCategory)($command);
            }
        };

        (new AddDefaultProductCategoriesOnOrganizationCreated($this->organizations, $this->tenantContext, $commandBus, $queryBus))(
            new OrganizationCreated($organization->getId()->toRfc4122(), $this->owner->getId()->toRfc4122()),
        );
        $this->selectedWhileHandling = [...$this->selectedWhileHandling, ...$commandBus->selected];
    }

    /** @return array<string, list<string>> top level names => their subcategories' names, sorted */
    private function tree(): array
    {
        $tree = [];
        foreach ($this->categories->findAll() as $category) {
            if (null === $category->getParent()) {
                $tree[$category->getName()] ??= [];
            }
        }
        foreach ($this->categories->findAll() as $category) {
            if (null !== $category->getParent()) {
                $tree[$category->getParent()->getName()][] = $category->getName();
            }
        }
        foreach ($tree as &$children) {
            sort($children);
        }

        return $tree;
    }
}
