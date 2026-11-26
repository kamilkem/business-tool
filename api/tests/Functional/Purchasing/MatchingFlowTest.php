<?php

declare(strict_types=1);

namespace App\Tests\Functional\Purchasing;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Application\Command\LearnProductAliases\LearnProductAliasesCommand;
use App\Purchasing\Application\Command\MatchPurchasedItems\MatchPurchasedItemsCommand;
use App\Purchasing\Application\Command\RecordPurchasedItems\RecordPurchasedItemsCommand;
use App\Purchasing\Application\Job\MatchPurchasedItems\MatchPurchasedItemsJob;
use App\Purchasing\Domain\Matching\MatchScope;
use App\Purchasing\Domain\Model\Product;
use App\Purchasing\Domain\Model\ProductMeasure;
use App\Purchasing\Domain\Model\PurchasedItem;
use App\Purchasing\Domain\Model\PurchasedItemStatus;
use App\Purchasing\Domain\Repository\ProductRepositoryInterface;
use App\Purchasing\Domain\Repository\PurchasedItemRepositoryInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Fixtures\InMemoryItemInterpreter;
use App\Tests\Fixtures\PurchasedItemMother;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Automatic matching through the real buses, repositories and PostgreSQL, with the in-memory
 * interpreter (ADR 0042): recording items queues the job, the model's answer links an item, a person's
 * confirmation teaches aliases, and the aliases then settle the next item without the model.
 */
