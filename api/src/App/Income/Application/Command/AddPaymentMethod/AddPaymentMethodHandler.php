<?php

declare(strict_types=1);

namespace App\Income\Application\Command\AddPaymentMethod;

use App\Income\Application\IncomeActor;
use App\Income\Domain\Exception\IncomeManagementForbiddenException;
use App\Income\Domain\Exception\InvalidPaymentMethodNameException;
use App\Income\Domain\Exception\PaymentMethodNameTakenException;
use App\Income\Domain\Model\PaymentMethod;
use App\Income\Domain\Repository\PaymentMethodRepositoryInterface;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final readonly class AddPaymentMethodHandler implements CommandHandlerInterface
{
    public function __construct(
        private PaymentMethodRepositoryInterface $paymentMethods,
        private QueryBusInterface $queryBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException      when the actor is not an active member of the organization
     * @throws IncomeManagementForbiddenException when the actor is not an owner or manager
     * @throws InvalidPaymentMethodNameException  when the name is blank or too long
     * @throws PaymentMethodNameTakenException    when a method of the organization, active or not, has the name
     */
    public function __invoke(AddPaymentMethodCommand $command): void
    {
        $actor = IncomeActor::resolve($this->queryBus, $command->organizationId, $command->actorId);

        $method = PaymentMethod::create(
            Uuid::fromString($command->paymentMethodId),
            $command->name,
            $this->paymentMethods->findByName(trim($command->name)),
            $this->paymentMethods->nextPosition(),
            $actor->id,
            $actor->role,
            $this->clock->now(),
        );

        $this->paymentMethods->add($method);
    }
}
