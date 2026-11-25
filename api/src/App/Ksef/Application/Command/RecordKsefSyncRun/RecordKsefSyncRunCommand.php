<?php

declare(strict_types=1);

namespace App\Ksef\Application\Command\RecordKsefSyncRun;

use App\Shared\Application\Command\CommandInterface;

/**
 * The outcome of one import run (ADR 0023 §3, §5): counts, the error that ended it (a KsefErrorCode
 * value) and, after a 429, when KSeF allows the next attempt (ATOM); how it fetched the invoices (a
 * KsefImportMethod value) and how many it knew were still waiting, exactly or at least (ADR 0039 §7).
 * Instants are ATOM strings.
 */
final readonly class RecordKsefSyncRunCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $ranAt,
        public int $imported,
        public int $skipped,
        public ?string $error = null,
        public ?string $errorMessage = null,
        public ?string $retryNotBefore = null,
        public ?string $method = null,
        public ?int $backlogCount = null,
        public bool $backlogIsMinimum = false,
    ) {
    }
}
