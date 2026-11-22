<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\CashFlow\Application\Command\DeleteDenomination\DeleteDenominationCommand;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager deletes a denomination of the organization selected by the X-Organization-Id
 * header that no close or movement uses (ADR 0033 §2).
 *
 * @implements ProcessorInterface<null, null>
 */
final readonly class DeleteDenominationProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $denominationId = $uriVariables['id'];
        \assert(\is_string($denominationId));

        $this->commandBus->handle(new DeleteDenominationCommand(
            organizationId: $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            actorId: $this->currentUser->id(),
            denominationId: $denominationId,
        ));

        return null;
    }
}
