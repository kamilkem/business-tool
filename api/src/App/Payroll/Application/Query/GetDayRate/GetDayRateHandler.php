<?php

declare(strict_types=1);

namespace App\Payroll\Application\Query\GetDayRate;

use App\Payroll\Application\PayrollActor;
use App\Payroll\Domain\Exception\PayrollAccessForbiddenException;
use App\Payroll\Domain\Model\PayrollAccess;
use App\Payroll\Domain\Repository\DayRateRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class GetDayRateHandler implements QueryHandlerInterface
{
    public function __construct(
        private DayRateRepositoryInterface $dayRates,
        private QueryBusInterface $queryBus,
    ) {
    }

    /** @throws PayrollAccessForbiddenException when an employee asks about someone else's day rate */
    public function __invoke(GetDayRateQuery $query): ?DayRateView
    {
        $actor = PayrollActor::member($this->queryBus, $query->organizationId, $query->actorId);
        $dayRate = null !== $actor && Uuid::isValid($query->rateId) ? $this->dayRates->findById(Uuid::fromString($query->rateId)) : null;
        if (null === $actor || null === $dayRate) {
            return null;
        }
        PayrollAccess::assertCanRead($actor->id, $actor->role, $dayRate->getUserId());

        return DayRateView::of($dayRate);
    }
}
