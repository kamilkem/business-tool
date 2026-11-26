<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Command\DismissProductProposal;

use App\Shared\Application\Command\CommandInterface;

/** An owner or manager dismisses a proposal: its items stay unassigned and the same name is not proposed for them again (ADR 0044). */
final readonly class DismissProductProposalCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $proposalKey,
    ) {
    }
}
