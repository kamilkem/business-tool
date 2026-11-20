<?php

declare(strict_types=1);

namespace Ui\Http\Payroll\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Payroll\Application\Command\RegeneratePayrollRun\RegeneratePayrollRunCommand;
use App\Shared\Application\Command\CommandBusInterface;
use Ui\Http\Payroll\PayrollRunReader;
use Ui\Http\Payroll\PayrollRunResource;
use Ui\Http\Payroll\RegeneratePayrollRunInput;
use Ui\Http\Shared\Security\CurrentUser;

/** @implements ProcessorInterface<RegeneratePayrollRunInput, PayrollRunResource> */
final readonly class RegeneratePayrollRunProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private PayrollRunReader $reader,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @param RegeneratePayrollRunInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PayrollRunResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $actorId = $this->currentUser->id();
        $runId = $uriVariables['id'];
        \assert(\is_string($runId));

        $this->commandBus->handle(new RegeneratePayrollRunCommand(
            $organizationId,
            $actorId,
            $runId,
            $data->roundingUnit?->value,
            $data->roundingDirection?->value,
        ));

        return $this->reader->read($organizationId, $actorId, $runId);
    }
}
