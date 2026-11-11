<?php

declare(strict_types=1);

namespace Ui\Http\Organization\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organization\Application\Command\DeactivateMember\DeactivateMemberCommand;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * Deactivates a member of the organization selected by the X-Organization-Id header
 * (Organization::deactivateMember()). The domain decides who may do it and keeps an active owner.
 *
 * @implements ProcessorInterface<null, null>
 */
final readonly class DeactivateMemberProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $this->commandBus->handle(new DeactivateMemberCommand(
            $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            $this->currentUser->id(),
            (string) $uriVariables['userId'],
        ));

        return null;
    }
}
