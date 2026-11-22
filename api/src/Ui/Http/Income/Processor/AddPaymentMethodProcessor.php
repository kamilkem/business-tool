<?php

declare(strict_types=1);

namespace Ui\Http\Income\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Income\Application\Command\AddPaymentMethod\AddPaymentMethodCommand;
use App\Income\Application\Query\GetPaymentMethod\GetPaymentMethodQuery;
use App\Income\Application\Query\GetPaymentMethod\PaymentMethodView;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Uid\Uuid;
use Ui\Http\Income\AddPaymentMethodInput;
use Ui\Http\Income\PaymentMethodResource;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * An owner or manager adds a payment method at the end of the list of the organization selected
 * by the X-Organization-Id header (ADR 0030).
 *
 * @implements ProcessorInterface<AddPaymentMethodInput, PaymentMethodResource>
 */
final readonly class AddPaymentMethodProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /** @param AddPaymentMethodInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PaymentMethodResource
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();
        $actorId = $this->currentUser->id();
        $paymentMethodId = Uuid::v7()->toRfc4122();

        $this->commandBus->handle(new AddPaymentMethodCommand($organizationId, $actorId, $paymentMethodId, $data->name, $data->cash));

        /** @var PaymentMethodView $view */
        $view = $this->queryBus->ask(new GetPaymentMethodQuery($organizationId, $actorId, $paymentMethodId));

        return PaymentMethodResource::fromView($view);
    }
}
