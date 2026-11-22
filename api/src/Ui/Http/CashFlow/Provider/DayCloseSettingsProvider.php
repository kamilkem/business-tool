<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Put;
use ApiPlatform\State\ProviderInterface;
use App\CashFlow\Application\Query\GetDayCloseSettings\DayCloseSettingsView;
use App\CashFlow\Application\Query\GetDayCloseSettings\GetDayCloseSettingsQuery;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\CashFlow\DayCloseSettingsResource;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * GET /day-close-settings, and the current settings before PUT /day-close-settings: for the PUT,
 * an employee is refused (403) before the body is validated.
 *
 * @implements ProviderInterface<DayCloseSettingsResource>
 */
final readonly class DayCloseSettingsProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): DayCloseSettingsResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();

        /** @var DayCloseSettingsView|null $view */
        $view = $this->queryBus->ask(new GetDayCloseSettingsQuery($organizationId, $this->currentUser->id(), toManage: $operation instanceof Put));

        return DayCloseSettingsResource::fromView($view ?? throw OrganizationNotFoundException::forId($organizationId));
    }
}
