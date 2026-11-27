<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Command\AcceptProductProposal;

use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Purchasing\Application\PurchasingActor;
use App\Purchasing\Domain\Event\ProductCreated;
use App\Purchasing\Domain\Event\PurchasedItemsConfirmed;
use App\Purchasing\Domain\Exception\InvalidProductNameException;
use App\Purchasing\Domain\Exception\InvalidProductReferenceQuantityException;
use App\Purchasing\Domain\Exception\ProductNameTakenException;
use App\Purchasing\Domain\Exception\ProductProposalNotFoundException;
use App\Purchasing\Domain\Exception\PurchasingManagementForbiddenException;
use App\Purchasing\Domain\Exception\UnknownProductCategoryException;
use App\Purchasing\Domain\Matching\ItemReader;
use App\Purchasing\Domain\Model\Product;
use App\Purchasing\Domain\Model\ProductMeasure;
use App\Purchasing\Domain\Model\PurchasedItem;
use App\Purchasing\Domain\Repository\ProductCategoryRepositoryInterface;
use App\Purchasing\Domain\Repository\ProductRepositoryInterface;
use App\Purchasing\Domain\Repository\PurchasedItemRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Event\EventPublisherInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Creates the product of a proposal and links the proposal's items to it (ADR 0044). The product is
 * created with the actor's role check, like `POST /products`, and the items are linked as a person's
 * confirmation: `confirmed`, with the package size the text strongly states (ItemReading::packageFor()),
 * so the aliases are learned and the other items matched again by the events.
 *
 * The items are changed first and the product is added last: adding flushes the unit of work, so the
 * product and the links are one transaction, and a name that is taken changes nothing.
 */
final readonly class AcceptProductProposalHandler implements CommandHandlerInterface
{
    public function __construct(
        private PurchasedItemRepositoryInterface $items,
        private ProductRepositoryInterface $products,
        private ProductCategoryRepositoryInterface $categories,
        private QueryBusInterface $queryBus,
        private EventPublisherInterface $events,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException            when the actor is not an active member of the organization
     * @throws PurchasingManagementForbiddenException   when the actor is not an owner or manager
     * @throws ProductProposalNotFoundException         when no open item proposes it (also another organization's)
     * @throws UnknownProductCategoryException          when the category is not one of the organization's
     * @throws InvalidProductNameException              when the name is blank or too long
     * @throws InvalidProductReferenceQuantityException when the reference quantity is not positive, or is given for `none`
     * @throws ProductNameTakenException                when a product already has the name
     */
    public function __invoke(AcceptProductProposalCommand $command): void
    {
        $actor = PurchasingActor::resolve($this->queryBus, $command->organizationId, $command->actorId);

        $items = $this->items->findByProposalKey($command->proposalKey);
        if ([] === $items) {
            throw ProductProposalNotFoundException::withKey($command->proposalKey);
        }

        $category = null;
        if (null !== $command->categoryId) {
            $category = Uuid::isValid($command->categoryId) ? $this->categories->findById(Uuid::fromString($command->categoryId)) : null;
            $category ??= throw UnknownProductCategoryException::withId($command->categoryId);
        }

        $measure = ProductMeasure::from($command->measure);
        $product = Product::create(
            Uuid::fromString($command->productId),
            $command->name,
            $category,
            $measure,
            $command->referenceQuantity,
            false,
            $this->products->findByName($command->name),
            $actor->id,
            $actor->role,
            $this->clock->now(),
        );

        foreach ($items as $item) {
            $package = $item->getPackage() ?? ItemReader::read($item->getName(), $item->getUnit())->packageFor($measure);
            $item->link($product, $package?->single, $actor->id, $actor->role, $package?->bundleCount);
        }

        $this->products->add($product);
        $this->events->publish(new ProductCreated($command->organizationId, $command->productId));
        $this->events->publish(new PurchasedItemsConfirmed($command->organizationId, array_map(static fn (PurchasedItem $item): string => $item->getId()->toRfc4122(), $items)));
    }
}
