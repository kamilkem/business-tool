<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\CashFlow\Application\Command\DeleteDayClose\DeleteDayCloseCommand;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager deletes an end-of-day close of the organization selected by the
 * X-Organization-Id header (ADR 0033 §7).
 *
 * @implements ProcessorInterface<null, null>
 */
final readonly class DeleteDayCloseProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $dayCloseId = $uriVariables['id'];
        \assert(\is_string($dayCloseId));

        $this->commandBus->handle(new DeleteDayCloseCommand(
            organizationId: $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            actorId: $this->currentUser->id(),
            dayCloseId: $dayCloseId,
        ));

        return null;
    }
}
