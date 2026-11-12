<?php

declare(strict_types=1);

namespace App\Income\Application\Query\ListVatRates;

use App\Income\Application\IncomeActor;
use App\Income\Domain\Exception\IncomeManagementForbiddenException;
use App\Income\Domain\Model\VatRate;
use App\Income\Domain\Model\VatRates;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class ListVatRatesHandler implements QueryHandlerInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @return list<VatRateView>|null in the order a daily report prints them
     *
     * @throws IncomeManagementForbiddenException when the actor is an employee
     */
    public function __invoke(ListVatRatesQuery $query): ?array
    {
        $actor = IncomeActor::resolveOrNull($this->queryBus, $query->organizationId, $query->actorId);
        if (null === $actor) {
            return null;
        }

        return array_map(
            static fn (VatRate $rate): VatRateView => new VatRateView($rate->code, $rate->zeroVat),
            VatRates::forCountry($actor->organization->countryCode),
        );
    }
}
