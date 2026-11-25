<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Command\MergeProduct;

use App\Shared\Application\Command\CommandInterface;

/**
 * An owner or manager merges a product into another one: the purchased items move to the target,
 * each keeping its status and package size, and the merged product is deleted (ADR 0041 §4).
 */
final readonly class MergeProductCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $productId,
        public string $intoProductId,
    ) {
    }
}
