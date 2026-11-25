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
use Ui\Http\Purchasing\ChangePurchasedItemInput;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * Pre-fills the merge-patch input with the item's current product and package size; API Platform
 * then applies the request body onto it. An employee is refused here (403) and an item that is not
 * the organization's is a 404, before the body is read.
 *
 * @implements ProviderInterface<ChangePurchasedItemInput>
 */
final readonly class ChangePurchasedItemInputProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ChangePurchasedItemInput
    {
        $itemId = $uriVariables['id'];
        \assert(\is_string($itemId));

        /** @var PurchasedItemView|null $view */
        $view = $this->queryBus->ask(new GetPurchasedItemQuery(
            $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            $this->currentUser->id(),
            $itemId,
        ));

        return ChangePurchasedItemInput::fromView($view ?? throw PurchasedItemNotFoundException::withId($itemId));
    }
}
