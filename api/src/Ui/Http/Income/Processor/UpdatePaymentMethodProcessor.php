<?php

declare(strict_types=1);

namespace Ui\Http\Income\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Income\Application\Command\UpdatePaymentMethod\UpdatePaymentMethodCommand;
use App\Income\Application\Query\GetPaymentMethod\GetPaymentMethodQuery;
use App\Income\Application\Query\GetPaymentMethod\PaymentMethodView;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\Income\PaymentMethodResource;
use Ui\Http\Income\UpdatePaymentMethodInput;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager renames a payment method of the organization selected by the
 * X-Organization-Id header (ADR 0030), or marks it as cash (ADR 0033 §9).
 *
 * @implements ProcessorInterface<UpdatePaymentMethodInput, PaymentMethodResource>
 */
final readonly class UpdatePaymentMethodProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @param UpdatePaymentMethodInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PaymentMethodResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $actorId = $this->currentUser->id();
        $paymentMethodId = $uriVariables['id'];
        \assert(\is_string($paymentMethodId) && null !== $data->name && null !== $data->cash);

        $this->commandBus->handle(new UpdatePaymentMethodCommand($organizationId, $actorId, $paymentMethodId, $data->name, $data->cash));

        /** @var PaymentMethodView $view */
        $view = $this->queryBus->ask(new GetPaymentMethodQuery($organizationId, $actorId, $paymentMethodId));

        return PaymentMethodResource::fromView($view);
    }
}
