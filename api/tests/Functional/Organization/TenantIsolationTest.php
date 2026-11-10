<?php

declare(strict_types=1);

namespace App\Tests\Functional\Organization;

use App\Organization\Domain\Exception\NoOrganizationSelectedException;
use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Fixtures\Entity\TenantNote;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * TenantFilter and TenantAssignListener against the database, through the test-only TenantNote
 * entity. Header handling is covered by tests/Api/Organization/TenantHeaderApiTest.
 */
final class TenantIsolationTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private EntityManagerInterface $entityManager;
    private TenantContextInterface $tenantContext;

    protected function setUp(): void
    {
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->tenantContext = self::getContainer()->get(TenantContextInterface::class);
    }

    public function test_it_only_returns_rows_of_the_current_organization(): void
    {
        [$a, $b] = OrganizationFactory::createMany(2);
        $this->createNote($a, 'from A');
        $this->createNote($b, 'from B');

        $this->tenantContext->switchTo($this->reference($a));

        self::assertSame(['from A'], $this->allTexts());
    }

    public function test_it_returns_nothing_without_a_selected_organization(): void
    {
        $this->createNote(OrganizationFactory::createOne(), 'from A');

        self::assertSame([], $this->allTexts());
    }

    public function test_it_does_not_find_another_organizations_row_by_id(): void
    {
        [$a, $b] = OrganizationFactory::createMany(2);
        $foreign = $this->createNote($b, 'secret');

        $this->tenantContext->switchTo($this->reference($a));

        self::assertNull($this->entityManager->getRepository(TenantNote::class)->findOneBy(['id' => $foreign->id]));
    }

    public function test_it_assigns_the_current_organization_on_persist(): void
    {
        $a = OrganizationFactory::createOne();
        $this->tenantContext->switchTo($this->reference($a));

        $note = new TenantNote('new');
        $this->entityManager->persist($note);
        $this->entityManager->flush();

        self::assertTrue($note->getOrganization()?->getId()->equals($a->getId()));
    }

    public function test_it_refuses_to_persist_without_a_selected_organization(): void
    {
        $this->expectException(NoOrganizationSelectedException::class);

        $this->entityManager->persist(new TenantNote('orphan'));
    }

    private function createNote(Organization $organization, string $text): TenantNote
    {
        $this->tenantContext->switchTo($this->reference($organization));
        $note = new TenantNote($text);
        $this->entityManager->persist($note);
        $this->entityManager->flush();
        $this->entityManager->clear();
        $this->tenantContext->reset();

        return $note;
    }

    /** @return list<string> */
    private function allTexts(): array
    {
        $notes = $this->entityManager->getRepository(TenantNote::class)->findAll();

        return array_map(static fn (TenantNote $note): string => $note->text, $notes);
    }

    private function reference(Organization $organization): Organization
    {
        return $this->entityManager->getReference(Organization::class, $organization->getId())
            ?? throw new \LogicException();
    }
}
