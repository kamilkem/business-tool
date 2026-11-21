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
use Ui\Http\Income\UpdatePaymentMethodInput;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * Pre-fills the merge-patch input with the payment method's current name; API Platform then
 * applies the request body onto it. An employee is refused here (403) and a method that is not the
 * organization's is a 404, before the body is read.
 *
 * @implements ProviderInterface<UpdatePaymentMethodInput>
 */
final readonly class UpdatePaymentMethodInputProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): UpdatePaymentMethodInput
    {
        $paymentMethodId = $uriVariables['id'];
        \assert(\is_string($paymentMethodId));

        /** @var PaymentMethodView|null $view */
        $view = $this->queryBus->ask(new GetPaymentMethodQuery(
            $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            $this->currentUser->id(),
            $paymentMethodId,
        ));

        return UpdatePaymentMethodInput::fromView($view ?? throw PaymentMethodNotFoundException::withId($paymentMethodId));
    }
}
