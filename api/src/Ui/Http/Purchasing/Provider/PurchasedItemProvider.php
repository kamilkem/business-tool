<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Application\Query\GetPurchasedItem\GetPurchasedItemQuery;
use App\Purchasing\Application\Query\GetPurchasedItem\PurchasedItemView;
use App\Purchasing\Domain\Exception\PurchasedItemNotFoundException;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\Purchasing\PurchasedItemResource;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * One purchased item of the organization selected by the X-Organization-Id header. An employee is
 * refused by the domain (PurchasingAccess); another organization's item is a 404.
 *
 * @implements ProviderInterface<PurchasedItemResource>
 */
final readonly class PurchasedItemProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PurchasedItemResource
    {
        $itemId = $uriVariables['id'];
        \assert(\is_string($itemId));

        /** @var PurchasedItemView|null $view */
        $view = $this->queryBus->ask(new GetPurchasedItemQuery(
            $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            $this->currentUser->id(),
            $itemId,
        ));

        return PurchasedItemResource::fromView($view ?? throw PurchasedItemNotFoundException::withId($itemId));
    }
}
