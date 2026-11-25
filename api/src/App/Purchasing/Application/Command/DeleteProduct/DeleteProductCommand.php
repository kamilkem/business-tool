<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Command\DeleteProduct;

use App\Shared\Application\Command\CommandInterface;

/** An owner or manager deletes a product that no purchased item refers to (ADR 0041 §4). */
final readonly class DeleteProductCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $productId,
    ) {
    }
}
