<?php

declare(strict_types=1);

namespace Ui\Http\TimeTracking\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\TimeTracking\Application\Query\GetCurrentShift\GetCurrentShiftQuery;
use App\TimeTracking\Application\Query\GetShift\ShiftView;
use App\TimeTracking\Domain\Exception\ShiftNotFoundException;
use Ui\Http\Shared\Security\CurrentUser;
use Ui\Http\TimeTracking\ShiftResource;

/**
 * The signed-in user's open shift in the organization selected by the X-Organization-Id header.
 *
 * @implements ProviderInterface<ShiftResource>
 */
final readonly class CurrentShiftProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ShiftResource
    {
        $this->tenantContext->getOrganization(); // 400 without the header, rather than a 404.
        $userId = $this->currentUser->id();

        /** @var ShiftView|null $view */
        $view = $this->queryBus->ask(new GetCurrentShiftQuery($userId));

        return ShiftResource::fromView($view ?? throw ShiftNotFoundException::currentOf($userId));
    }
}
