<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ui\Http\Payroll;

use App\Payroll\Application\Query\ExportPayrollRun\PaycheckExportView;
use App\Payroll\Application\Query\ExportPayrollRun\PaycheckLineExportView;
use App\Payroll\Application\Query\ExportPayrollRun\PayrollRunExportView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ui\Http\Payroll\Export\PayrollRunCsv;

/** October 2026 in Europe/Warsaw (summer time ends on 25 October), in PLN. */
#[CoversClass(PayrollRunCsv::class)]
final class PayrollRunCsvTest extends TestCase
{
    private const string BOM = "\u{FEFF}";

    public function test_it_writes_one_row_per_paycheck_for_a_spreadsheet_with_a_decimal_comma(): void
    {
        self::assertSame(
            self::BOM
            ."Month;First name;Last name;Paid time;Paid hours;Pay for hours;Adjustments;Total;Currency;Run status;Paid on;Payment method\r\n"
            ."2026-10;Anna;Kowalska;11:15:00;11,25;371,24;-50,00;321,24;PLN;approved;2026-11-05;transfer\r\n"
            ."2026-10;Jan;\"Nowak; Jr\";0:20:00;0,33;10,00;200,00;210,00;PLN;approved;;\r\n",
            PayrollRunCsv::paychecks(self::octoberRun('semicolon')),
        );
    }

    public function test_it_writes_one_row_per_shift_line_with_times_in_the_organizations_time_zone(): void
    {
        self::assertSame(
            self::BOM
            ."Month;First name;Last name;Date;Start;End;Length;Length (hours);Paid length;Paid hours;Rate kind;Rate per hour;Amount;Currency\r\n"
            ."2026-10;Anna;Kowalska;2026-10-03;2026-10-03 18:00;2026-10-03 22:08;4:08:00;4,13;4:15:00;4,25;hourly;30,00;127,50;PLN\r\n"
            ."2026-10;Anna;Kowalska;2026-10-31;2026-10-31 20:00;2026-11-01 03:00;7:00:00;7,00;7:00:00;7,00;day;34,82;243,74;PLN\r\n"
            ."2026-10;Jan;\"Nowak; Jr\";2026-10-10;;;0:20:00;0,33;0:20:00;0,33;hourly;30,00;10,00;PLN\r\n",
            PayrollRunCsv::shiftLines(self::octoberRun('semicolon')),
        );
    }

    public function test_it_writes_a_comma_separated_file_with_a_decimal_point(): void
    {
        $paychecks = PayrollRunCsv::paychecks(self::octoberRun('comma'));

        self::assertStringContainsString("\r\n2026-10,Anna,Kowalska,11:15:00,11.25,371.24,-50.00,321.24,PLN,approved,2026-11-05,transfer\r\n", $paychecks);
        self::assertStringContainsString("\r\n2026-10,Jan,Nowak; Jr,0:20:00,0.33,10.00,200.00,210.00,PLN,approved,,\r\n", $paychecks);
    }

    public function test_it_names_the_files_after_the_month(): void
    {
        self::assertSame('payroll-2026-10-paychecks.csv', PayrollRunCsv::fileName(self::octoberRun('comma'), 'paychecks'));
    }

    private static function octoberRun(string $csvSeparator): PayrollRunExportView
    {
        return new PayrollRunExportView('0190a0a0-0000-7000-8000-000000000001', '2026-10', '2026-10-01', '2026-10-31', 'approved', 'PLN', 'Europe/Warsaw', $csvSeparator, [
            new PaycheckExportView('0190a0a0-0000-7000-8000-00000000000a', 'Anna', 'Kowalska', 40_500, 37_124, -5_000, 32_124, '2026-11-05', 'transfer', [
                // 18:00–22:08 Warsaw summer time, rounded to 15 minutes.
                new PaycheckLineExportView('0190a0a0-0000-7000-8000-0000000000a1', '2026-10-03', new \DateTimeImmutable('2026-10-03 16:00:00Z'), new \DateTimeImmutable('2026-10-03 20:08:00Z'), 14_880, 15_300, 'hourly', 3_000, 12_750),
                // A night into November belongs to 31 October, winter time.
                new PaycheckLineExportView('0190a0a0-0000-7000-8000-0000000000a2', '2026-10-31', new \DateTimeImmutable('2026-10-31 19:00:00Z'), new \DateTimeImmutable('2026-11-01 02:00:00Z'), 25_200, 25_200, 'day', 3_482, 24_374),
            ]),
            new PaycheckExportView('0190a0a0-0000-7000-8000-00000000000b', 'Jan', 'Nowak; Jr', 1_200, 1_000, 20_000, 21_000, null, null, [
                new PaycheckLineExportView('0190a0a0-0000-7000-8000-0000000000b1', '2026-10-10', null, null, 1_200, 1_200, 'hourly', 3_000, 1_000),
            ]),
        ]);
    }
}
