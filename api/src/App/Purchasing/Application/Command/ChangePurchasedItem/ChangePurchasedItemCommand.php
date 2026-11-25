<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Command\ChangePurchasedItem;

use App\Shared\Application\Command\CommandInterface;

/**
 * An owner or manager links a purchased item to a product, or takes it off its product, and sets its
 * package size. Every value is the new one: the caller sends the current value of what it does not
 * change. The item becomes `confirmed` with a product and `unassigned` without one (ADR 0041 §3).
 */
final readonly class ChangePurchasedItemCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $itemId,
        public ?string $productId,
        public ?string $packageSize,
    ) {
    }
}
