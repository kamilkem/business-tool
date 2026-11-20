<?php

declare(strict_types=1);

namespace Ui\Http\Payroll\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Payroll\Application\Command\ChangePaycheckAdjustment\ChangePaycheckAdjustmentCommand;
use App\Shared\Application\Command\CommandBusInterface;
use Ui\Http\Payroll\PayrollRunReader;
use Ui\Http\Payroll\PayrollRunResource;
use Ui\Http\Payroll\UpdatePaycheckAdjustmentInput;
use Ui\Http\Shared\Security\CurrentUser;

/** @implements ProcessorInterface<UpdatePaycheckAdjustmentInput, PayrollRunResource> */
final readonly class UpdatePaycheckAdjustmentProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private PayrollRunReader $reader,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @param UpdatePaycheckAdjustmentInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PayrollRunResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $actorId = $this->currentUser->id();
        $runId = $uriVariables['id'];
        $adjustmentId = $uriVariables['adjustmentId'];
        \assert(\is_string($runId) && \is_string($adjustmentId) && null !== $data->label && null !== $data->amount);

        $this->commandBus->handle(new ChangePaycheckAdjustmentCommand($organizationId, $actorId, $runId, $adjustmentId, $data->label, $data->amount));

        return $this->reader->read($organizationId, $actorId, $runId);
    }
}
