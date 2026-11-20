<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payroll\Application;

use App\Payroll\Domain\Exception\PayrollRunMonthTakenException;
use App\Payroll\Domain\Model\PayrollRun;
use App\Payroll\Domain\Repository\PayrollRunRepositoryInterface;
use App\Shared\Domain\Time\BusinessPeriod;
use Symfony\Component\Uid\Uuid;

final class InMemoryPayrollRunRepository implements PayrollRunRepositoryInterface
{
    /** @var array<string, PayrollRun> */
    private array $byId = [];

    public function findById(Uuid $id): ?PayrollRun
    {
        return $this->byId[$id->toRfc4122()] ?? null;
    }

    public function findByMonth(BusinessPeriod $month): ?PayrollRun
    {
        foreach ($this->byId as $run) {
            if ($run->month()->equals($month)) {
                return $run;
            }
        }

        return null;
    }

    public function findAll(): array
    {
        $runs = array_values($this->byId);
        usort($runs, static fn (PayrollRun $a, PayrollRun $b): int => $b->month()->first->toString() <=> $a->month()->first->toString());

        return $runs;
    }

    public function add(PayrollRun $run): void
    {
        if (null !== $this->findByMonth($run->month())) {
            throw PayrollRunMonthTakenException::month($run->month());
        }
        $this->byId[$run->getId()->toRfc4122()] = $run;
    }

    public function save(PayrollRun $run): void
    {
    }

    public function remove(PayrollRun $run): void
    {
        unset($this->byId[$run->getId()->toRfc4122()]);
    }
}
