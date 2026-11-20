<?php

declare(strict_types=1);

namespace App\Payroll\Domain\Repository;

use App\Payroll\Domain\Exception\PayrollRunMonthTakenException;
use App\Payroll\Domain\Model\PayrollRun;
use App\Shared\Domain\Time\BusinessPeriod;
use Symfony\Component\Uid\Uuid;

/** Payroll runs of the current organization only (TenantFilter). */
interface PayrollRunRepositoryInterface
{
    public function findById(Uuid $id): ?PayrollRun;

    public function findByMonth(BusinessPeriod $month): ?PayrollRun;

    /**
     * Newest month first.
     *
     * @return list<PayrollRun>
     */
    public function findAll(): array;

    /** @throws PayrollRunMonthTakenException when a run of the month was added in the meantime */
    public function add(PayrollRun $run): void;

    /** Persists changes made to an already managed run, its paychecks, lines and adjustments. */
    public function save(PayrollRun $run): void;

    public function remove(PayrollRun $run): void;
}
