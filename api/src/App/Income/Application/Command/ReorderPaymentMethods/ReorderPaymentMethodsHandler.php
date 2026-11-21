<?php

declare(strict_types=1);

namespace App\Income\Application\Command\ReorderPaymentMethods;

use App\Income\Application\IncomeActor;
use App\Income\Domain\Exception\IncomeManagementForbiddenException;
use App\Income\Domain\Exception\PaymentMethodOrderMismatchException;
use App\Income\Domain\Model\PaymentMethodOrder;
use App\Income\Domain\Repository\PaymentMethodRepositoryInterface;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;

final readonly class ReorderPaymentMethodsHandler implements CommandHandlerInterface
{
    public function __construct(
        private PaymentMethodRepositoryInterface $paymentMethods,
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @throws OrganizationNotFoundException       when the actor is not an active member of the organization
     * @throws IncomeManagementForbiddenException  when the actor is not an owner or manager
     * @throws PaymentMethodOrderMismatchException when the ids are not exactly the active methods
     */
    public function __invoke(ReorderPaymentMethodsCommand $command): void
    {
        $actor = IncomeActor::resolve($this->queryBus, $command->organizationId, $command->actorId);

        $active = $this->paymentMethods->findAll();
        PaymentMethodOrder::apply($active, $command->paymentMethodIds, $actor->id, $actor->role);

        $this->paymentMethods->saveAll($active);
    }
}
