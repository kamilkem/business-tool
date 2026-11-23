<?php

declare(strict_types=1);

namespace Ui\Http\TimeTracking\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\TimeTracking\Application\Command\WithdrawShiftChangeRequest\WithdrawShiftChangeRequestCommand;
use App\TimeTracking\Application\Query\GetShiftChangeRequest\GetShiftChangeRequestQuery;
use App\TimeTracking\Application\Query\GetShiftChangeRequest\ShiftChangeRequestView;
use Ui\Http\Shared\Security\CurrentUser;
use Ui\Http\TimeTracking\ShiftChangeRequestResource;

/**
 * The signed-in member withdraws their own pending shift change request in the organization selected
 * by the X-Organization-Id header.
 *
 * @implements ProcessorInterface<null, ShiftChangeRequestResource>
 */
final readonly class WithdrawShiftChangeRequestProcessor implements ProcessorInterface
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

        $this->commandBus->handle(new WithdrawShiftChangeRequestCommand($organizationId, $actorId, $requestId));

        /** @var ShiftChangeRequestView $view */
        $view = $this->queryBus->ask(new GetShiftChangeRequestQuery($organizationId, $actorId, $requestId));

        return ShiftChangeRequestResource::fromView($view);
    }
}
