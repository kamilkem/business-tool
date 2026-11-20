<?php

declare(strict_types=1);

namespace Ui\Http\Expenses\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Expenses\Application\Command\DeletePurchaseInvoice\DeletePurchaseInvoiceCommand;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager deletes an expense entered by hand in the organization selected by the
 * X-Organization-Id header; no trace is kept (#54).
 *
 * @implements ProcessorInterface<null, null>
 */
final readonly class DeleteExpenseProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $invoiceId = $uriVariables['id'];
        \assert(\is_string($invoiceId));

        $this->commandBus->handle(new DeletePurchaseInvoiceCommand(
            organizationId: $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            actorId: $this->currentUser->id(),
            invoiceId: $invoiceId,
        ));

        return null;
    }
}
