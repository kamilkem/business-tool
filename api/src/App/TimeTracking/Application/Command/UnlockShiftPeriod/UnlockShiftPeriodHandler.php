<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Command\UnlockShiftPeriod;

use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\TimeTracking\Domain\Repository\LockedShiftPeriodRepositoryInterface;
use Symfony\Component\Uid\Uuid;

final readonly class UnlockShiftPeriodHandler implements CommandHandlerInterface
{
    public function __construct(
        private LockedShiftPeriodRepositoryInterface $periods,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function __invoke(UnlockShiftPeriodCommand $command): void
    {
        if ($this->tenantContext->getOrganization()->getId()->toRfc4122() !== $command->organizationId) {
            // Only reachable through a bug in the caller: the period would be unlocked in another organization.
            throw new \LogicException('A shift period is unlocked in the organization selected for the request or job.');
        }

        $period = $this->periods->findByLockedBy(Uuid::fromString($command->lockedBy));
        if (null !== $period) {
            $this->periods->remove($period);
        }
    }
}
