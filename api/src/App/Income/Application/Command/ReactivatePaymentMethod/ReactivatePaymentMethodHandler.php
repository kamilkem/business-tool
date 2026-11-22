<?php

declare(strict_types=1);

namespace App\Income\Application\Command\ReactivatePaymentMethod;

use App\Income\Application\IncomeActor;
use App\Income\Domain\Exception\CashPaymentMethodTakenException;
use App\Income\Domain\Exception\IncomeManagementForbiddenException;
use App\Income\Domain\Exception\PaymentMethodNotFoundException;
use App\Income\Domain\Repository\PaymentMethodRepositoryInterface;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Uid\Uuid;

final readonly class ReactivatePaymentMethodHandler implements CommandHandlerInterface
{
    public function __construct(
        private PaymentMethodRepositoryInterface $paymentMethods,
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException      when the actor is not an active member of the organization
     * @throws IncomeManagementForbiddenException when the actor is not an owner or manager
     * @throws PaymentMethodNotFoundException     when the method is not one of the organization's
     * @throws CashPaymentMethodTakenException    when the method is cash and another active method is
     */
    public function __invoke(ReactivatePaymentMethodCommand $command): void
    {
        $actor = IncomeActor::resolve($this->queryBus, $command->organizationId, $command->actorId);

        $method = Uuid::isValid($command->paymentMethodId) ? $this->paymentMethods->findById(Uuid::fromString($command->paymentMethodId)) : null;
        $method ??= throw PaymentMethodNotFoundException::withId($command->paymentMethodId);

        $method->reactivate($this->paymentMethods->nextPosition(), $this->paymentMethods->findActiveCash(), $actor->id, $actor->role);

        $this->paymentMethods->save($method);
    }
}