final class MatchingFlowTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private TenantContextInterface $tenantContext;
    private CommandBusInterface $commandBus;
    private PurchasedItemRepositoryInterface $items;
    private ProductRepositoryInterface $products;
    private InMemoryItemInterpreter $interpreter;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $this->tenantContext = $container->get(TenantContextInterface::class);
        $this->commandBus = $container->get(CommandBusInterface::class);
        $this->items = $container->get(PurchasedItemRepositoryInterface::class);
        $this->products = $container->get(ProductRepositoryInterface::class);
        $interpreter = self::testService(InMemoryItemInterpreter::class);
        self::assertInstanceOf(InMemoryItemInterpreter::class, $interpreter);
        $this->interpreter = $interpreter;
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    public function test_it_queues_matching_when_items_are_recorded_and_links_what_the_model_recognizes(): void
    {
        $organization = OrganizationFactory::createOne();
        $this->tenantContext->switchTo($organization);
        $jack = $this->product('Jack Daniel\'s');
        $invoiceId = Uuid::v7()->toRfc4122();

        $this->commandBus->handle(PurchasedItemMother::command($organization->getId()->toRfc4122(), $invoiceId, [
            PurchasedItemMother::recorded(1, ['name' => 'J.DANIELS 1L', 'sellerCode' => null, 'gtin' => null, 'unit' => 'szt.']),
            PurchasedItemMother::recorded(2, ['name' => 'Serwetki', 'sellerCode' => null, 'gtin' => null]),
        ]));

        $queued = $this->queuedJobs();
        self::assertCount(1, $queued);
        self::assertEquals(new MatchPurchasedItemsJob($organization->getId()->toRfc4122(), MatchScope::NotYetMatched->value, true), $queued[0]);

        $this->interpreter->answer('J.DANIELS 1L', $jack->getId()->toRfc4122());
        $ids = array_map(static fn (PurchasedItem $item): string => $item->getId()->toRfc4122(), $this->items->findByInvoice(Uuid::fromString($invoiceId)));
        $this->commandBus->handle(new MatchPurchasedItemsCommand($organization->getId()->toRfc4122(), $ids, true));
        $this->entityManager->clear();

        [$matched, $unmatched] = $this->items->findByInvoice(Uuid::fromString($invoiceId));
        self::assertSame([PurchasedItemStatus::Assigned, '1'], [$matched->getStatus(), $matched->getPackageSize()]);
        self::assertTrue($matched->getProduct()?->getId()->equals($jack->getId()));
        self::assertSame(PurchasedItemStatus::Unassigned, $unmatched->getStatus());
        self::assertNotNull($unmatched->getMatchedAt());
        self::assertSame([], $this->items->findMatchableIds(MatchScope::NotYetMatched, null, 10), 'The model went through both.');
        self::assertCount(1, $this->items->findMatchableIds(MatchScope::Unassigned, null, 10));
        self::assertCount(2, $this->items->findMatchableIds(MatchScope::AllMatchable, null, 10));
    }

    public function test_a_confirmation_teaches_aliases_that_settle_the_next_invoice_without_the_model(): void
    {
        $organization = OrganizationFactory::createOne();
        $organizationId = $organization->getId()->toRfc4122();
        $this->tenantContext->switchTo($organization);
        $jack = $this->product('Jack Daniel\'s');
        $this->commandBus->handle(PurchasedItemMother::command($organizationId, null, [
            PurchasedItemMother::recorded(1, ['name' => 'JACK DANIELS 0,7L 40%', 'sellerCode' => 'JD-07', 'gtin' => '5099873089798']),
        ]));
        [$first] = $this->items->findMatchableIds(MatchScope::AllMatchable, null, 10);
        $item = $this->items->findById($first) ?? throw new \LogicException('Item expected.');

        // What the person's confirmation does (the Ui handlers are tested apart): link, then learn.
        $item->link($jack, '0.7', Uuid::v7(), MembershipRole::Owner);
        $this->items->save($item);
        $this->commandBus->handle(new LearnProductAliasesCommand($organizationId, [$first->toRfc4122()]));
        $this->entityManager->clear();
        $this->tenantContext->switchTo($this->entityManager->find(Organization::class, $organization->getId()) ?? throw new \LogicException('Organization expected.'));

        $this->commandBus->handle(PurchasedItemMother::command($organizationId, null, [
            PurchasedItemMother::recorded(1, ['name' => 'Whisky Tennessee Jack 0.7', 'sellerCode' => 'JD-07', 'gtin' => null, 'unit' => 'szt.']),
        ]));
        $ids = array_map(static fn (\Symfony\Component\Uid\Uuid $id): string => $id->toRfc4122(), $this->items->findMatchableIds(MatchScope::AllMatchable, null, 10));
        self::assertCount(1, $ids, 'The confirmed item is not matchable.');
        $this->commandBus->handle(new MatchPurchasedItemsCommand($organizationId, $ids, true));
        $this->entityManager->clear();

        $sibling = $this->items->findById(Uuid::fromString($ids[0])) ?? throw new \LogicException('Item expected.');
        self::assertSame(PurchasedItemStatus::Assigned, $sibling->getStatus());
        self::assertTrue($sibling->getProduct()?->getId()->equals($jack->getId()));
        self::assertSame('0.7', $sibling->getPackageSize());
        self::assertSame([], $this->interpreter->calls, 'The owner\'s alias settled it: no call to the model.');
        $confirmed = $this->items->findById($first);
        self::assertSame(PurchasedItemStatus::Confirmed, $confirmed?->getStatus());
    }

    public function test_it_matches_only_inside_its_organization(): void
    {
        $mine = OrganizationFactory::createOne();
        $this->tenantContext->switchTo($mine);
        $jack = $this->product('Jack Daniel\'s');
        $this->commandBus->handle(PurchasedItemMother::command($mine->getId()->toRfc4122()));
        $this->interpreter->answer('Jack Daniel\'s 0,7l', $jack->getId()->toRfc4122());

        $other = OrganizationFactory::createOne();
        $this->tenantContext->switchTo($other);

        self::assertSame([], $this->items->findMatchableIds(MatchScope::AllMatchable, null, 10));
        $this->expectException(\LogicException::class);
        $this->commandBus->handle(new MatchPurchasedItemsCommand($mine->getId()->toRfc4122(), [], true));
    }

    /** A service registered for the test env only, which static analysis (dev container) does not know. */
    private static function testService(string $id): object
    {
        return self::getContainer()->get($id);
    }

    private function product(string $name): Product
    {
        $product = Product::create(Uuid::v7(), $name, null, ProductMeasure::Volume, null, false, null, Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable('2026-11-01 10:00:00'));
        $this->products->add($product);

        return $product;
    }

    /** @return list<object> */
    private function queuedJobs(): array
    {
        $transport = self::getContainer()->get('messenger.transport.jobs');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values(array_map(static fn ($envelope): object => $envelope->getMessage(), [...$transport->getSent()]));
    }
}
