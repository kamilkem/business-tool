<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ksef;

use App\Ksef\Domain\Model\KsefConnection;
use App\Ksef\Domain\Model\KsefErrorCode;
use App\Ksef\Domain\Repository\KsefConnectionRepositoryInterface;
use App\Ksef\Infrastructure\Doctrine\Orm\Repository\KsefConnectionRepository;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;
use App\Tests\Factory\OrganizationFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The KSeF connection adapter against PostgreSQL: the import status columns, and the fan-out's list of
 * organizations, which spans tenants and returns ids only (adr/0025 §4).
 */
#[CoversClass(KsefConnectionRepository::class)]
final class KsefConnectionRepositoryTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private const string NOW = '2026-11-20 21:00:00';

    private EntityManagerInterface $entityManager;
    private TenantContextInterface $tenantContext;
    private KsefConnectionRepositoryInterface $connections;

    protected function setUp(): void
    {
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->tenantContext = self::getContainer()->get(TenantContextInterface::class);
        $this->connections = self::getContainer()->get(KsefConnectionRepositoryInterface::class);
    }

    public function test_it_lists_across_organizations_those_that_may_sync(): void
    {
        $active = $this->connect(OrganizationFactory::createOne());
        $rateLimitedUntilLater = $this->connect(OrganizationFactory::createOne(), static fn (KsefConnection $c) => $c->recordSyncRun(new \DateTimeImmutable(self::NOW), 0, 0, KsefErrorCode::RateLimited, '429', new \DateTimeImmutable('2026-11-20 21:30:00')));
        $rateLimitedUntilNow = $this->connect(OrganizationFactory::createOne(), static fn (KsefConnection $c) => $c->recordSyncRun(new \DateTimeImmutable(self::NOW), 0, 0, KsefErrorCode::RateLimited, '429', new \DateTimeImmutable(self::NOW)));
        $this->connect(OrganizationFactory::createOne(), static fn (KsefConnection $c) => $c->recordSyncRun(new \DateTimeImmutable(self::NOW), 0, 0, KsefErrorCode::CredentialsRejected, 'revoked'));
        $this->connect(OrganizationFactory::createOne(), static fn (KsefConnection $c) => $c->tokenRemoved(Uuid::v7(), MembershipRole::Owner));
        OrganizationFactory::createOne();

        $ids = $this->connections->findOrganizationIdsToSync(new \DateTimeImmutable(self::NOW));

        $expected = [$active->getId()->toRfc4122(), $rateLimitedUntilNow->getId()->toRfc4122()];
        sort($expected);
        self::assertSame($expected, $ids);
        self::assertNotContains($rateLimitedUntilLater->getId()->toRfc4122(), $ids);
        self::assertFalse($this->tenantContext->hasOrganization());
    }

    public function test_it_stores_the_import_status(): void
    {
        $organization = OrganizationFactory::createOne();
        $this->connect($organization, static function (KsefConnection $c): void {
            $c->requestImport('PL', '1111111111', Uuid::v7(), MembershipRole::Manager, new \DateTimeImmutable('2026-11-20 20:50:00'));
            $c->advanceSyncCursor(new \DateTimeImmutable('2026-11-20 20:00:00.987654 UTC'));
            $c->recordSyncRun(new \DateTimeImmutable(self::NOW), 3, 1, KsefErrorCode::RateLimited, '429', new \DateTimeImmutable('2026-11-20 21:30:00'));
        });

        $this->tenantContext->switchTo($organization);
        $connection = $this->connections->findCurrent() ?? self::fail('Connection expected.');

        self::assertEquals(new \DateTimeImmutable('2026-11-20 20:00:00 UTC'), $connection->getSyncCursor(), 'Kept to the second, rounded down: never past an invoice not imported yet.');
        self::assertEquals(new \DateTimeImmutable('2026-11-20 21:30:00'), $connection->getSyncRetryNotBefore());
        self::assertEquals(new \DateTimeImmutable('2026-11-20 20:50:00'), $connection->getLastImportRequestedAt());
        self::assertSame(3, $connection->getLastSyncImportedCount());
        self::assertSame(1, $connection->getLastSyncSkippedCount());
    }

    /** @param (\Closure(KsefConnection): void)|null $change */
    private function connect(Organization $organization, ?\Closure $change = null): Organization
    {
        $this->tenantContext->switchTo($organization);
        $connection = KsefConnection::connect(Uuid::v7(), Uuid::v7(), 'PL', '1111111111', Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable('2026-11-10 12:00:00'));
        if (null !== $change) {
            $change($connection);
        }
        $this->connections->add($connection);
        $this->entityManager->clear();
        $this->tenantContext->reset();

        return $organization;
    }
}
