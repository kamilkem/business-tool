<?php

declare(strict_types=1);

namespace Ui\Http\TimeTracking\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\TimeTracking\Application\Query\GetShiftChangeRequest\GetShiftChangeRequestQuery;
use App\TimeTracking\Application\Query\GetShiftChangeRequest\ShiftChangeRequestView;
use App\TimeTracking\Domain\Exception\ShiftChangeRequestNotFoundException;
use Ui\Http\Shared\Security\CurrentUser;
use Ui\Http\TimeTracking\ShiftChangeRequestResource;

/**
 * One shift change request of the organization selected by the X-Organization-Id header; a request
 * the signed-in user may not see is a 404.
 *
 * @implements ProviderInterface<ShiftChangeRequestResource>
 */
final readonly class ShiftChangeRequestProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ShiftChangeRequestResource
    {
        $requestId = (string) $uriVariables['id'];

        /** @var ShiftChangeRequestView|null $view */
        $view = $this->queryBus->ask(new GetShiftChangeRequestQuery(
            $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            $this->currentUser->id(),
            $requestId,
        ));

        return ShiftChangeRequestResource::fromView($view ?? throw ShiftChangeRequestNotFoundException::withId($requestId));
    }
}
