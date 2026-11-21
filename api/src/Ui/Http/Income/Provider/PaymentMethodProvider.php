<?php

declare(strict_types=1);

namespace Ui\Http\Income\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Income\Application\Query\GetPaymentMethod\GetPaymentMethodQuery;
use App\Income\Application\Query\GetPaymentMethod\PaymentMethodView;
use App\Income\Domain\Exception\PaymentMethodNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\Income\PaymentMethodResource;
use Ui\Http\Shared\Security\CurrentUser;

/** @implements ProviderInterface<PaymentMethodResource> */
final readonly class PaymentMethodProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PaymentMethodResource
    {
        $paymentMethodId = $uriVariables['id'];
        \assert(\is_string($paymentMethodId));

        /** @var PaymentMethodView|null $view */
        $view = $this->queryBus->ask(new GetPaymentMethodQuery(
            $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            $this->currentUser->id(),
            $paymentMethodId,
        ));

        return PaymentMethodResource::fromView($view ?? throw PaymentMethodNotFoundException::withId($paymentMethodId));
    }
}
