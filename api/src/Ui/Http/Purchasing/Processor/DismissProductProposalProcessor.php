<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Application\Command\DismissProductProposal\DismissProductProposalCommand;
use App\Purchasing\Domain\Proposal\ProposalGroup;
use App\Shared\Application\Command\CommandBusInterface;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager dismisses a proposal of the organization selected by the X-Organization-Id header;
 * the answer has no body.
 *
 * @implements ProcessorInterface<null, null>
 */
final readonly class DismissProductProposalProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $id = $uriVariables['id'];
        \assert(\is_string($id));

        $this->commandBus->handle(new DismissProductProposalCommand(
            $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            $this->currentUser->id(),
            ProposalGroup::keyOf($id),
        ));

        return null;
    }
}
