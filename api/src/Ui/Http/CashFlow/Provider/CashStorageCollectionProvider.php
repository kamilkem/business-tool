<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\State\ProviderInterface;
use App\CashFlow\Application\Query\GetCashStorage\CashStorageView;
use App\CashFlow\Application\Query\ListCashStorages\ListCashStoragesQuery;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\CashFlow\CashStorageResource;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * The cash storages of the organization selected by the X-Organization-Id header:
 * the active ones, or all with ?includeDeactivated=true.
 *
 * @implements ProviderInterface<CashStorageResource>
 */
final readonly class CashStorageCollectionProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @return list<CashStorageResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();

        $includeDeactivated = true === $operation->getParameters()?->get('includeDeactivated', QueryParameter::class)?->getValue();

        /** @var list<CashStorageView>|null $views */
        $views = $this->queryBus->ask(new ListCashStoragesQuery($organizationId, $this->currentUser->id(), $includeDeactivated));

        return array_map(
            CashStorageResource::fromView(...),
            $views ?? throw OrganizationNotFoundException::forId($organizationId),
        );
    }
}
