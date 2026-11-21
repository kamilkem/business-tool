<?php

declare(strict_types=1);

namespace App\Income\Application\Command\DeactivatePaymentMethod;

use App\Shared\Application\Command\CommandInterface;

/** An owner or manager deactivates a payment method: it takes no new lines, its lines stay (ADR 0030 §4). */
final readonly class DeactivatePaymentMethodCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $paymentMethodId,
    ) {
    }
}
