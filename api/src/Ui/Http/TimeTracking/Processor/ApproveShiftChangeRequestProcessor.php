<?php

declare(strict_types=1);

namespace Ui\Http\TimeTracking\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\TimeTracking\Application\Command\ApplyShiftChangeRequest\ApplyShiftChangeRequestCommand;
use App\TimeTracking\Application\Query\GetShiftChangeRequest\GetShiftChangeRequestQuery;
use App\TimeTracking\Application\Query\GetShiftChangeRequest\ShiftChangeRequestView;
use Symfony\Component\Uid\Uuid;
use Ui\Http\Shared\Security\CurrentUser;
use Ui\Http\TimeTracking\ShiftChangeRequestResource;

/**
 * An owner or manager approves a shift change request in the organization selected by the
 * X-Organization-Id header. The command applies the change to the shift; the request is approved
 * from the ShiftChangeRequestApplied event, in the same request.
 *
 * @implements ProcessorInterface<null, ShiftChangeRequestResource>
 */
final readonly class ApproveShiftChangeRequestProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ShiftChangeRequestResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $actorId = $this->currentUser->id();
        $requestId = (string) $uriVariables['id'];

        $this->commandBus->handle(new ApplyShiftChangeRequestCommand(
            organizationId: $organizationId,
            actorId: $actorId,
            requestId: $requestId,
            newShiftId: Uuid::v7()->toRfc4122(),
        ));

        /** @var ShiftChangeRequestView $view */
        $view = $this->queryBus->ask(new GetShiftChangeRequestQuery($organizationId, $actorId, $requestId));

        return ShiftChangeRequestResource::fromView($view);
    }
}
