<?php

declare(strict_types=1);

namespace App\Ksef\Domain\Model;

/** The status of a package export, with the package once it is ready (ADR 0039 §4, §6). */
final readonly class KsefInvoiceExportStatus
{
    private function __construct(
        public KsefInvoiceExportState $state,
        public ?KsefInvoicePackage $package = null,
        /** For Failed: KSeF's status code and description. */
        public ?string $failure = null,
        /** For Failed: KSeF says to try again (500, 550), as opposed to a request it cannot serve. */
        public bool $transient = false,
    ) {
    }

    public static function inProgress(): self
    {
        return new self(KsefInvoiceExportState::InProgress);
    }

    public static function ready(KsefInvoicePackage $package): self
    {
        return new self(KsefInvoiceExportState::Ready, $package);
    }

    public static function expired(): self
    {
        return new self(KsefInvoiceExportState::Expired);
    }

    public static function nothingToExport(): self
    {
        return new self(KsefInvoiceExportState::NothingToExport);
    }

    public static function failed(string $failure, bool $transient): self
    {
        return new self(KsefInvoiceExportState::Failed, null, $failure, $transient);
    }
}
