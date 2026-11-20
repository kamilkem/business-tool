<?php

declare(strict_types=1);

namespace App\Payroll\Application\Query\ListPayrollRuns;

use App\Payroll\Application\PayrollActor;
use App\Payroll\Application\Query\PayrollRunVisibility;
use App\Payroll\Domain\Repository\PayrollRunRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class ListPayrollRunsHandler implements QueryHandlerInterface
{
    public function __construct(
        private PayrollRunRepositoryInterface $runs,
        private QueryBusInterface $queryBus,
    ) {
    }

    /** @return list<PayrollRunSummaryView>|null */
    public function __invoke(ListPayrollRunsQuery $query): ?array
    {
        $actor = PayrollActor::member($this->queryBus, $query->organizationId, $query->actorId);
        if (null === $actor) {
            return null;
        }

        $views = [];
        foreach ($this->runs->findAll() as $run) {
            $paychecks = PayrollRunVisibility::paychecksFor($actor, $run);
            if (null !== $paychecks) {
                $views[] = PayrollRunSummaryView::of($run, $paychecks);
            }
        }

        return $views;
    }
}
