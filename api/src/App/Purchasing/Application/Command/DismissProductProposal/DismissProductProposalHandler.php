<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Command\DismissProductProposal;

use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Purchasing\Application\PurchasingActor;
use App\Purchasing\Domain\Exception\ProductProposalNotFoundException;
use App\Purchasing\Domain\Exception\PurchasingManagementForbiddenException;
use App\Purchasing\Domain\Repository\PurchasedItemRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;

final readonly class DismissProductProposalHandler implements CommandHandlerInterface
{
    public function __construct(
        private PurchasedItemRepositoryInterface $items,
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException         when the actor is not an active member of the organization
     * @throws PurchasingManagementForbiddenException when the actor is not an owner or manager
     * @throws ProductProposalNotFoundException       when no open item proposes it
     */
    public function __invoke(DismissProductProposalCommand $command): void
    {
        $actor = PurchasingActor::resolve($this->queryBus, $command->organizationId, $command->actorId);

        $items = $this->items->findByProposalKey($command->proposalKey);
        if ([] === $items) {
            throw ProductProposalNotFoundException::withKey($command->proposalKey);
        }
        foreach ($items as $item) {
            $item->dismissProposal($actor->id, $actor->role);
        }

        $this->items->saveAll($items);
    }
}
