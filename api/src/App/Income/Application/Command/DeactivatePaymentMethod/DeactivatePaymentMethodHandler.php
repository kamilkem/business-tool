<?php

declare(strict_types=1);

namespace App\Income\Application\Command\DeactivatePaymentMethod;

use App\Income\Application\IncomeActor;
use App\Income\Domain\Exception\IncomeManagementForbiddenException;
use App\Income\Domain\Exception\PaymentMethodNotFoundException;
use App\Income\Domain\Repository\PaymentMethodRepositoryInterface;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DeactivatePaymentMethodHandler implements CommandHandlerInterface
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
     * @throws PaymentMethodNotFoundException     when the method is not one of the organization's
     */
    public function __invoke(DeactivatePaymentMethodCommand $command): void
    {
        $actor = IncomeActor::resolve($this->queryBus, $command->organizationId, $command->actorId);

        $method = Uuid::isValid($command->paymentMethodId) ? $this->paymentMethods->findById(Uuid::fromString($command->paymentMethodId)) : null;
        $method ??= throw PaymentMethodNotFoundException::withId($command->paymentMethodId);

        $method->deactivate($actor->id, $actor->role, $this->clock->now());

        $this->paymentMethods->save($method);
    }
}
