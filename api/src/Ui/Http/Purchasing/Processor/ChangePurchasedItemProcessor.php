<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Application\Command\ChangePurchasedItem\ChangePurchasedItemCommand;
use App\Purchasing\Application\Query\GetPurchasedItem\GetPurchasedItemQuery;
use App\Purchasing\Application\Query\GetPurchasedItem\PurchasedItemView;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\Purchasing\ChangePurchasedItemInput;
use Ui\Http\Purchasing\PurchasedItemResource;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager links a purchased item of the organization selected by the X-Organization-Id
 * header to a product, or takes it off, and sets its package size.
 *
 * @implements ProcessorInterface<ChangePurchasedItemInput, PurchasedItemResource>
 */
final readonly class ChangePurchasedItemProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @param ChangePurchasedItemInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PurchasedItemResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $actorId = $this->currentUser->id();
        $itemId = $uriVariables['id'];
        \assert(\is_string($itemId));

        $this->commandBus->handle(new ChangePurchasedItemCommand($organizationId, $actorId, $itemId, $data->productId, $data->packageSize));

        /** @var PurchasedItemView $view */
        $view = $this->queryBus->ask(new GetPurchasedItemQuery($organizationId, $actorId, $itemId));

        return PurchasedItemResource::fromView($view);
    }
}
