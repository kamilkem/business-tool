<?php

declare(strict_types=1);

namespace App\Tests\Functional\Purchasing;

use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Domain\Model\SellerUnit;
use App\Purchasing\Domain\Repository\SellerUnitRepositoryInterface;
use App\Purchasing\Infrastructure\Doctrine\Orm\Repository\SellerUnitRepository;
use App\Tests\Factory\OrganizationFactory;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/** SellerUnitRepository against PostgreSQL: lookup by (seller, unit), one multiplier per unit, the TenantFilter (ADR 0043). */
#[CoversClass(SellerUnitRepository::class)]
#[CoversClass(SellerUnit::class)]
final class SellerUnitRepositoryTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private EntityManagerInterface $entityManager;
    private TenantContextInterface $tenantContext;
    private SellerUnitRepositoryInterface $units;

    protected function setUp(): void
    {
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->tenantContext = self::getContainer()->get(TenantContextInterface::class);
        $this->units = self::getContainer()->get(SellerUnitRepositoryInterface::class);
    }

    public function test_it_finds_the_multipliers_of_the_given_sellers_and_units_only(): void
    {
        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        $this->units->saveAll([$this->unit('5265877635', 'co', 6), $this->unit('5265877635', 'karton', 24), $this->unit('1111111111', 'co', 12)]);
        $this->entityManager->clear();

        $found = $this->units->findByPairs([['seller' => '5265877635', 'unit' => 'co'], ['seller' => '1111111111', 'unit' => 'co'], ['seller' => '1111111111', 'unit' => 'karton']]);

        $byKey = [];
        foreach ($found as $unit) {
            $byKey[$unit->getSellerKey().'#'.$unit->getUnitKey()] = $unit->getMultiplier();
        }
        self::assertSame(['5265877635#co' => 6, '1111111111#co' => 12], $byKey);
        self::assertSame([], $this->units->findByPairs([]));
    }

    public function test_it_keeps_one_multiplier_per_organization_seller_and_unit_and_changes_it_in_place(): void
    {
        $organization = OrganizationFactory::createOne();
        $this->tenantContext->switchTo($organization);
        $unit = $this->unit('5265877635', 'co', 6);
        $this->units->saveAll([$unit]);

        $unit->change(12, new \DateTimeImmutable('2026-11-27 10:00:00'));
        $this->units->saveAll([$unit]);
        $this->entityManager->clear();

        [$stored] = $this->units->findByPairs([['seller' => '5265877635', 'unit' => 'co']]);
        self::assertSame(12, $stored->getMultiplier());

        // After clear() the tenant's organization is detached: select it again, as a request would.
        $this->tenantContext->switchTo($this->entityManager->find(Organization::class, $organization->getId()) ?? throw new \LogicException('Organization expected.'));
        $this->expectException(UniqueConstraintViolationException::class);
        $this->units->saveAll([$this->unit('5265877635', 'co', 3)]);
    }

    public function test_it_is_invisible_to_another_organization(): void
    {
        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        $this->units->saveAll([$this->unit('5265877635', 'co', 6)]);
        $this->entityManager->clear();

        $this->tenantContext->switchTo(OrganizationFactory::createOne());

        self::assertSame([], $this->units->findByPairs([['seller' => '5265877635', 'unit' => 'co']]));
    }

    public function test_it_refuses_a_unit_that_holds_nothing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->unit('5265877635', 'co', 0);
    }

    private function unit(string $seller, string $unit, int $multiplier): SellerUnit
    {
        return new SellerUnit(Uuid::v7(), $seller, $unit, $multiplier, new \DateTimeImmutable('2026-11-02 10:00:00'));
    }
}
