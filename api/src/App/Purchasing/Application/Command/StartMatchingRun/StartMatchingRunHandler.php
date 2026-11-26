<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Command\StartMatchingRun;

use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Domain\Matching\MatchScope;
use App\Purchasing\Domain\Model\MatchingRun;
use App\Purchasing\Domain\Repository\MatchingRunRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final readonly class StartMatchingRunHandler implements CommandHandlerInterface
{
    public function __construct(
        private MatchingRunRepositoryInterface $runs,
        private TenantContextInterface $tenantContext,
        private ClockInterface $clock,
    ) {
    }

    /** @throws \LogicException when the organization is not the one the job runs in */
    public function __invoke(StartMatchingRunCommand $command): void
    {
        if ($this->tenantContext->getOrganization()->getId()->toRfc4122() !== $command->organizationId) {
            throw new \LogicException('A matching run is recorded only in the organization the job runs in.');
        }

        $this->runs->add(new MatchingRun(Uuid::fromString($command->runId), MatchScope::from($command->scope), $this->clock->now()));
    }
}
