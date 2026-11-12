<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\EventHandler;

use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Domain\Event\EventHandlerInterface;
use App\TimeTracking\Application\Command\ApproveShiftChangeRequest\ApproveShiftChangeRequestCommand;
use App\TimeTracking\Domain\Event\ShiftChangeRequestApplied;

/**
 * The second step of approving a shift change request: once its change is applied to the shift, the
 * request is marked approved by the same owner or manager.
 */
final readonly class ApproveRequestOnShiftChangeRequestApplied implements EventHandlerInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
    ) {
    }

    public function __invoke(ShiftChangeRequestApplied $event): void
    {
        $this->commandBus->handle(new ApproveShiftChangeRequestCommand(
            organizationId: $event->organizationId,
            actorId: $event->approvedById,
            requestId: $event->requestId,
            shiftId: $event->shiftId,
        ));
    }
}
