<?php

declare(strict_types=1);

namespace Ui\Http\TimeTracking\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\TimeTracking\Application\Command\ClockOut\ClockOutCommand;
use App\TimeTracking\Application\Query\GetCurrentShift\GetCurrentShiftQuery;
use App\TimeTracking\Application\Query\GetShift\GetShiftQuery;
use App\TimeTracking\Application\Query\GetShift\ShiftView;
use App\TimeTracking\Domain\Exception\NotClockedInException;
use Ui\Http\Shared\Security\CurrentUser;
use Ui\Http\TimeTracking\ShiftResource;

/**
 * Clocks the signed-in user out of their open shift in the organization selected by the
 * X-Organization-Id header, at the server's now (ADR 0016), and returns the ended shift.
 *
 * @implements ProcessorInterface<null, ShiftResource>
 */
final readonly class ClockOutProcessor implements ProcessorInterface
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

        /** @var ShiftView|null $current */
        $current = $this->queryBus->ask(new GetCurrentShiftQuery($userId));
        if (null === $current) {
            throw NotClockedInException::forUser($userId);
        }

        $this->commandBus->handle(new ClockOutCommand($organizationId, $current->id, $userId));

        /** @var ShiftView $view */
        $view = $this->queryBus->ask(new GetShiftQuery($current->id, $userId));

        return ShiftResource::fromView($view);
    }
}
