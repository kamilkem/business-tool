<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Command\AssignProductProposal;

use App\Shared\Application\Command\CommandInterface;

/**
 * An owner or manager links every item of a proposal to a product that exists: the proposal was the
 * product's other name (ADR 0044). The items become `confirmed`.
 */
final readonly class AssignProductProposalCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $proposalKey,
        public string $productId,
    ) {
    }
}
