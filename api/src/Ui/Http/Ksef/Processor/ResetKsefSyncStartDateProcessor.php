<?php

declare(strict_types=1);

namespace Ui\Http\Ksef\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Ksef\Application\Command\ChangeKsefSyncStartDate\ChangeKsefSyncStartDateCommand;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager goes back to the default start of the KSeF import (ADR 0038 §1).
 *
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class ResetKsefSyncStartDateProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $this->commandBus->handle(new ChangeKsefSyncStartDateCommand(
            $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            $this->currentUser->id(),
            null,
        ));

        return null;
    }
}
