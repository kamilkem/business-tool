<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Application\Command\AcceptProductProposal\AcceptProductProposalCommand;
use App\Purchasing\Domain\Proposal\ProposalGroup;
use App\Shared\Application\Command\CommandBusInterface;
use Symfony\Component\Uid\Uuid;
use Ui\Http\Purchasing\AcceptProductProposalInput;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager creates the product of a proposal of the organization selected by the
 * X-Organization-Id header and links its items to it; the answer has no body. This is the only way a
 * proposed product gets created: always a person's command (ADR 0044).
 *
 * @implements ProcessorInterface<AcceptProductProposalInput, null>
 */
final readonly class AcceptProductProposalProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @param AcceptProductProposalInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $id = $uriVariables['id'];
        \assert(\is_string($id));

        $this->commandBus->handle(new AcceptProductProposalCommand(
            $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            $this->currentUser->id(),
            ProposalGroup::keyOf($id),
            Uuid::v7()->toRfc4122(),
            $data->name,
            $data->measure->value,
            $data->categoryId,
            $data->referenceQuantity,
        ));

        return null;
    }
}
