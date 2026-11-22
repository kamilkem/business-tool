<?php

declare(strict_types=1);

namespace App\Income\Application\Command\UpdatePaymentMethod;

use App\Income\Application\IncomeActor;
use App\Income\Domain\Exception\CashPaymentMethodTakenException;
use App\Income\Domain\Exception\IncomeManagementForbiddenException;
use App\Income\Domain\Exception\InvalidPaymentMethodNameException;
use App\Income\Domain\Exception\PaymentMethodNameTakenException;
use App\Income\Domain\Exception\PaymentMethodNotFoundException;
use App\Income\Domain\Repository\PaymentMethodRepositoryInterface;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Uid\Uuid;

final readonly class UpdatePaymentMethodHandler implements CommandHandlerInterface
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
     * @throws InvalidPaymentMethodNameException  when the name is blank or too long
     * @throws PaymentMethodNameTakenException    when another method of the organization has the name
     * @throws CashPaymentMethodTakenException    when an active method is marked and another active method is cash
     */
    public function __invoke(UpdatePaymentMethodCommand $command): void
    {
        $actor = IncomeActor::resolve($this->queryBus, $command->organizationId, $command->actorId);

        $method = Uuid::isValid($command->paymentMethodId) ? $this->paymentMethods->findById(Uuid::fromString($command->paymentMethodId)) : null;
        $method ??= throw PaymentMethodNotFoundException::withId($command->paymentMethodId);

        $method->rename($command->name, $this->paymentMethods->findByName(trim($command->name)), $actor->id, $actor->role);
        $method->changeCash($command->cash, $this->paymentMethods->findActiveCash(), $actor->id, $actor->role);

        $this->paymentMethods->save($method);
    }
}
