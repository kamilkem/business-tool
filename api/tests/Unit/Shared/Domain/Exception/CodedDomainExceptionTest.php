<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Exception;

use App\Income\Domain\Exception\InvalidPaymentLinesException;
use App\Income\Domain\Exception\RegisterNameTakenException;
use App\Ksef\Domain\Exception\InvalidFa3InvoiceException;
use App\Ksef\Domain\Exception\KsefTokenRejectedException;
use App\Payroll\Domain\Exception\PayrollRunNotApprovableException;
use App\Secret\Domain\Exception\SecretUnreadableException;
use App\Shared\Domain\Exception\CodedDomainException;
use App\Shared\Domain\Time\BusinessPeriod;
use App\TimeTracking\Domain\Exception\ShiftPeriodTooLongException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CodedDomainExceptionTest extends TestCase
{
    /** @return iterable<string, array{CodedDomainException, string}> */
    public static function codes(): iterable
    {
        yield 'module and class' => [RegisterNameTakenException::of('Bar'), 'income.register_name_taken'];
        yield 'a leading module name is dropped' => [KsefTokenRejectedException::create(), 'ksef.token_rejected'];
        yield 'a module of two words' => [ShiftPeriodTooLongException::of(BusinessPeriod::fromStrings('2026-01-01', '2026-12-31'), 62), 'time_tracking.shift_period_too_long'];
        yield 'digits stay with their word' => [InvalidFa3InvoiceException::doctype(), 'ksef.invalid_fa3_invoice.doctype'];
        yield 'the variant of a named constructor' => [PayrollRunNotApprovableException::openShifts(2), 'payroll.run_not_approvable.open_shifts'];
        yield 'a variant without parameters' => [InvalidPaymentLinesException::empty(), 'income.invalid_payment_lines.empty'];
    }

    #[DataProvider('codes')]
    public function test_it_derives_the_code_from_the_names(CodedDomainException $exception, string $code): void
    {
        self::assertSame($code, $exception->errorCode());
    }

    public function test_it_carries_primitive_parameters_next_to_the_english_message(): void
    {
        $exception = ShiftPeriodTooLongException::of(BusinessPeriod::fromStrings('2026-01-01', '2026-03-31'), 62);

        self::assertSame(['from' => '2026-01-01', 'to' => '2026-03-31', 'days' => 90, 'maxDays' => 62], $exception->errorParameters());
        self::assertSame('Shifts are listed for at most 62 days at a time; 2026-01-01 to 2026-03-31 is 90 days.', $exception->getMessage());
    }

    public function test_it_keeps_the_previous_exception(): void
    {
        $previous = SecretUnreadableException::unknownKey('k1');

        $exception = SecretUnreadableException::ofSecret('s1', $previous);

        self::assertSame($previous, $exception->getPrevious());
        self::assertSame('secret.unreadable.of_secret', $exception->errorCode());
        self::assertSame(['secretId' => 's1'], $exception->errorParameters());
    }
}
