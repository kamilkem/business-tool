<?php

declare(strict_types=1);

namespace Ui\Http\TimeTracking\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\TimeTracking\Application\Command\SubmitShiftChangeRequest\SubmitShiftChangeRequestCommand;
use App\TimeTracking\Application\Query\GetShiftChangeRequest\GetShiftChangeRequestQuery;
use App\TimeTracking\Application\Query\GetShiftChangeRequest\ShiftChangeRequestView;
use Symfony\Component\Uid\Uuid;
use Ui\Http\Shared\Security\CurrentUser;
use Ui\Http\TimeTracking\ShiftChangeRequestResource;
use Ui\Http\TimeTracking\SubmitShiftChangeRequestInput;

/**
 * The signed-in member asks for a change to their own shifts in the organization selected by the
 * X-Organization-Id header (ADR 0016 §5).
 *
 * @implements ProcessorInterface<SubmitShiftChangeRequestInput, ShiftChangeRequestResource>
 */
final readonly class SubmitShiftChangeRequestProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @param SubmitShiftChangeRequestInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ShiftChangeRequestResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $actorId = $this->currentUser->id();
        $requestId = Uuid::v7()->toRfc4122();

        $this->commandBus->handle(new SubmitShiftChangeRequestCommand(
            organizationId: $organizationId,
            actorId: $actorId,
            requestId: $requestId,
            shiftId: $data->shiftId,
            startedAt: $data->startedAt,
            endedAt: $data->endedAt,
            note: $data->note,
        ));

        /** @var ShiftChangeRequestView $view */
        $view = $this->queryBus->ask(new GetShiftChangeRequestQuery($organizationId, $actorId, $requestId));

        return ShiftChangeRequestResource::fromView($view);
    }
}
