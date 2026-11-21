<?php

declare(strict_types=1);

namespace App\Income\Application\Command\ReorderPaymentMethods;

use App\Shared\Application\Command\CommandInterface;

/**
 * An owner or manager puts the organization's active payment methods in a new order (ADR 0030 §3).
 * The one command that changes several rows: the order is the list's, not one method's.
 */
final readonly class ReorderPaymentMethodsCommand implements CommandInterface
{
    /** @param list<string> $paymentMethodIds every active method's id, once, in the new order */
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public array $paymentMethodIds,
    ) {
    }
}
