<?php

declare(strict_types=1);

namespace Ui\Http\TimeTracking\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Put;
use ApiPlatform\State\ProviderInterface;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\TimeTracking\Application\Query\GetTimeTrackingSettings\GetTimeTrackingSettingsQuery;
use App\TimeTracking\Application\Query\GetTimeTrackingSettings\TimeTrackingSettingsView;
use Ui\Http\Shared\Security\CurrentUser;
use Ui\Http\TimeTracking\TimeTrackingSettingsResource;

/**
 * GET /time-tracking-settings, and the current settings before PUT /time-tracking-settings: for the
 * PUT, an employee is refused (403) before the body is validated.
 *
 * @implements ProviderInterface<TimeTrackingSettingsResource>
 */
final readonly class TimeTrackingSettingsProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TimeTrackingSettingsResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();

        /** @var TimeTrackingSettingsView|null $view */
        $view = $this->queryBus->ask(new GetTimeTrackingSettingsQuery($organizationId, $this->currentUser->id(), toManage: $operation instanceof Put));

        return TimeTrackingSettingsResource::fromView($view ?? throw OrganizationNotFoundException::forId($organizationId));
    }
}
