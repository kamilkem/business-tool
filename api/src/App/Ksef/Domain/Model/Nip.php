<?php

declare(strict_types=1);

namespace App\Ksef\Domain\Model;

/**
 * A Polish tax ID (NIP): ten digits whose last one is the checksum of the first nine (weights
 * 6, 5, 7, 2, 3, 4, 5, 6, 7, modulo 11; a remainder of 10 is never valid). KSeF identifies the
 * organization by it (ADR 0023 §3).
 */
final readonly class Nip
{
    private const array WEIGHTS = [6, 5, 7, 2, 3, 4, 5, 6, 7];

    private function __construct(public string $value)
    {
    }

    /**
     * The organization's tax ID as written in its settings: spaces and hyphens are ignored, and so is
     * a leading "PL" (the EU VAT number form). Null when it is not a valid NIP.
     */
    public static function tryFromTaxId(?string $taxId): ?self
    {
        if (null === $taxId) {
            return null;
        }

        $digits = (string) preg_replace('/[\s-]/', '', $taxId);
        if (str_starts_with(strtoupper($digits), 'PL')) {
            $digits = substr($digits, 2);
        }

        return self::isValid($digits) ? new self($digits) : null;
    }

    private static function isValid(string $digits): bool
    {
        if (1 !== preg_match('/^\d{10}$/', $digits)) {
            return false;
        }

        $sum = 0;
        foreach (self::WEIGHTS as $position => $weight) {
            $sum += $weight * (int) $digits[$position];
        }
        $checksum = $sum % 11;

        return 10 !== $checksum && $checksum === (int) $digits[9];
    }
}
