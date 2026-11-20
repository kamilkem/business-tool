<?php

declare(strict_types=1);

namespace App\Payroll\Application\Query\GetHourlyRate;

use App\Payroll\Application\PayrollActor;
use App\Payroll\Domain\Exception\PayrollAccessForbiddenException;
use App\Payroll\Domain\Model\HourlyRateHistory;
use App\Payroll\Domain\Model\PayrollAccess;
use App\Payroll\Domain\Repository\HourlyRateRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class GetHourlyRateHandler implements QueryHandlerInterface
{
    public function __construct(
        private HourlyRateRepositoryInterface $rates,
        private QueryBusInterface $queryBus,
    ) {
    }

    /** @throws PayrollAccessForbiddenException when an employee asks about someone else's rate */
    public function __invoke(GetHourlyRateQuery $query): ?HourlyRateView
    {
        $actor = PayrollActor::member($this->queryBus, $query->organizationId, $query->actorId);
        $rate = null !== $actor && Uuid::isValid($query->rateId) ? $this->rates->findById(Uuid::fromString($query->rateId)) : null;
        if (null === $actor || null === $rate) {
            return null;
        }
        PayrollAccess::assertCanRead($actor->id, $actor->role, $rate->getUserId());

        return HourlyRateView::of($rate, HourlyRateHistory::of($rate->getUserId(), $this->rates->findByUser($rate->getUserId())));
    }
}
