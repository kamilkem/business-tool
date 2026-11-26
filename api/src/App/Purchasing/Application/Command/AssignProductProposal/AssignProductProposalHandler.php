<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Command\AssignProductProposal;

use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Purchasing\Application\PurchasingActor;
use App\Purchasing\Domain\Event\PurchasedItemsConfirmed;
use App\Purchasing\Domain\Exception\ProductProposalNotFoundException;
use App\Purchasing\Domain\Exception\PurchasedItemProductNotFoundException;
use App\Purchasing\Domain\Exception\PurchasingManagementForbiddenException;
use App\Purchasing\Domain\Matching\ItemReader;
use App\Purchasing\Domain\Model\PurchasedItem;
use App\Purchasing\Domain\Repository\ProductRepositoryInterface;
use App\Purchasing\Domain\Repository\PurchasedItemRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Event\EventPublisherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Links the items of a proposal to an existing product in one flush, as a person's confirmation: the
 * aliases are learned from them (ADR 0044). Each item keeps its package size or gets the one its text
 * strongly states.
 */
final readonly class AssignProductProposalHandler implements CommandHandlerInterface
{
    public function __construct(
        private PurchasedItemRepositoryInterface $items,
        private ProductRepositoryInterface $products,
        private QueryBusInterface $queryBus,
        private EventPublisherInterface $events,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException         when the actor is not an active member of the organization
     * @throws PurchasingManagementForbiddenException when the actor is not an owner or manager
     * @throws PurchasedItemProductNotFoundException  when the product is not one of the organization's
     * @throws ProductProposalNotFoundException       when no open item proposes it
     */
    public function __invoke(AssignProductProposalCommand $command): void
    {
        $actor = PurchasingActor::resolve($this->queryBus, $command->organizationId, $command->actorId);

        $product = Uuid::isValid($command->productId) ? $this->products->findById(Uuid::fromString($command->productId)) : null;
        $product ??= throw PurchasedItemProductNotFoundException::withId($command->productId);
        $items = $this->items->findByProposalKey($command->proposalKey);
        if ([] === $items) {
            throw ProductProposalNotFoundException::withKey($command->proposalKey);
        }

        foreach ($items as $item) {
            $size = $item->getPackageSize() ?? ItemReader::read($item->getName(), $item->getUnit())->packageSizeFor($product->getMeasure());
            $item->link($product, $size, $actor->id, $actor->role);
        }

        $this->items->saveAll($items);
        $this->events->publish(new PurchasedItemsConfirmed($command->organizationId, array_map(static fn (PurchasedItem $item): string => $item->getId()->toRfc4122(), $items)));
    }
}
