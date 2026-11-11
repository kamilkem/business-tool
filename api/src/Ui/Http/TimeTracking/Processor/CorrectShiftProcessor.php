<?php

declare(strict_types=1);

namespace Ui\Http\TimeTracking\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\TimeTracking\Application\Command\CorrectShift\CorrectShiftCommand;
use App\TimeTracking\Application\Query\GetManagedShift\GetManagedShiftQuery;
use App\TimeTracking\Application\Query\GetShift\ShiftView;
use Ui\Http\Shared\Security\CurrentUser;
use Ui\Http\TimeTracking\CorrectShiftInput;
use Ui\Http\TimeTracking\ShiftResource;

/**
 * An owner or manager corrects a shift's times and note in the organization selected by the
 * X-Organization-Id header (ADR 0016 §5).
 *
 * @implements ProcessorInterface<CorrectShiftInput, ShiftResource>
 */
final readonly class CorrectShiftProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @param CorrectShiftInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ShiftResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $actorId = $this->currentUser->id();
        $shiftId = $uriVariables['id'];
        \assert(\is_string($shiftId) && null !== $data->startedAt);

        $this->commandBus->handle(new CorrectShiftCommand(
            organizationId: $organizationId,
            actorId: $actorId,
            shiftId: $shiftId,
            startedAt: $data->startedAt,
            endedAt: $data->endedAt,
            note: $data->note,
        ));

        /** @var ShiftView $view */
        $view = $this->queryBus->ask(new GetManagedShiftQuery($organizationId, $actorId, $shiftId));

        return ShiftResource::fromView($view);
    }
}
