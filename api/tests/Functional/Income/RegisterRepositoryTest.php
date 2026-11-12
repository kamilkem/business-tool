<?php

declare(strict_types=1);

namespace App\Tests\Functional\Income;

use App\Income\Domain\Exception\RegisterNameTakenException;
use App\Income\Domain\Model\Register;
use App\Income\Domain\Repository\RegisterRepositoryInterface;
use App\Income\Infrastructure\Doctrine\Orm\Repository\RegisterRepository;
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

/** RegisterRepository against PostgreSQL: the unique name per organization and the TenantFilter. */
#[CoversClass(RegisterRepository::class)]
final class RegisterRepositoryTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private EntityManagerInterface $entityManager;
    private TenantContextInterface $tenantContext;
    private RegisterRepositoryInterface $registers;

    protected function setUp(): void
    {
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->tenantContext = self::getContainer()->get(TenantContextInterface::class);
        $this->registers = self::getContainer()->get(RegisterRepositoryInterface::class);
    }

    public function test_it_stores_a_register_in_the_current_organization(): void
    {
        $organization = OrganizationFactory::createOne();
        $this->tenantContext->switchTo($organization);
        $register = $this->register('Bar');
        $this->registers->add($register);
        $this->entityManager->clear();

        $found = $this->registers->findById($register->getId());
        self::assertNotNull($found);
        self::assertSame('Bar', $found->getName());
        self::assertTrue($found->isActive());
        self::assertTrue($found->getOrganization()?->getId()->equals($organization->getId()));
        self::assertSame($register->getId()->toRfc4122(), $this->registers->findByName('Bar')?->getId()->toRfc4122());
        self::assertNull($this->registers->findByName('bar'));
    }

    public function test_it_rejects_a_second_register_with_the_same_name_in_the_organization(): void
    {
        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        $this->registers->add($this->register('Bar'));

        $this->expectException(RegisterNameTakenException::class);

        // A concurrent write that did not see the first register: only the constraint can stop it.
        $this->registers->add($this->register('Bar'));
    }

    public function test_it_rejects_a_rename_to_a_name_stored_in_the_meantime(): void
    {
        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        $this->registers->add($this->register('Bar'));
        $kitchen = $this->register('Kitchen');
        $this->registers->add($kitchen);

        $kitchen->rename('Bar', null, Uuid::v7(), MembershipRole::Owner);

        $this->expectException(RegisterNameTakenException::class);

        $this->registers->save($kitchen);
    }

    public function test_it_allows_the_same_name_in_another_organization(): void
    {
        [$a, $b] = OrganizationFactory::createMany(2);
        $this->tenantContext->switchTo($a);
        $this->registers->add($this->register('Bar'));
        $this->tenantContext->switchTo($b);
        $this->registers->add($this->register('Bar'));
        $this->entityManager->clear();

        self::assertCount(1, $this->registers->findAll());
    }

    public function test_it_lists_active_registers_by_name_and_the_deactivated_ones_last_on_request(): void
    {
        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        $terrace = $this->register('Terrace');
        $terrace->deactivate(Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable('2026-11-01'));
        foreach ([$this->register('Kitchen'), $terrace, $this->register('Bar'), $this->register('Annex')] as $register) {
            $this->registers->add($register);
        }
        $this->registers->findByName('Annex')?->deactivate(Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable('2026-11-02'));
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertSame(['Bar', 'Kitchen'], $this->names($this->registers->findAll()));
        self::assertSame(['Bar', 'Kitchen', 'Annex', 'Terrace'], $this->names($this->registers->findAll(includeDeactivated: true)));
    }

    public function test_it_does_not_show_another_organizations_registers(): void
    {
        [$a, $b] = OrganizationFactory::createMany(2);
        $this->switchTo($b);
        $foreign = $this->register('Bar');
        $this->registers->add($foreign);
        $this->entityManager->clear();

        $this->switchTo($a);

        self::assertNull($this->registers->findById($foreign->getId()));
        self::assertNull($this->registers->findByName('Bar'));
        self::assertSame([], $this->registers->findAll(includeDeactivated: true));
    }

    private function register(string $name): Register
    {
        return Register::create(Uuid::v7(), $name, null, Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable('2026-10-01'));
    }

    private function switchTo(Organization $organization): void
    {
        $this->tenantContext->switchTo($organization);
    }

    /**
     * @param list<Register> $registers
     *
     * @return list<string>
     */
    private function names(array $registers): array
    {
        return array_map(static fn (Register $register): string => $register->getName(), $registers);
    }
}
