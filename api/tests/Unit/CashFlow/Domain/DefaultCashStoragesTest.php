<?php

declare(strict_types=1);

namespace App\Tests\Unit\CashFlow\Domain;

use App\CashFlow\Domain\Model\CashStorageMode;
use App\CashFlow\Domain\Model\DefaultCashStorages;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DefaultCashStorages::class)]
final class DefaultCashStoragesTest extends TestCase
{
    public function test_it_gives_poland_a_counted_cash_box_and_a_deposit_safe_in_polish(): void
    {
        self::assertSame(
            [['name' => 'Kasetka', 'mode' => CashStorageMode::Counted], ['name' => 'Sejf', 'mode' => CashStorageMode::Deposit]],
            DefaultCashStorages::forCountry('PL'),
        );
    }

    public function test_it_reads_the_country_in_any_case(): void
    {
        self::assertSame(DefaultCashStorages::forCountry('PL'), DefaultCashStorages::forCountry('pl'));
    }

    public function test_it_names_them_in_english_in_every_other_country(): void
    {
        foreach (['DE', 'GB', 'CZ'] as $country) {
            self::assertSame(
                [['name' => 'Cash box', 'mode' => CashStorageMode::Counted], ['name' => 'Safe', 'mode' => CashStorageMode::Deposit]],
                DefaultCashStorages::forCountry($country),
                $country,
            );
        }
    }
}
