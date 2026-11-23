<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Command\ReportLongShift;

use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\Event\EventPublisherInterface;
use App\TimeTracking\Application\MaxShiftLengths;
use App\TimeTracking\Domain\Event\LongShiftReported;
use App\TimeTracking\Domain\Repository\ShiftRepositoryInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** Marks the shift as reported (Shift::reportAsLong()), then publishes LongShiftReported (the e-mails). */
final readonly class ReportLongShiftHandler implements CommandHandlerInterface
{
    public function __construct(
        private ShiftRepositoryInterface $shifts,
        private MaxShiftLengths $maxShiftLengths,
        private TenantContextInterface $tenantContext,
        private EventPublisherInterface $events,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ReportLongShiftCommand $command): void
    {
        if ($this->tenantContext->getOrganization()->getId()->toRfc4122() !== $command->organizationId) {
            // Only reachable through a bug in the caller: the shift would be looked up in another organization.
            throw new \LogicException('A long shift is reported in the organization selected for the job.');
        }

        // Deleted since the job listed it: nothing to report.
        $shift = $this->shifts->findById(Uuid::fromString($command->shiftId));
        if (null === $shift) {
            return;
        }

        $maxShiftLength = $this->maxShiftLengths->current();
        if (!$shift->reportAsLong($maxShiftLength, $this->clock->now())) {
            return;
        }
        $this->shifts->save($shift);

        $this->events->publish(new LongShiftReported(
            $command->organizationId,
            $shift->getId()->toRfc4122(),
            $shift->getUserId()->toRfc4122(),
            $maxShiftLength->hours,
        ));
    }
}
