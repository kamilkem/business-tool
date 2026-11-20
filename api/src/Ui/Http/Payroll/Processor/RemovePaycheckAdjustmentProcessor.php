<?php

declare(strict_types=1);

namespace Ui\Http\Payroll\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Payroll\Application\Command\RemovePaycheckAdjustment\RemovePaycheckAdjustmentCommand;
use App\Shared\Application\Command\CommandBusInterface;
use Ui\Http\Shared\Security\CurrentUser;

/** @implements ProcessorInterface<null, null> */
final readonly class RemovePaycheckAdjustmentProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $runId = $uriVariables['id'];
        $adjustmentId = $uriVariables['adjustmentId'];
        \assert(\is_string($runId) && \is_string($adjustmentId));

        $this->commandBus->handle(new RemovePaycheckAdjustmentCommand(
            $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            $this->currentUser->id(),
            $runId,
            $adjustmentId,
        ));

        return null;
    }
}
