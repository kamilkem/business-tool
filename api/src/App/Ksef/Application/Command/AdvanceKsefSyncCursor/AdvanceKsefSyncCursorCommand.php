<?php

declare(strict_types=1);

namespace App\Ksef\Application\Command\AdvanceKsefSyncCursor;

use App\Shared\Application\Command\CommandInterface;

/**
 * The import got this far (ADR 0023 §4): every invoice KSeF stored before `$cursor` (an ATOM instant)
 * is imported or skipped. Sent by the import job after each unit, so an interrupted run continues
 * from here (ADR 0025 §3).
 */
final readonly class AdvanceKsefSyncCursorCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $cursor,
    ) {
    }
}
