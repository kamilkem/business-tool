<?php

declare(strict_types=1);

namespace App\Tests\Functional\Purchasing;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Application\Job\BackfillProductAliases\BackfillProductAliasesJob;
use App\Purchasing\Application\Job\BackfillProductAliases\BackfillProductAliasesJobHandler;
use App\Purchasing\Domain\Model\Product;
use App\Purchasing\Domain\Model\ProductMeasure;
use App\Purchasing\Domain\Model\PurchasedItem;
use App\Purchasing\Domain\Model\PurchasedItemDetails;
use App\Purchasing\Domain\Model\PurchasedItemStatus;
use App\Purchasing\Domain\Repository\ProductRepositoryInterface;
use App\Purchasing\Domain\Repository\PurchasedItemRepositoryInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Job\JobBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\Tests\Factory\OrganizationFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Learning aliases from items confirmed before aliases existed (ADR 0045 §3), through the real buses and
 * PostgreSQL: the aliases appear in the organization the job runs in and nowhere else, no item or link
 * changes, and a second run adds nothing.
 */
final class BackfillProductAliasesFlowTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private TenantContextInterface $tenantContext;
    private PurchasedItemRepositoryInterface $items;
    private ProductRepositoryInterface $products;
    private EntityManagerInterface $entityManager;
    private Connection $connection;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $this->tenantContext = $container->get(TenantContextInterface::class);
        $this->items = $container->get(PurchasedItemRepositoryInterface::class);
        $this->products = $container->get(ProductRepositoryInterface::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
    }

    public function test_it_teaches_the_aliases_of_the_confirmed_items_of_its_organization_only_and_changes_no_link(): void
    {
        $mine = OrganizationFactory::createOne();
        $this->tenantContext->switchTo($mine);
        $jack = $this->product('Jack Daniel\'s');
        $oldJack = $this->product('Jack Daniel\'s Old');
        $this->items->addAll([
            $this->confirmed($oldJack, 'JACK DANIELS 0,7L', '2026-09-01', '0.7', 'JD-07'),
            $this->confirmed($jack, 'JACK DANIELS 0,7L', '2026-10-01', '0.7', 'JD-07'),
            $this->open('Serwetki'),
        ]);
        $this->entityManager->clear();

        $theirs = OrganizationFactory::createOne();
        $this->tenantContext->switchTo($theirs);
        $theirJack = $this->product('Jack');
        $this->items->addAll([$this->confirmed($theirJack, 'WHISKY OTHER 1L', '2026-10-01', '1', null)]);
        $this->entityManager->clear();
        $linksBefore = $this->links();

        $this->backfill($mine);

        self::assertSame(
            [['name', 'jack daniels', null, $jack->getId()->toRfc4122()], ['seller_code', '5265877635|JD-07', '0.7', $jack->getId()->toRfc4122()], ['seller_name', '5265877635|jack daniels 0 7l', '0.7', $jack->getId()->toRfc4122()]],
            $this->aliasRows($mine),
            'The newest item of a key taught it, and the open item taught nothing.',
        );
        self::assertSame([], $this->aliasRows($theirs));
        self::assertSame($linksBefore, $this->links(), 'No item, status or link changed.');

        $this->backfill($mine);
        self::assertCount(3, $this->aliasRows($mine), 'A second run adds nothing.');
        self::assertSame([], $this->aliasRows($theirs));

        $this->backfill($theirs);
        self::assertSame([['name', 'whisky other', null, $theirJack->getId()->toRfc4122()], ['seller_name', '5265877635|whisky other 1l', '1', $theirJack->getId()->toRfc4122()]], $this->aliasRows($theirs));
        self::assertCount(3, $this->aliasRows($mine));
    }

    private function backfill(Organization $organization): void
    {
        $this->entityManager->clear();
        $this->tenantContext->switchTo($this->entityManager->find(Organization::class, $organization->getId()) ?? throw new \LogicException('Organization expected.'));
        $container = self::getContainer();
        (new BackfillProductAliasesJobHandler(
            $this->tenantContext,
            $container->get(QueryBusInterface::class),
            $container->get(CommandBusInterface::class),
            $container->get(JobBusInterface::class),
            $container->get(ClockInterface::class),
            new NullLogger(),
        ))(new BackfillProductAliasesJob($organization->getId()->toRfc4122()));
    }

    /** @return list<array{string, string, ?string, string}> kind, key, package size and product id of the organization's aliases */
    private function aliasRows(Organization $organization): array
    {
        /** @var list<array{kind: string, alias_key: string, package_size: ?string, product_id: string}> $rows */
        $rows = $this->connection->fetchAllAssociative('SELECT kind, alias_key, package_size, product_id FROM product_alias WHERE organization_id = :id ORDER BY kind, alias_key', ['id' => $organization->getId()->toRfc4122()]);

        return array_map(static fn (array $row): array => [$row['kind'], $row['alias_key'], null === $row['package_size'] ? null : rtrim(rtrim($row['package_size'], '0'), '.'), $row['product_id']], $rows);
    }

    /** @return list<array<string, mixed>> */
    private function links(): array
    {
        return $this->connection->fetchAllAssociative('SELECT id, status, product_id, package_size, matched_at, matching_skipped FROM purchased_item ORDER BY id');
    }

    private function product(string $name): Product
    {
        $product = Product::create(Uuid::v7(), $name, null, ProductMeasure::Volume, null, false, null, Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable('2026-11-01 10:00:00'));
        $this->products->add($product);

        return $product;
    }

    private function confirmed(Product $product, string $name, string $issueDate, string $size, ?string $sellerCode): PurchasedItem
    {
        $item = $this->open($name, $issueDate, $sellerCode);
        $item->link($product, $size, Uuid::v7(), MembershipRole::Owner);
        self::assertSame(PurchasedItemStatus::Confirmed, $item->getStatus());

        return $item;
    }

    private function open(string $name, string $issueDate = '2026-11-10', ?string $sellerCode = null): PurchasedItem
    {
        return PurchasedItem::record(Uuid::v7(), new PurchasedItemDetails(
            Uuid::v7(), 1, BusinessDate::fromString($issueDate), 'regular', 'PLN', '5265877635', 'Hurtownia Napojów', 1, $name, $sellerCode, null, 'szt.',
            '6', '81.3', '100', null, 48_780, 60_000, 11_220, '23', false,
        ), new \DateTimeImmutable('2026-11-25 03:00:00'));
    }
}
