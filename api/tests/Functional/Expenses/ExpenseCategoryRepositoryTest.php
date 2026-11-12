<?php

declare(strict_types=1);

namespace App\Tests\Functional\Expenses;

use App\Expenses\Domain\Exception\ExpenseCategoryNameTakenException;
use App\Expenses\Domain\Model\ExpenseCategory;
use App\Expenses\Domain\Repository\ExpenseCategoryRepositoryInterface;
use App\Expenses\Infrastructure\Doctrine\Orm\Repository\ExpenseCategoryRepository;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\TenantContextInterface;
use App\Tests\Factory\OrganizationFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/** ExpenseCategoryRepository against PostgreSQL: the unique name per organization and the TenantFilter. */
#[CoversClass(ExpenseCategoryRepository::class)]
final class ExpenseCategoryRepositoryTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private EntityManagerInterface $entityManager;
    private TenantContextInterface $tenantContext;
    private ExpenseCategoryRepositoryInterface $categories;

    protected function setUp(): void
    {
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->tenantContext = self::getContainer()->get(TenantContextInterface::class);
        $this->categories = self::getContainer()->get(ExpenseCategoryRepositoryInterface::class);
    }

    public function test_it_stores_a_category_in_the_current_organization(): void
    {
        $organization = OrganizationFactory::createOne();
        $this->tenantContext->switchTo($organization);
        $category = $this->category('Rent');
        $this->categories->add($category);
        $this->entityManager->clear();

        $found = $this->categories->findById($category->getId());
        self::assertNotNull($found);
        self::assertSame('Rent', $found->getName());
        self::assertFalse($found->isArchived());
        self::assertTrue($found->getOrganization()?->getId()->equals($organization->getId()));
        self::assertSame($category->getId()->toRfc4122(), $this->categories->findByName('Rent')?->getId()->toRfc4122());
        self::assertNull($this->categories->findByName('rent'));
    }

    public function test_it_rejects_a_second_category_with_the_same_name_in_the_organization(): void
    {
        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        $this->categories->add($this->category('Rent'));

        $this->expectException(ExpenseCategoryNameTakenException::class);

        // A concurrent write that did not see the first category: only the constraint can stop it.
        $this->categories->add($this->category('Rent'));
    }

    public function test_it_rejects_a_rename_to_a_name_stored_in_the_meantime(): void
    {
        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        $this->categories->add($this->category('Rent'));
        $food = $this->category('Food');
        $this->categories->add($food);

        $food->rename('Rent', null, Uuid::v7(), MembershipRole::Owner);

        $this->expectException(ExpenseCategoryNameTakenException::class);

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

    public function test_it_lists_active_categories_by_name_and_the_archived_ones_last_on_request(): void
    {
        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        $utilities = $this->category('Utilities');
        $utilities->archive(Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable('2026-11-01'));
        foreach ([$this->category('Rent'), $utilities, $this->category('Food'), $this->category('Beverages')] as $category) {
            $this->categories->add($category);
        }
        $this->categories->findByName('Beverages')?->archive(Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable('2026-11-02'));
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertSame(['Food', 'Rent'], $this->names($this->categories->findAll()));
        self::assertSame(['Food', 'Rent', 'Beverages', 'Utilities'], $this->names($this->categories->findAll(includeArchived: true)));
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
        self::assertNull($this->categories->findByName('Rent'));
        self::assertSame([], $this->categories->findAll(includeArchived: true));
    }

    private function category(string $name): ExpenseCategory
    {
        return ExpenseCategory::create(Uuid::v7(), $name, null, Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable('2026-10-01'));
    }

    /**
     * @param list<ExpenseCategory> $categories
     *
     * @return list<string>
     */
    private function names(array $categories): array
    {
        return array_map(static fn (ExpenseCategory $category): string => $category->getName(), $categories);
    }
}
