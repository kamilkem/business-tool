<?php

declare(strict_types=1);

namespace Ui\Http\Payroll\Export;

use App\Organization\Domain\Model\CsvSeparator;
use App\Payroll\Application\Query\ExportPayrollRun\PaycheckExportView;
use App\Payroll\Application\Query\ExportPayrollRun\PaycheckLineExportView;
use App\Payroll\Application\Query\ExportPayrollRun\PayrollRunExportView;
use Ui\Http\Shared\Csv\Csv;
use Ui\Http\Shared\Csv\CsvNumber;

/**
 * The two CSV files of an approved run for the accountant (ADR 0027 §12): one row per paycheck (with its payment, once marked paid), and
 * one row per shift line. Written with the organization's CSV separator and its decimal separator;
 * money as decimal strings from minor units, time as h:mm:ss and as hours with two decimals, the
 * shifts' start and end in the organization's time zone.
 */
final class PayrollRunCsv
{
    public static function paychecks(PayrollRunExportView $run): string
    {
        $separator = CsvSeparator::from($run->csvSeparator);
        $decimal = $separator->decimalSeparator();
        $rows = [['Month', 'First name', 'Last name', 'Paid time', 'Paid hours', 'Pay for hours', 'Adjustments', 'Total', 'Currency', 'Run status', 'Paid on', 'Payment method']];
        foreach ($run->paychecks as $paycheck) {
            $rows[] = [
                $run->month,
                $paycheck->firstName,
                $paycheck->lastName,
                CsvNumber::duration($paycheck->paidSeconds),
                CsvNumber::hours($paycheck->paidSeconds, $decimal),
                CsvNumber::money($paycheck->payForHours, $run->currency, $decimal),
                CsvNumber::money($paycheck->adjustmentsTotal, $run->currency, $decimal),
                CsvNumber::money($paycheck->total, $run->currency, $decimal),
                $run->currency,
                $run->status,
                $paycheck->paidOn ?? '',
                $paycheck->paymentMethod ?? '',
            ];
        }

        return Csv::write($rows, $separator);
    }

    public static function shiftLines(PayrollRunExportView $run): string
    {
        $separator = CsvSeparator::from($run->csvSeparator);
        $decimal = $separator->decimalSeparator();
        $timeZone = new \DateTimeZone($run->timeZone);
        $rows = [['Month', 'First name', 'Last name', 'Date', 'Start', 'End', 'Length', 'Length (hours)', 'Paid length', 'Paid hours', 'Rate kind', 'Rate per hour', 'Amount', 'Currency']];
        foreach ($run->paychecks as $paycheck) {
            foreach ($paycheck->lines as $line) {
                $rows[] = self::shiftLine($run, $paycheck, $line, $decimal, $timeZone);
            }
        }

        return Csv::write($rows, $separator);
    }

    public static function fileName(PayrollRunExportView $run, string $part): string
    {
        return \sprintf('payroll-%s-%s.csv', $run->month, $part);
    }

    /** @return list<string> */
    private static function shiftLine(PayrollRunExportView $run, PaycheckExportView $paycheck, PaycheckLineExportView $line, string $decimal, \DateTimeZone $timeZone): array
    {
        return [
            $run->month,
            $paycheck->firstName,
            $paycheck->lastName,
            $line->date,
            $line->startedAt?->setTimezone($timeZone)->format('Y-m-d H:i') ?? '',
            $line->endedAt?->setTimezone($timeZone)->format('Y-m-d H:i') ?? '',
            CsvNumber::duration($line->seconds),
            CsvNumber::hours($line->seconds, $decimal),
            CsvNumber::duration($line->paidSeconds),
            CsvNumber::hours($line->paidSeconds, $decimal),
            $line->rateKind ?? '',
            null === $line->rate ? '' : CsvNumber::money($line->rate, $run->currency, $decimal),
            null === $line->amount ? '' : CsvNumber::money($line->amount, $run->currency, $decimal),
            $run->currency,
        ];
    }
}
