<?php

declare(strict_types=1);

namespace App\Income\Application\Query\ListPaymentMethods;

use App\Income\Application\IncomeActor;
use App\Income\Application\Query\GetPaymentMethod\PaymentMethodView;
use App\Income\Domain\Exception\IncomeManagementForbiddenException;
use App\Income\Domain\Model\PaymentMethod;
use App\Income\Domain\Repository\PaymentMethodRepositoryInterface;
use App\Income\Domain\Repository\RevenueEntryRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class ListPaymentMethodsHandler implements QueryHandlerInterface
{
    public function __construct(
        private PaymentMethodRepositoryInterface $paymentMethods,
        private RevenueEntryRepositoryInterface $entries,
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @return list<PaymentMethodView>|null active methods first, each group in the organization's order
     *
     * @throws IncomeManagementForbiddenException when the actor is an employee
     */
    public function __invoke(ListPaymentMethodsQuery $query): ?array
    {
        if (null === IncomeActor::resolveOrNull($this->queryBus, $query->organizationId, $query->actorId)) {
            return null;
        }

        $used = array_flip($this->entries->usedPaymentMethodIds());

        return array_map(
            static fn (PaymentMethod $method): PaymentMethodView => PaymentMethodView::fromPaymentMethod($method, isset($used[$method->getId()->toRfc4122()])),
            $this->paymentMethods->findAll($query->includeDeactivated),
        );
    }
}
