<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Application\Command\AssignPurchasedItems\AssignPurchasedItemsCommand;
use App\Shared\Application\Command\CommandBusInterface;
use Ui\Http\Purchasing\AssignPurchasedItemsInput;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager links purchased items of the organization selected by the X-Organization-Id
 * header to a product, all or none; the answer has no body.
 *
 * @implements ProcessorInterface<AssignPurchasedItemsInput, null>
 */
final readonly class AssignPurchasedItemsProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @param AssignPurchasedItemsInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $this->commandBus->handle(new AssignPurchasedItemsCommand(
            $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            $this->currentUser->id(),
            $data->itemIds,
            $data->productId,
            $data->packageSize,
        ));

        return null;
    }
}
