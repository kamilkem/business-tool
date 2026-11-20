<?php

declare(strict_types=1);

namespace App\Tests\Functional\Secret;

use App\Ksef\Domain\Model\KsefConnection;
use App\Ksef\Domain\Repository\KsefConnectionRepositoryInterface;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;
use App\Secret\Domain\Model\EncryptedValue;
use App\Secret\Domain\Model\OrganizationSecret;
use App\Secret\Domain\Model\SecretKind;
use App\Secret\Domain\Repository\OrganizationSecretRepositoryInterface;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Fixtures\OrganizationMother;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * TenantFilter and TenantAssignListener on organization_secret and ksef_connection (adr/0026 §8),
 * through the repository adapters.
 */
final class OrganizationSecretTenantFilterTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private EntityManagerInterface $entityManager;
    private TenantContextInterface $tenantContext;
    private OrganizationSecretRepositoryInterface $secrets;
    private KsefConnectionRepositoryInterface $connections;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->tenantContext = $container->get(TenantContextInterface::class);
        $this->secrets = $container->get(OrganizationSecretRepositoryInterface::class);
        $this->connections = $container->get(KsefConnectionRepositoryInterface::class);
    }

    public function test_it_stores_a_secret_for_the_current_organization_and_hides_it_from_others(): void
    {
        [$a, $b] = OrganizationFactory::createMany(2);
        $secretId = $this->addSecret($a, 'old');

        $this->select($a);
        $stored = $this->secrets->findById($secretId) ?? self::fail('Secret expected.');
        self::assertTrue($stored->getOrganization()?->getId()->equals($a->getId()));
        self::assertSame('old', $stored->getEncryptedValue()->keyId);
        self::assertCount(1, $this->secrets->findNotUnderKey('new'));

        $this->select($b);
        self::assertNull($this->secrets->findById($secretId));
        self::assertSame([], $this->secrets->findNotUnderKey('new'));

        $this->entityManager->clear();
        $this->tenantContext->reset();
        self::assertNull($this->secrets->findById($secretId));
    }

    public function test_it_lists_only_secrets_under_another_key(): void
    {
        $a = OrganizationFactory::createOne();
        $this->addSecret($a, 'old');
        $current = $this->addSecret($a, 'new');

        $this->select($a);

        self::assertSame([], array_filter($this->secrets->findNotUnderKey('new'), static fn (OrganizationSecret $s): bool => $s->getId()->equals($current)));
        self::assertCount(1, $this->secrets->findNotUnderKey('new'));
    }

    public function test_it_removes_a_secret(): void
    {
        $a = OrganizationFactory::createOne();
        $secretId = $this->addSecret($a, 'old');
        $this->select($a);

        $this->secrets->remove($this->secrets->findById($secretId) ?? self::fail('Secret expected.'));

        $this->entityManager->clear();
        self::assertNull($this->secrets->findById($secretId));
    }

    public function test_it_finds_only_the_current_organizations_ksef_connection(): void
    {
        [$a, $b] = OrganizationFactory::createMany(2);
        $this->select($a);
        $connection = KsefConnection::connect(Uuid::v7(), Uuid::v7(), 'PL', '1111111111', OrganizationMother::ownerOf($a)->getId(), MembershipRole::Owner, new \DateTimeImmutable());
        $this->connections->add($connection);
        $this->entityManager->clear();

        $this->select($a);
        self::assertTrue($this->connections->findCurrent()?->getId()->equals($connection->getId()));

        $this->select($b);
        self::assertNull($this->connections->findCurrent());
    }

    private function addSecret(Organization $organization, string $keyId): Uuid
    {
        $this->select($organization);
        $id = Uuid::v7();
        $this->secrets->add(OrganizationSecret::create(
            $id,
            SecretKind::KsefToken,
            new EncryptedValue($keyId, base64_encode('nonce'), base64_encode('ciphertext')),
            OrganizationMother::ownerOf($organization)->getId(),
            MembershipRole::Owner,
            new \DateTimeImmutable(),
        ));
        $this->entityManager->clear();
        $this->tenantContext->reset();

        return $id;
    }

    private function select(Organization $organization): void
    {
        $this->entityManager->clear();
        $this->tenantContext->switchTo(
            $this->entityManager->getReference(Organization::class, $organization->getId()) ?? throw new \LogicException('Organization expected.'),
        );
    }
}
