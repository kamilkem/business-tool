<?php

declare(strict_types=1);

namespace Ui\Http\Income\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\State\ProviderInterface;
use App\Income\Application\Query\GetPaymentMethod\PaymentMethodView;
use App\Income\Application\Query\ListPaymentMethods\ListPaymentMethodsQuery;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\Income\PaymentMethodResource;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * The payment methods of the organization selected by the X-Organization-Id header, in its order:
 * the active ones, or all with ?includeDeactivated=true. Employees are refused by the domain
 * (IncomeAccess).
 *
 * @implements ProviderInterface<PaymentMethodResource>
 */
final readonly class PaymentMethodCollectionProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @return list<PaymentMethodResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();

        $includeDeactivated = true === $operation->getParameters()?->get('includeDeactivated', QueryParameter::class)?->getValue();

        /** @var list<PaymentMethodView>|null $views */
        $views = $this->queryBus->ask(new ListPaymentMethodsQuery($organizationId, $this->currentUser->id(), $includeDeactivated));

        return array_map(
            PaymentMethodResource::fromView(...),
            $views ?? throw OrganizationNotFoundException::forId($organizationId),
        );
    }
}
