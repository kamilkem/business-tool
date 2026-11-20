<?php

declare(strict_types=1);

namespace Ui\Http\Payroll;

use App\Payroll\Application\Query\GetPayrollRun\GetPayrollRunQuery;
use App\Payroll\Application\Query\GetPayrollRun\PayrollRunView;
use App\Payroll\Domain\Exception\PayrollRunNotFoundException;
use App\Shared\Application\Query\QueryBusInterface;

/** Answers a payroll run endpoint with the run as the signed-in member sees it, or a 404. */
final readonly class PayrollRunReader
{
    public function __construct(
        private QueryBusInterface $queryBus,
    ) {
    }

    /** @throws PayrollRunNotFoundException */
    public function read(string $organizationId, string $actorId, string $payrollRunId): PayrollRunResource
    {
        /** @var PayrollRunView|null $view */
        $view = $this->queryBus->ask(new GetPayrollRunQuery($organizationId, $actorId, $payrollRunId));

        return PayrollRunResource::fromView($view ?? throw PayrollRunNotFoundException::forId($payrollRunId));
    }
}
