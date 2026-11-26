<?php

declare(strict_types=1);

namespace App\Tests\Functional\Purchasing;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Domain\Model\AliasKind;
use App\Purchasing\Domain\Model\Product;
use App\Purchasing\Domain\Model\ProductAlias;
use App\Purchasing\Domain\Model\ProductMeasure;
use App\Purchasing\Domain\Repository\ProductAliasRepositoryInterface;
use App\Purchasing\Domain\Repository\ProductRepositoryInterface;
use App\Purchasing\Infrastructure\Doctrine\Orm\Repository\ProductAliasRepository;
use App\Tests\Factory\OrganizationFactory;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/** ProductAliasRepository against PostgreSQL: lookup by (kind, key), one alias per key, the merge move and the TenantFilter (ADR 0042 §2). */
#[CoversClass(ProductAliasRepository::class)]
#[CoversClass(ProductAlias::class)]
final class ProductAliasRepositoryTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private EntityManagerInterface $entityManager;
    private TenantContextInterface $tenantContext;
    private ProductAliasRepositoryInterface $aliases;
    private ProductRepositoryInterface $products;

    protected function setUp(): void
    {
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->tenantContext = self::getContainer()->get(TenantContextInterface::class);
        $this->aliases = self::getContainer()->get(ProductAliasRepositoryInterface::class);
        $this->products = self::getContainer()->get(ProductRepositoryInterface::class);
    }

    public function test_it_finds_aliases_by_kind_and_key_with_their_product_and_package_size(): void
    {
        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        $jack = $this->product('Jack Daniel\'s');
        $this->aliases->saveAll([
            $this->alias(AliasKind::Gtin, '5099873089798', $jack, '0.7'),
            $this->alias(AliasKind::SellerCode, '5265877635|JD-07', $jack, '0.700000'),
            $this->alias(AliasKind::Name, 'jack daniels', $jack, '9'),
        ]);
        $this->entityManager->clear();

        $found = $this->aliases->findByKeys([
            ['kind' => 'gtin', 'key' => '5099873089798'],
            ['kind' => 'seller_code', 'key' => '5265877635|JD-07'],
            ['kind' => 'name', 'key' => 'jack daniels'],
            ['kind' => 'gtin', 'key' => '0000000000000'],
            ['kind' => 'seller_name', 'key' => 'jack daniels'],
        ]);

        /** @var array<string, ProductAlias> $byKind */
        $byKind = [];
        foreach ($found as $alias) {
            $byKind[$alias->getKind()->value] = $alias;
        }
        self::assertEqualsCanonicalizing(['gtin', 'seller_code', 'name'], array_keys($byKind));
        self::assertSame('0.7', $byKind['gtin']->getPackageSize());
        self::assertSame('0.7', $byKind['seller_code']->getPackageSize());
        self::assertNull($byKind['name']->getPackageSize(), 'A name alias carries no size.');
        self::assertTrue($byKind['gtin']->getProduct()->getId()->equals($jack->getId()));
        self::assertSame([], $this->aliases->findByKeys([]));
    }

    public function test_it_keeps_one_alias_per_organization_kind_and_key(): void
    {
        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        $jack = $this->product('Jack Daniel\'s');
        $this->aliases->saveAll([$this->alias(AliasKind::Name, 'jack daniels', $jack)]);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->aliases->saveAll([$this->alias(AliasKind::Name, 'jack daniels', $jack)]);
    }

    public function test_it_points_an_existing_alias_to_another_product(): void
    {
        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        $jack = $this->product('Jack Daniel\'s');
        $honey = $this->product('Jack Daniel\'s Honey');
        $alias = $this->alias(AliasKind::SellerCode, 'S|X', $jack, '0.7');
        $this->aliases->saveAll([$alias]);

        $alias->repoint($honey, '1', new \DateTimeImmutable('2026-11-27 10:00:00'));
        $this->aliases->saveAll([$alias]);
        $this->entityManager->clear();

        [$stored] = $this->aliases->findByKeys([['kind' => 'seller_code', 'key' => 'S|X']]);
        self::assertTrue($stored->getProduct()->getId()->equals($honey->getId()));
        self::assertSame('1', $stored->getPackageSize());
    }

    public function test_it_moves_the_aliases_of_a_merged_product_to_the_target(): void
    {
        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        $from = $this->product('J.D.');
        $to = $this->product('Jack Daniel\'s');
        $this->aliases->saveAll([
            $this->alias(AliasKind::Name, 'j d', $from),
            $this->alias(AliasKind::Gtin, '5099873089798', $from, '0.7'),
            $this->alias(AliasKind::Gtin, '5099873089799', $to, '1'),
        ]);
        $this->entityManager->clear();
        $this->aliases->moveAliases($from->getId(), $to->getId());
        $this->entityManager->clear();

        $found = $this->aliases->findByKeys([
            ['kind' => 'name', 'key' => 'j d'], ['kind' => 'gtin', 'key' => '5099873089798'], ['kind' => 'gtin', 'key' => '5099873089799'],
        ]);

        self::assertCount(3, $found);
        foreach ($found as $alias) {
            self::assertTrue($alias->getProduct()->getId()->equals($to->getId()));
        }
    }

    public function test_it_goes_with_its_product(): void
    {
        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        $jack = $this->product('Jack Daniel\'s');
        $this->aliases->saveAll([$this->alias(AliasKind::Name, 'jack daniels', $jack)]);

        $this->products->remove($jack);
        $this->entityManager->clear();

        self::assertSame([], $this->aliases->findByKeys([['kind' => 'name', 'key' => 'jack daniels']]));
    }

    public function test_it_is_invisible_to_another_organization(): void
    {
        $mine = OrganizationFactory::createOne();
        $this->tenantContext->switchTo($mine);
        $this->aliases->saveAll([$this->alias(AliasKind::Name, 'jack daniels', $this->product('Jack Daniel\'s'))]);
        $this->entityManager->clear();

        $this->tenantContext->switchTo(OrganizationFactory::createOne());

        self::assertSame([], $this->aliases->findByKeys([['kind' => 'name', 'key' => 'jack daniels']]));
    }

    private function product(string $name): Product
    {
        $product = Product::create(Uuid::v7(), $name, null, ProductMeasure::Volume, null, false, null, Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable('2026-11-01 10:00:00'));
        $this->products->add($product);

        return $product;
    }

    private function alias(AliasKind $kind, string $key, Product $product, ?string $size = null): ProductAlias
    {
        return new ProductAlias(Uuid::v7(), $kind, $key, $product, $size, new \DateTimeImmutable('2026-11-02 10:00:00'));
    }
}
