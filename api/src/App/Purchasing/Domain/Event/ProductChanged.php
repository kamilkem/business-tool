<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Event;

use App\Shared\Domain\Event\EventInterface;

/**
 * A person renamed a product or changed its measure (ADR 0046 §1): what the model is shown of a product, so
 * items it found nothing for may match now. Published only when one of the two changed. Primitives only.
 */
final readonly class ProductChanged implements EventInterface
{
    public function __construct(
        public string $organizationId,
        public string $productId,
        public bool $renamed,
        public bool $measureChanged,
    ) {
    }
}
