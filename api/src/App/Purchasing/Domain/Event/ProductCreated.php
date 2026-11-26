<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Event;

use App\Shared\Domain\Event\EventInterface;

/** A person created a product (ADR 0044): the items the model has not been through are put to it again, with the bigger catalog. Primitives only. */
final readonly class ProductCreated implements EventInterface
{
    public function __construct(
        public string $organizationId,
        public string $productId,
    ) {
    }
}
