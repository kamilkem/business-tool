<?php

declare(strict_types=1);

namespace Ui\Http\Income\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Income\Application\Command\DeletePaymentMethod\DeletePaymentMethodCommand;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager deletes a payment method of the organization selected by the X-Organization-Id
 * header that no revenue entry has used (ADR 0030 §4).
 *
 * @implements ProcessorInterface<null, null>
 */
final readonly class DeletePaymentMethodProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $paymentMethodId = $uriVariables['id'];
        \assert(\is_string($paymentMethodId));

        $this->commandBus->handle(new DeletePaymentMethodCommand(
            organizationId: $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            actorId: $this->currentUser->id(),
            paymentMethodId: $paymentMethodId,
        ));

        return null;
    }
}
