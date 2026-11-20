<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Command\LockShiftPeriod;

use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\Time\BusinessPeriod;
use App\Shared\Domain\Time\Exception\TimeException;
use App\TimeTracking\Domain\Model\LockedShiftPeriod;
use App\TimeTracking\Domain\Repository\LockedShiftPeriodRepositoryInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final readonly class LockShiftPeriodHandler implements CommandHandlerInterface
{
    public function __construct(
        private LockedShiftPeriodRepositoryInterface $periods,
        private TenantContextInterface $tenantContext,
        private ClockInterface $clock,
    ) {
    }

    /** @throws TimeException when a date is not a date, or the last is before the first */
    public function __invoke(LockShiftPeriodCommand $command): void
    {
        if ($this->tenantContext->getOrganization()->getId()->toRfc4122() !== $command->organizationId) {
            // Only reachable through a bug in the caller: the period would be locked in another organization.
            throw new \LogicException('A shift period is locked in the organization selected for the request or job.');
        }

        $period = BusinessPeriod::fromStrings($command->firstDate, $command->lastDate);
        $lockedBy = Uuid::fromString($command->lockedBy);

        $existing = $this->periods->findByLockedBy($lockedBy);
        if (null !== $existing) {
            if (!$existing->getPeriod()->equals($period)) {
                // Only reachable through a bug in the caller: what locked a period never locks another one.
                throw new \LogicException(\sprintf('"%s" already locks another period.', $command->lockedBy));
            }

            return;
        }

        $this->periods->add(new LockedShiftPeriod(
            Uuid::fromString($command->lockedShiftPeriodId),
            $lockedBy,
            $period,
            $this->clock->now(),
        ));
    }
}
