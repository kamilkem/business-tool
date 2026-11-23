<?php

declare(strict_types=1);

namespace App\Tests\Unit\Expenses\Domain;

use App\Expenses\Domain\Model\SellerKey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SellerKey::class)]
final class SellerKeyTest extends TestCase
{
    /** @return iterable<string, array{?string, ?string}> */
    public static function taxIds(): iterable
    {
        yield 'digits' => ['5260250995', '5260250995'];
        yield 'dashes and spaces' => [' 526-025 09-95 ', '5260250995'];
        yield 'letters upper-cased' => ['de 123456789', 'DE123456789'];
        yield 'none' => [null, null];
        yield 'blank' => ['  ', null];
        yield 'dashes only' => [' - ', null];
    }

    #[DataProvider('taxIds')]
    public function test_it_matches_tax_ids_without_spaces_or_dashes(?string $taxId, ?string $key): void
    {
        self::assertSame($key, SellerKey::ofTaxId($taxId));
    }

    public function test_it_matches_names_ignoring_case_and_surrounding_spaces(): void
    {
        self::assertSame('żabka polska', SellerKey::ofName('  ŻABKA Polska '));
        self::assertSame(SellerKey::ofName('Biedronka'), SellerKey::ofName('biedronka'));
        self::assertNotSame(SellerKey::ofName('Biedronka 1'), SellerKey::ofName('Biedronka'));
        self::assertSame('', SellerKey::ofName('   '));
    }
}
