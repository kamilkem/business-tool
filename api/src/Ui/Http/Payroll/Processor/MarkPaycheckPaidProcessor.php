<?php

declare(strict_types=1);

namespace Ui\Http\Payroll\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Payroll\Application\Command\MarkPaycheckPaid\MarkPaycheckPaidCommand;
use App\Shared\Application\Command\CommandBusInterface;
use Ui\Http\Payroll\MarkPaycheckPaidInput;
use Ui\Http\Payroll\PayrollRunReader;
use Ui\Http\Payroll\PayrollRunResource;
use Ui\Http\Shared\Security\CurrentUser;

/** @implements ProcessorInterface<MarkPaycheckPaidInput, PayrollRunResource> */
final readonly class MarkPaycheckPaidProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private PayrollRunReader $reader,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @param MarkPaycheckPaidInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PayrollRunResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $actorId = $this->currentUser->id();
        $runId = $uriVariables['id'];
        $paycheckId = $uriVariables['paycheckId'];
        \assert(\is_string($runId) && \is_string($paycheckId));

        $this->commandBus->handle(new MarkPaycheckPaidCommand($organizationId, $actorId, $runId, $paycheckId, $data->paidOn, $data->method->value));

        return $this->reader->read($organizationId, $actorId, $runId);
    }
}
