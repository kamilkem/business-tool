<?php

declare(strict_types=1);

namespace App\Income\Application\Command\DeletePaymentMethod;

use App\Income\Application\IncomeActor;
use App\Income\Domain\Exception\IncomeManagementForbiddenException;
use App\Income\Domain\Exception\PaymentMethodInUseException;
use App\Income\Domain\Exception\PaymentMethodNotFoundException;
use App\Income\Domain\Repository\PaymentMethodRepositoryInterface;
use App\Income\Domain\Repository\RevenueEntryRepositoryInterface;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DeletePaymentMethodHandler implements CommandHandlerInterface
{
    public function __construct(
        private PaymentMethodRepositoryInterface $paymentMethods,
        private RevenueEntryRepositoryInterface $entries,
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException      when the actor is not an active member of the organization
     * @throws IncomeManagementForbiddenException when the actor is not an owner or manager
     * @throws PaymentMethodNotFoundException     when the method is not one of the organization's
     * @throws PaymentMethodInUseException        when a revenue entry has a line for the method
     */
    public function __invoke(DeletePaymentMethodCommand $command): void
    {
        $actor = IncomeActor::resolve($this->queryBus, $command->organizationId, $command->actorId);

        $method = Uuid::isValid($command->paymentMethodId) ? $this->paymentMethods->findById(Uuid::fromString($command->paymentMethodId)) : null;
        $method ??= throw PaymentMethodNotFoundException::withId($command->paymentMethodId);

        $method->assertDeletable($this->entries->usesPaymentMethod($method->getId()), $actor->id, $actor->role);

        // The foreign key of the lines catches an entry recorded in the meantime (remove()).
        $this->paymentMethods->remove($method);
    }
}
