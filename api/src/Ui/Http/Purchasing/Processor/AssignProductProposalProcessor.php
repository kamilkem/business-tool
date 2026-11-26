<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Application\Command\AssignProductProposal\AssignProductProposalCommand;
use App\Purchasing\Domain\Proposal\ProposalGroup;
use App\Shared\Application\Command\CommandBusInterface;
use Ui\Http\Purchasing\AssignProductProposalInput;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager links the items of a proposal to a product that exists; the answer has no body.
 *
 * @implements ProcessorInterface<AssignProductProposalInput, null>
 */
final readonly class AssignProductProposalProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @param AssignProductProposalInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $id = $uriVariables['id'];
        \assert(\is_string($id));

        $this->commandBus->handle(new AssignProductProposalCommand(
            $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            $this->currentUser->id(),
            ProposalGroup::keyOf($id),
            $data->productId,
        ));

        return null;
    }
}
