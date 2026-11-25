<?php

declare(strict_types=1);

namespace App\Ksef\Application\Command\FinishKsefInvoiceExport;

use App\Shared\Application\Command\CommandInterface;

/** The import is done with this package export: imported, expired or failed (ADR 0039 §5–6). */
final readonly class FinishKsefInvoiceExportCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $referenceNumber,
    ) {
    }
}
