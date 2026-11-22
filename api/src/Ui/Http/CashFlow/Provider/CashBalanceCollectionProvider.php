<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\CashFlow\Application\Query\ListCashBalances\CashBalanceView;
use App\CashFlow\Application\Query\ListCashBalances\ListCashBalancesQuery;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\CashFlow\CashBalanceResource;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * The current balance of each cash storage of the organization selected by the X-Organization-Id header.
 *
 * @implements ProviderInterface<CashBalanceResource>
 */
final readonly class CashBalanceCollectionProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @return list<CashBalanceResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();

        /** @var list<CashBalanceView>|null $views */
        $views = $this->queryBus->ask(new ListCashBalancesQuery($organizationId, $this->currentUser->id()));

        return array_map(
            CashBalanceResource::fromView(...),
            $views ?? throw OrganizationNotFoundException::forId($organizationId),
        );
    }
}
