<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Command\AcceptProductProposal;

use App\Shared\Application\Command\CommandInterface;

/**
 * An owner or manager creates the product a proposal stands for, with the name, measure and category
 * they chose, and links every item of the proposal to it, `confirmed` (ADR 0044). `$proposalKey` is the
 * key of the proposed name (ProposalGroup). The one command with an actor that creates a product from
 * what the model proposed; no job and no event handler does.
 */
final readonly class AcceptProductProposalCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $proposalKey,
        public string $productId,
        public string $name,
        /** A ProductMeasure value. */
        public string $measure,
        public ?string $categoryId = null,
        public ?string $referenceQuantity = null,
    ) {
    }
}
