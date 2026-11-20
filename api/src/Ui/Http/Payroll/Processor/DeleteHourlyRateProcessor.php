<?php

declare(strict_types=1);

namespace Ui\Http\Payroll\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Payroll\Application\Command\DeleteHourlyRate\DeleteHourlyRateCommand;
use App\Shared\Application\Command\CommandBusInterface;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager deletes a hourly rate of the organization selected by the X-Organization-Id header.
 *
 * @implements ProcessorInterface<null, null>
 */
final readonly class DeleteHourlyRateProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $rateId = $uriVariables['id'];
        \assert(\is_string($rateId));

        $this->commandBus->handle(new DeleteHourlyRateCommand(
            organizationId: $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            actorId: $this->currentUser->id(),
            rateId: $rateId,
        ));

        return null;
    }
}
