<?php

declare(strict_types=1);

namespace App\Income\Application\Command\AddPaymentMethod;

use App\Shared\Application\Command\CommandInterface;

/** An owner or manager adds a payment method at the end of the organization's list (ADR 0030). */
final readonly class AddPaymentMethodCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $paymentMethodId,
        public string $name,
        /** Whether the end-of-day close books the counted cash on it (ADR 0033 §9). */
        public bool $cash = false,
    ) {
    }
}
