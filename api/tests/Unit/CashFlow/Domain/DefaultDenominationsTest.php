<?php

declare(strict_types=1);

namespace App\Tests\Unit\CashFlow\Domain;

use App\CashFlow\Domain\Model\DefaultDenominations;
use App\CashFlow\Domain\Model\DenominationKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DefaultDenominations::class)]
final class DefaultDenominationsTest extends TestCase
{
    public function test_it_lists_every_polish_coin_and_note_lowest_first(): void
    {
        $defaults = DefaultDenominations::forCurrency('PLN');

        self::assertSame(
            [1, 2, 5, 10, 20, 50, 100, 200, 500, 1_000, 2_000, 5_000, 10_000, 20_000, 50_000],
            array_column($defaults, 'value'),
        );
        self::assertSame(
            [...array_fill(0, 9, DenominationKind::Coin), ...array_fill(0, 6, DenominationKind::Note)],
            array_column($defaults, 'kind'),
        );
    }

    public function test_it_reads_the_currency_in_any_case(): void
    {
        self::assertSame(DefaultDenominations::forCurrency('PLN'), DefaultDenominations::forCurrency('pln'));
    }

    public function test_it_has_none_for_a_currency_without_a_list(): void
    {
        self::assertSame([], DefaultDenominations::forCurrency('EUR'));
    }
}
