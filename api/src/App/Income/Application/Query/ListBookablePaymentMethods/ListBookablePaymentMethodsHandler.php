<?php

declare(strict_types=1);

namespace App\Income\Application\Query\ListBookablePaymentMethods;

use App\Income\Application\Query\GetPaymentMethod\PaymentMethodView;
use App\Income\Domain\Model\PaymentMethod;
use App\Income\Domain\Repository\PaymentMethodRepositoryInterface;
use App\Income\Domain\Repository\RevenueEntryRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class ListBookablePaymentMethodsHandler implements QueryHandlerInterface
{
    public function __construct(
        private PaymentMethodRepositoryInterface $paymentMethods,
        private RevenueEntryRepositoryInterface $entries,
    ) {
    }

    /** @return list<PaymentMethodView> active only, in the organization's order */
    public function __invoke(ListBookablePaymentMethodsQuery $query): array
    {
        $used = array_flip($this->entries->usedPaymentMethodIds());

        return array_map(
            static fn (PaymentMethod $method): PaymentMethodView => PaymentMethodView::fromPaymentMethod($method, isset($used[$method->getId()->toRfc4122()])),
            $this->paymentMethods->findAll(),
        );
    }
}
