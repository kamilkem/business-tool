<?php

declare(strict_types=1);

namespace Ui\Http\Income\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Income\Application\Command\ReorderPaymentMethods\ReorderPaymentMethodsCommand;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use Ui\Http\Income\ReorderPaymentMethodsInput;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager puts the active payment methods of the organization selected by the
 * X-Organization-Id header in a new order (ADR 0030 §3).
 *
 * @implements ProcessorInterface<ReorderPaymentMethodsInput, null>
 */
final readonly class ReorderPaymentMethodsProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @param ReorderPaymentMethodsInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $this->commandBus->handle(new ReorderPaymentMethodsCommand(
            organizationId: $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            actorId: $this->currentUser->id(),
            paymentMethodIds: $data->paymentMethodIds,
        ));

        return null;
    }
}
