<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Application\Command\RequestMatching\RequestMatchingCommand;
use App\Purchasing\Application\Query\GetMatchingStatus\GetMatchingStatusQuery;
use App\Purchasing\Application\Query\GetMatchingStatus\MatchingStatusView;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\Purchasing\MatchingStatusResource;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * "Match again": an owner or manager queues the organization's pass over its unassigned items (202, ADR 0025,
 * ADR 0046 §4); the status is returned as it is now. The run shows up in it when the job starts.
 *
 * @implements ProcessorInterface<null, MatchingStatusResource>
 */
final readonly class RequestMatchingProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MatchingStatusResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $actorId = $this->currentUser->id();

        $this->commandBus->handle(new RequestMatchingCommand($organizationId, $actorId));

        /** @var MatchingStatusView|null $view */
        $view = $this->queryBus->ask(new GetMatchingStatusQuery($organizationId, $actorId));

        return MatchingStatusResource::fromView($view ?? throw OrganizationNotFoundException::forId($organizationId));
    }
}
