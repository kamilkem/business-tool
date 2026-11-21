<?php

declare(strict_types=1);

namespace App\Income\Application\Query\GetPaymentMethod;

use App\Income\Application\IncomeActor;
use App\Income\Domain\Exception\IncomeManagementForbiddenException;
use App\Income\Domain\Repository\PaymentMethodRepositoryInterface;
use App\Income\Domain\Repository\RevenueEntryRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class GetPaymentMethodHandler implements QueryHandlerInterface
{
    public function __construct(
        private PaymentMethodRepositoryInterface $paymentMethods,
        private RevenueEntryRepositoryInterface $entries,
        private QueryBusInterface $queryBus,
    ) {
    }

    /** @throws IncomeManagementForbiddenException when the actor is an employee */
    public function __invoke(GetPaymentMethodQuery $query): ?PaymentMethodView
    {
        if (null === IncomeActor::resolveOrNull($this->queryBus, $query->organizationId, $query->actorId)) {
            return null;
        }

        $method = Uuid::isValid($query->paymentMethodId) ? $this->paymentMethods->findById(Uuid::fromString($query->paymentMethodId)) : null;

        return null === $method ? null : PaymentMethodView::fromPaymentMethod($method, $this->entries->usesPaymentMethod($method->getId()));
    }
}
