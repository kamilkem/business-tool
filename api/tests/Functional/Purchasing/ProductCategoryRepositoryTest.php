<?php

declare(strict_types=1);

namespace App\Tests\Functional\Purchasing;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Domain\Exception\ProductCategoryInUseException;
use App\Purchasing\Domain\Exception\ProductCategoryNameTakenException;
use App\Purchasing\Domain\Model\ProductCategory;
use App\Purchasing\Domain\Repository\ProductCategoryRepositoryInterface;
use App\Purchasing\Infrastructure\Doctrine\Orm\Repository\ProductCategoryRepository;
use App\Tests\Factory\OrganizationFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/** ProductCategoryRepository against PostgreSQL: unique sibling names, the tree and the TenantFilter. */
#[CoversClass(ProductCategoryRepository::class)]
final class ProductCategoryRepositoryTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private EntityManagerInterface $entityManager;
    private TenantContextInterface $tenantContext;
    private ProductCategoryRepositoryInterface $categories;

    protected function setUp(): void
    {
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->tenantContext = self::getContainer()->get(TenantContextInterface::class);
        $this->categories = self::getContainer()->get(ProductCategoryRepositoryInterface::class);
    }

    public function test_it_stores_a_tree_in_the_current_organization(): void
    {
        $organization = OrganizationFactory::createOne();
        $this->tenantContext->switchTo($organization);
        $alcohol = $this->category('Alcohol');
        $whisky = $this->category('Whisky', $alcohol);
        $this->categories->add($alcohol);
        $this->categories->add($whisky);
        $this->entityManager->clear();

        $found = $this->categories->findById($whisky->getId());
        self::assertNotNull($found);
        self::assertSame('Whisky', $found->getName());
        self::assertTrue($found->getParent()?->getId()->equals($alcohol->getId()));
        self::assertTrue($found->getOrganization()?->getId()->equals($organization->getId()));
        self::assertNull($this->categories->findById($alcohol->getId())?->getParent());
    }

    public function test_it_finds_a_sibling_by_name_case_insensitively_under_the_same_parent_only(): void
    {
        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        $alcohol = $this->category('Alcohol');
        $whisky = $this->category('Whisky', $alcohol);
        foreach ([$alcohol, $whisky] as $category) {
            $this->categories->add($category);
        }
        $this->entityManager->clear();

        self::assertTrue($this->categories->findSibling($alcohol->getId(), ' wHISKY ')?->getId()->equals($whisky->getId()));
        self::assertTrue($this->categories->findSibling(null, 'ALCOHOL')?->getId()->equals($alcohol->getId()));
        self::assertNull($this->categories->findSibling(null, 'Whisky'));
        self::assertNull($this->categories->findSibling($alcohol->getId(), 'Alcohol'));
        self::assertNull($this->categories->findSibling(Uuid::v7(), 'Whisky'));
    }

    public function test_it_rejects_a_second_top_level_category_with_the_same_name_in_another_case(): void
    {
        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        $this->categories->add($this->category('Alcohol'));

        $this->expectException(ProductCategoryNameTakenException::class);

        // A concurrent write that did not see the first category: only the constraint can stop it.
        $this->categories->add($this->category('ALCOHOL'));
    }

    public function test_it_rejects_a_second_subcategory_with_the_same_name_in_another_case(): void
    {
        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        $alcohol = $this->category('Alcohol');
        $this->categories->add($alcohol);
        $this->categories->add($this->category('Whisky', $alcohol));

        try {
            $this->categories->add($this->category('whisky', $alcohol));
            self::fail('The name should be taken.');
        } catch (ProductCategoryNameTakenException $e) {
            self::assertSame(['name' => 'whisky', 'parentId' => $alcohol->getId()->toRfc4122()], $e->errorParameters());
        }
    }

    public function test_it_allows_the_same_name_under_different_parents_and_at_the_top_level(): void
    {
        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        $alcohol = $this->category('Alcohol');
        $food = $this->category('Food');
        foreach ([$alcohol, $food, $this->category('Other', $alcohol), $this->category('Other', $food), $this->category('Other')] as $category) {
            $this->categories->add($category);
        }
        $this->entityManager->clear();

        self::assertSame(['Alcohol', 'Food', 'Other', 'Other', 'Other'], $this->names($this->categories->findAll()));
    }

    public function test_it_rejects_a_rename_to_a_name_stored_in_the_meantime(): void
    {
        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        $this->categories->add($this->category('Rent'));
        $food = $this->category('Food');
        $this->categories->add($food);

        $food->change('RENT', null, null, Uuid::v7(), MembershipRole::Owner);

        $this->expectException(ProductCategoryNameTakenException::class);

        $this->categories->save($food);
    }

    public function test_it_allows_the_same_name_in_another_organization(): void
    {
        [$a, $b] = OrganizationFactory::createMany(2);
        $this->tenantContext->switchTo($a);
        $this->categories->add($this->category('Rent'));
        $this->tenantContext->switchTo($b);
        $this->categories->add($this->category('Rent'));
        $this->entityManager->clear();

        self::assertCount(1, $this->categories->findAll());
    }

    public function test_it_lists_by_name_without_regard_to_case(): void
    {
        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        foreach (['utilities', 'Rent', 'Food', 'Beverages'] as $name) {
            $this->categories->add($this->category($name));
        }
        $this->entityManager->clear();

        self::assertSame(['Beverages', 'Food', 'Rent', 'utilities'], $this->names($this->categories->findAll()));
    }

    public function test_it_knows_whether_a_category_has_subcategories(): void
    {
        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        $alcohol = $this->category('Alcohol');
        $whisky = $this->category('Whisky', $alcohol);
        $this->categories->add($alcohol);
        $this->categories->add($whisky);
        $this->entityManager->clear();

        self::assertTrue($this->categories->hasSubcategories($alcohol->getId()));
        self::assertFalse($this->categories->hasSubcategories($whisky->getId()));
    }

    public function test_it_removes_a_category_but_the_foreign_key_keeps_one_with_subcategories(): void
    {
        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        $alcohol = $this->category('Alcohol');
        $whisky = $this->category('Whisky', $alcohol);
        $this->categories->add($alcohol);
        $this->categories->add($whisky);

        $this->categories->remove($whisky);
        self::assertNull($this->categories->findById($whisky->getId()));
        $this->categories->add($this->category('Rum', $alcohol));
        $this->entityManager->clear();
        $this->tenantContext->switchTo($this->entityManager->find(Organization::class, $this->tenantContext->getOrganization()->getId()) ?? self::fail('Organization expected.'));

        $this->expectException(ProductCategoryInUseException::class);
        $this->expectExceptionMessageMatches('/has subcategories/');

        $this->categories->remove($this->categories->findById($alcohol->getId()) ?? self::fail('Category expected.'));
    }

    public function test_it_does_not_show_another_organizations_categories(): void
    {
        [$a, $b] = OrganizationFactory::createMany(2);
        $this->tenantContext->switchTo($b);
        $foreign = $this->category('Rent');
        $this->categories->add($foreign);
        $this->entityManager->clear();

        $this->tenantContext->switchTo($a);

        self::assertNull($this->categories->findById($foreign->getId()));
        self::assertNull($this->categories->findSibling(null, 'Rent'));
        self::assertSame([], $this->categories->findAll());
    }

    private function category(string $name, ?ProductCategory $parent = null): ProductCategory
    {
        return ProductCategory::create(Uuid::v7(), $name, $parent, null, Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable('2026-10-01'));
    }

    /**
     * @param list<ProductCategory> $categories
     *
     * @return list<string>
     */
    private function names(array $categories): array
    {
        return array_map(static fn (ProductCategory $category): string => $category->getName(), $categories);
    }
}
