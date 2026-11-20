<?php

declare(strict_types=1);

namespace Ui\Http\Payroll\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Payroll\Application\Command\GeneratePayrollRun\GeneratePayrollRunCommand;
use App\Shared\Application\Command\CommandBusInterface;
use Symfony\Component\Uid\Uuid;
use Ui\Http\Payroll\GeneratePayrollRunInput;
use Ui\Http\Payroll\PayrollRunReader;
use Ui\Http\Payroll\PayrollRunResource;
use Ui\Http\Shared\Security\CurrentUser;

/** @implements ProcessorInterface<GeneratePayrollRunInput, PayrollRunResource> */
final readonly class GeneratePayrollRunProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private PayrollRunReader $reader,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @param GeneratePayrollRunInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PayrollRunResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $actorId = $this->currentUser->id();
        $runId = Uuid::v7()->toRfc4122();

        $this->commandBus->handle(new GeneratePayrollRunCommand(
            $organizationId,
            $actorId,
            $runId,
            $data->month,
            $data->roundingUnit->value,
            $data->roundingDirection->value,
        ));

        return $this->reader->read($organizationId, $actorId, $runId);
    }
}
