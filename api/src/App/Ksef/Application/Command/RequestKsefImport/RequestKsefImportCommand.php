<?php

declare(strict_types=1);

namespace App\Ksef\Application\Command\RequestKsefImport;

use App\Shared\Application\Command\CommandInterface;

/**
 * "Import now" by an owner or manager (ADR 0023 §5): records the request on the connection and queues
 * the organization's import job (ADR 0025), deduplicated with the scheduled run.
 */
final readonly class RequestKsefImportCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
    ) {
    }
}
