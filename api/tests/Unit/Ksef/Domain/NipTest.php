<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ksef\Domain;

use App\Ksef\Domain\Model\Nip;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Nip::class)]
final class NipTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function validTaxIds(): iterable
    {
        yield 'digits' => ['1111111111', '1111111111'];
        yield 'with hyphens' => ['526-025-09-95', '5260250995'];
        yield 'with spaces' => [' 222 222 22 22 ', '2222222222'];
        yield 'EU VAT form' => ['PL5260250995', '5260250995'];
        yield 'EU VAT form, lower case' => ['pl 526-025-09-95', '5260250995'];
    }

    #[DataProvider('validTaxIds')]
    public function test_it_reads_a_valid_nip_from_the_tax_id(string $taxId, string $nip): void
    {
        self::assertSame($nip, Nip::tryFromTaxId($taxId)?->value);
    }

    /** @return iterable<string, array{?string}> */
    public static function invalidTaxIds(): iterable
    {
        yield 'none' => [null];
        yield 'empty' => [''];
        yield 'wrong checksum' => ['1111111112'];
        yield 'checksum of 10' => ['1234567890'];
        yield 'nine digits' => ['111111111'];
        yield 'eleven digits' => ['11111111111'];
        yield 'letters' => ['11111A1111'];
        yield 'another country' => ['DE5260250995'];
    }

    #[DataProvider('invalidTaxIds')]
    public function test_it_refuses_anything_else(?string $taxId): void
    {
        self::assertNull(Nip::tryFromTaxId($taxId));
    }
}
