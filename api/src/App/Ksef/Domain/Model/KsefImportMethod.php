<?php

declare(strict_types=1);

namespace App\Ksef\Domain\Model;

/**
 * How an import run fetched the invoices' XML (ADR 0039 §1): one at a time while at most
 * SINGLE_DOWNLOAD_LIMIT invoices are waiting, else in a package export.
 */
enum KsefImportMethod: string
{
    /** KSeF's hourly limit of `GET /invoices/ksef/{ksefNumber}` per NIP; more waiting means the export. */
    public const int SINGLE_DOWNLOAD_LIMIT = 64;
    /** At most this many invoices in one package (`POST /invoices/exports`). */
    public const int PACKAGE_LIMIT = 10_000;

    case Single = 'single';
    case Export = 'export';

    /** The method for a backlog of this many waiting invoices. */
    public static function for(int $waiting): self
    {
        return $waiting > self::SINGLE_DOWNLOAD_LIMIT ? self::Export : self::Single;
    }

    /** How many invoices one run imports at most with this method, for the estimate (ADR 0039 §7). */
    public function invoicesPerRun(): int
    {
        return match ($this) {
            self::Single => self::SINGLE_DOWNLOAD_LIMIT,
            self::Export => self::PACKAGE_LIMIT,
        };
    }
}
