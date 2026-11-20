<?php

declare(strict_types=1);

namespace App\Tests\Api\Expenses;

use App\Expenses\Domain\Model\ExpenseCategory;
use App\Expenses\Domain\Model\PurchaseInvoice;
use App\Expenses\Domain\Model\PurchaseInvoiceDocument;
use App\Expenses\Domain\Repository\ExpenseCategoryRepositoryInterface;
use App\Expenses\Domain\Repository\PurchaseInvoiceRepositoryInterface;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;
use App\Tests\Api\ApiTestCase;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixtures\OrganizationMother;
use App\User\Domain\Model\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** Arranges expenses and categories in an organization's tenant, as the Expenses API tests share them. */
abstract class ExpensesApiTestCase extends ApiTestCase
{
    protected const string XML = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<Faktura><P_2>FV/1/2026</P_2></Faktura>\n";
    protected const array MERGE_PATCH = ['Content-Type' => 'application/merge-patch+json'];

    protected static function get(User $user, Organization $organization, string $id): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('GET', '/api/expenses/'.$id);
    }

    protected static function addInvoice(Organization $organization, PurchaseInvoice $invoice, bool $document = false, ?ExpenseCategory $category = null): PurchaseInvoice
    {
        self::inTenant($organization, static function () use ($organization, $invoice, $document, $category): void {
            if (null !== $category) {
                $managed = static::getContainer()->get(ExpenseCategoryRepositoryInterface::class)->findById($category->getId()) ?? throw new \LogicException('Category expected.');
                $invoice->categorize($managed, OrganizationMother::ownerOf($organization)->getId(), MembershipRole::Owner);
            }
            static::getContainer()->get(PurchaseInvoiceRepositoryInterface::class)->add(
                $invoice,
                $document ? PurchaseInvoiceDocument::of($invoice, self::XML, 'FA (3)', new \DateTimeImmutable('2026-11-12 21:00:05')) : null,
            );
        });

        return $invoice;
    }

    protected static function addCategory(Organization $organization, string $name): ExpenseCategory
    {
        $owner = OrganizationMother::ownerOf($organization);
        $category = ExpenseCategory::create(Uuid::v7(), $name, null, $owner->getId(), MembershipRole::Owner, new \DateTimeImmutable('2026-11-01 10:00:00'));

        self::inTenant($organization, static fn () => static::getContainer()->get(ExpenseCategoryRepositoryInterface::class)->add($category));

        return $category;
    }

    protected static function archiveCategory(Organization $organization, ExpenseCategory $category): void
    {
        $owner = OrganizationMother::ownerOf($organization);

        self::inTenant($organization, static function () use ($category, $owner): void {
            $categories = static::getContainer()->get(ExpenseCategoryRepositoryInterface::class);
            $managed = $categories->findById($category->getId()) ?? throw new \LogicException('Category expected.');
            $managed->archive($owner->getId(), MembershipRole::Owner, new \DateTimeImmutable('2026-11-02 10:00:00'));
            $categories->save($managed);
        });
    }

    protected static function addMember(Organization $organization, MembershipRole $role): User
    {
        $user = UserFactory::createOne();
        OrganizationMother::addMember($organization, $user, $role);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        return $user;
    }

    protected static function inTenant(Organization $organization, \Closure $action): void
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
