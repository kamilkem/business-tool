<?php

declare(strict_types=1);

namespace App\Ksef\Infrastructure\Http;

/**
 * KSeF's default API limits on production (limity/limity-api.md, read on 2026-11-24): requests allowed
 * per endpoint in sliding windows of one second, one minute and one hour, counted per pair (context,
 * client IP address). The higher limits from 20:00 to 06:00 have no published values yet, so the
 * daytime ones apply around the clock.
 */
enum KsefApiLimit
{
    /** `POST /invoices/query/metadata`. */
    case InvoiceMetadataQuery;
    /** `GET /invoices/ksef/{ksefNumber}`. */
    case InvoiceDownload;
    /** Every other protected endpoint ("Pozostałe"), each with its own counter. */
    case Other;

    /** @return non-empty-array<int, int> requests allowed, by window length in seconds */
    public function perWindow(): array
    {
        return match ($this) {
            self::InvoiceMetadataQuery => [1 => 8, 60 => 16, 3600 => 20],
            self::InvoiceDownload => [1 => 8, 60 => 16, 3600 => 64],
            self::Other => [1 => 10, 60 => 30, 3600 => 120],
        };
    }

    /** The longest window, for how long sent requests are remembered. */
    public function longestWindowSeconds(): int
    {
        return max(array_keys($this->perWindow()));
    }
}
