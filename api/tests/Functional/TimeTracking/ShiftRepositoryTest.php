<?php

declare(strict_types=1);

namespace App\Tests\Functional\TimeTracking;

use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;
use App\Tests\Factory\OrganizationFactory;
use App\TimeTracking\Domain\Exception\ShiftAlreadyOpenException;
use App\TimeTracking\Domain\Model\Shift;
use App\TimeTracking\Domain\Repository\ShiftRepositoryInterface;
use App\TimeTracking\Infrastructure\Doctrine\Orm\Repository\ShiftRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * ShiftRepository against PostgreSQL, including the partial unique index on open shifts and the
 * TenantFilter. The test schema is built from mapping (Foundry mode: schema), so the index comes
 * from the Shift mapping, as in the migration.
 */
#[CoversClass(ShiftRepository::class)]
final class ShiftRepositoryTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private const string START = '2026-11-11 16:00:00';
    private const string END = '2026-11-12 00:30:00';

    private EntityManagerInterface $entityManager;
    private TenantContextInterface $tenantContext;
    private ShiftRepositoryInterface $shifts;

    protected function setUp(): void
    {
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->tenantContext = self::getContainer()->get(TenantContextInterface::class);
        $this->shifts = self::getContainer()->get(ShiftRepositoryInterface::class);
    }

    public function test_it_stores_a_shift_in_the_current_organization(): void
    {
        $organization = OrganizationFactory::createOne();
        $userId = Uuid::v7();
        $this->switchTo($organization);

        $shift = Shift::clockIn(Uuid::v7(), $userId, new \DateTimeImmutable(self::START), null, 'Opening');
        $this->shifts->add($shift);
        $this->entityManager->clear();

        $found = $this->shifts->findById($shift->getId());
        self::assertNotNull($found);
        self::assertTrue($found->getUserId()->equals($userId));
        self::assertEquals(new \DateTimeImmutable(self::START), $found->getStartedAt());
        self::assertNull($found->getEndedAt());
        self::assertSame('Opening', $found->getNote());
        self::assertTrue($found->getOrganization()?->getId()->equals($organization->getId()));
    }

    public function test_it_finds_the_open_shift_of_a_person_until_they_clock_out(): void
    {
        $this->switchTo(OrganizationFactory::createOne());
        $userId = Uuid::v7();
        $shift = Shift::clockIn(Uuid::v7(), $userId, new \DateTimeImmutable(self::START), null);
        $this->shifts->add($shift);

        self::assertSame($shift, $this->shifts->findOpenByUser($userId));
        self::assertNull($this->shifts->findOpenByUser(Uuid::v7()));

        $shift->clockOut(new \DateTimeImmutable(self::END));
        $this->shifts->save($shift);
        $this->entityManager->clear();

        self::assertNull($this->shifts->findOpenByUser($userId));
        self::assertEquals(new \DateTimeImmutable(self::END), $this->shifts->findById($shift->getId())?->getEndedAt());
    }

    public function test_it_does_not_show_another_organizations_shifts(): void
    {
        [$a, $b] = OrganizationFactory::createMany(2);
        $userId = Uuid::v7();
        $this->switchTo($b);
        $foreign = Shift::clockIn(Uuid::v7(), $userId, new \DateTimeImmutable(self::START), null);
        $this->shifts->add($foreign);
        $this->entityManager->clear();

        $this->switchTo($a);

        self::assertNull($this->shifts->findById($foreign->getId()));
        self::assertNull($this->shifts->findOpenByUser($userId));
    }

    public function test_it_rejects_a_second_open_shift_of_the_same_person_in_the_organization(): void
    {
        $this->switchTo(OrganizationFactory::createOne());
        $userId = Uuid::v7();
        $this->shifts->add(Shift::clockIn(Uuid::v7(), $userId, new \DateTimeImmutable(self::START), null));

        $this->expectException(ShiftAlreadyOpenException::class);

        // A concurrent clock-in that did not see the first shift: only the index can stop it.
        $this->shifts->add(Shift::clockIn(Uuid::v7(), $userId, new \DateTimeImmutable('2026-11-11 16:00:01'), null));
    }

    public function test_it_allows_a_new_open_shift_after_the_previous_one_ended(): void
    {
        $this->switchTo(OrganizationFactory::createOne());
        $userId = Uuid::v7();
        $first = Shift::clockIn(Uuid::v7(), $userId, new \DateTimeImmutable(self::START), null);
        $this->shifts->add($first);
        $first->clockOut(new \DateTimeImmutable(self::END));
        $this->shifts->save($first);

        $second = Shift::clockIn(Uuid::v7(), $userId, new \DateTimeImmutable('2026-11-12 16:00:00'), null);
        $this->shifts->add($second);

        self::assertSame($second, $this->shifts->findOpenByUser($userId));
    }

    public function test_it_allows_one_open_shift_per_organization_for_the_same_person(): void
    {
        [$a, $b] = OrganizationFactory::createMany(2);
        $userId = Uuid::v7();

        $this->switchTo($a);
        $this->shifts->add(Shift::clockIn(Uuid::v7(), $userId, new \DateTimeImmutable(self::START), null));
        $this->entityManager->clear();
        $this->switchTo($b);
        $inB = Shift::clockIn(Uuid::v7(), $userId, new \DateTimeImmutable(self::START), null);
        $this->shifts->add($inB);

        self::assertSame($inB, $this->shifts->findOpenByUser($userId));
    }

    private function switchTo(Organization $organization): void
    {
        $this->tenantContext->switchTo(
            $this->entityManager->getReference(Organization::class, $organization->getId())
                ?? throw new \LogicException(),
        );
    }
}
