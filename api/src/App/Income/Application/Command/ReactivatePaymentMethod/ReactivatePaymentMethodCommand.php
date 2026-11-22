<?php

declare(strict_types=1);

namespace App\Income\Application\Command\ReactivatePaymentMethod;

use App\Shared\Application\Command\CommandInterface;

/**
 * An owner or manager reactivates a payment method; it goes to the end of the list (ADR 0030 §4). A
 * method marked as cash is refused while another active method is (ADR 0033 §9).
 */
final readonly class ReactivatePaymentMethodCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $paymentMethodId,
    ) {
    }
}
