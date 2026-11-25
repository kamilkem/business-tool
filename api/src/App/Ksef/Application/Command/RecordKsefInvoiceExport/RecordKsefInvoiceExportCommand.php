<?php

declare(strict_types=1);

namespace App\Ksef\Application\Command\RecordKsefInvoiceExport;

use App\Shared\Application\Command\CommandInterface;

/**
 * The import requested a package export (ADR 0039 §2–3): kept on the connection so that a later run
 * can import the package. Instants are ATOM strings; the key id is a UUID, not a secret.
 */
final readonly class RecordKsefInvoiceExportCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $referenceNumber,
        public string $keyId,
        public string $from,
        public string $to,
        public string $startedAt,
    ) {
    }
}
