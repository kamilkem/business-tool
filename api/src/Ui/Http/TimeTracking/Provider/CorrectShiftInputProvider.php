<?php

declare(strict_types=1);

namespace Ui\Http\TimeTracking\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\TimeTracking\Application\Query\GetManagedShift\GetManagedShiftQuery;
use App\TimeTracking\Application\Query\GetShift\ShiftView;
use App\TimeTracking\Domain\Exception\ShiftNotFoundException;
use Ui\Http\Shared\Security\CurrentUser;
use Ui\Http\TimeTracking\CorrectShiftInput;

/**
 * Pre-fills the merge-patch input with the shift's current state; API Platform then applies the
 * request body onto it. An employee is refused here (403) and a shift that is not the organization's
 * is a 404, before the body is read; what a correction may do is decided by Shift::correct().
 *
 * @implements ProviderInterface<CorrectShiftInput>
 */
final readonly class CorrectShiftInputProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CorrectShiftInput
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $shiftId = $uriVariables['id'];
        \assert(\is_string($shiftId));

        /** @var ShiftView|null $view */
        $view = $this->queryBus->ask(new GetManagedShiftQuery($organizationId, $this->currentUser->id(), $shiftId));

        return CorrectShiftInput::fromView($view ?? throw ShiftNotFoundException::withId($shiftId));
    }
}
