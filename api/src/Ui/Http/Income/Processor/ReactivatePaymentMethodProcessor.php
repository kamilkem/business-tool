<?php

declare(strict_types=1);

namespace Ui\Http\Income\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Income\Application\Command\ReactivatePaymentMethod\ReactivatePaymentMethodCommand;
use App\Income\Application\Query\GetPaymentMethod\GetPaymentMethodQuery;
use App\Income\Application\Query\GetPaymentMethod\PaymentMethodView;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\Income\PaymentMethodResource;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager reactivates a payment method of the organization selected by the
 * X-Organization-Id header: it takes lines again, at the end of the list (ADR 0030 §4).
 *
 * @implements ProcessorInterface<null, PaymentMethodResource>
 */
final readonly class ReactivatePaymentMethodProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PaymentMethodResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $actorId = $this->currentUser->id();
        $paymentMethodId = $uriVariables['id'];
        \assert(\is_string($paymentMethodId));

        $this->commandBus->handle(new ReactivatePaymentMethodCommand($organizationId, $actorId, $paymentMethodId));

        /** @var PaymentMethodView $view */
        $view = $this->queryBus->ask(new GetPaymentMethodQuery($organizationId, $actorId, $paymentMethodId));

        return PaymentMethodResource::fromView($view);
    }
}
