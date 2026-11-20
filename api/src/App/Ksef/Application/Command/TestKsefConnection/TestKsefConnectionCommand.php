<?php

declare(strict_types=1);

namespace App\Ksef\Application\Command\TestKsefConnection;

use App\Shared\Application\Command\CommandInterface;

/**
 * An owner or manager checks the saved KSeF token against KSeF: full token authentication, a one-day
 * metadata query as the buyer, the session revoked. Nothing is imported. The outcome is recorded on
 * the connection (GetKsefConnectionQuery shows it), not thrown: a rejected or unreadable token makes
 * the connection `credentials_rejected`.
 */
final readonly class TestKsefConnectionCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
    ) {
    }
}
