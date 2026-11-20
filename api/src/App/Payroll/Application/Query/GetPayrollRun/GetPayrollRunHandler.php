<?php

declare(strict_types=1);

namespace App\Payroll\Application\Query\GetPayrollRun;

use App\Payroll\Application\PayrollActor;
use App\Payroll\Application\Query\PayrollRunVisibility;
use App\Payroll\Domain\Repository\PayrollRunRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class GetPayrollRunHandler implements QueryHandlerInterface
{
    public function __construct(
        private PayrollRunRepositoryInterface $runs,
        private QueryBusInterface $queryBus,
    ) {
    }

    public function __invoke(GetPayrollRunQuery $query): ?PayrollRunView
    {
        $actor = PayrollActor::member($this->queryBus, $query->organizationId, $query->actorId);
        $run = null !== $actor && Uuid::isValid($query->payrollRunId) ? $this->runs->findById(Uuid::fromString($query->payrollRunId)) : null;
        if (null === $actor || null === $run) {
            return null;
        }
        $paychecks = PayrollRunVisibility::paychecksFor($actor, $run);

        return null === $paychecks ? null : PayrollRunView::of($run, $paychecks);
    }
}
