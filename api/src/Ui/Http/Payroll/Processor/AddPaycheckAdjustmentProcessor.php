<?php

declare(strict_types=1);

namespace Ui\Http\Payroll\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Payroll\Application\Command\AddPaycheckAdjustment\AddPaycheckAdjustmentCommand;
use App\Shared\Application\Command\CommandBusInterface;
use Symfony\Component\Uid\Uuid;
use Ui\Http\Payroll\AddPaycheckAdjustmentInput;
use Ui\Http\Payroll\PayrollRunReader;
use Ui\Http\Payroll\PayrollRunResource;
use Ui\Http\Shared\Security\CurrentUser;

/** @implements ProcessorInterface<AddPaycheckAdjustmentInput, PayrollRunResource> */
final readonly class AddPaycheckAdjustmentProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private PayrollRunReader $reader,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @param AddPaycheckAdjustmentInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PayrollRunResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $actorId = $this->currentUser->id();
        $runId = $uriVariables['id'];
        \assert(\is_string($runId));

        $this->commandBus->handle(new AddPaycheckAdjustmentCommand(
            $organizationId,
            $actorId,
            $runId,
            Uuid::v7()->toRfc4122(),
            $data->userId,
            $data->label,
            $data->amount,
        ));

        return $this->reader->read($organizationId, $actorId, $runId);
    }
}
