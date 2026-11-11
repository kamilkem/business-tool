<?php

declare(strict_types=1);

namespace Ui\Http\TimeTracking\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\TimeTracking\Application\Command\ClockIn\ClockInCommand;
use App\TimeTracking\Application\Query\GetShift\GetShiftQuery;
use App\TimeTracking\Application\Query\GetShift\ShiftView;
use Symfony\Component\Uid\Uuid;
use Ui\Http\Shared\Security\CurrentUser;
use Ui\Http\TimeTracking\ShiftResource;

/**
 * Clocks the signed-in user in to the organization selected by the X-Organization-Id header, at the
 * server's now (ADR 0016).
 *
 * @implements ProcessorInterface<null, ShiftResource>
 */
final readonly class ClockInProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ShiftResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $userId = $this->currentUser->id();
        $shiftId = Uuid::v7()->toRfc4122();

        $this->commandBus->handle(new ClockInCommand($organizationId, $shiftId, $userId));

        /** @var ShiftView $view */
        $view = $this->queryBus->ask(new GetShiftQuery($shiftId, $userId));

        return ShiftResource::fromView($view);
    }
}
