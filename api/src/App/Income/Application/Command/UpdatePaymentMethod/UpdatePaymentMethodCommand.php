<?php

declare(strict_types=1);

namespace App\Income\Application\Command\UpdatePaymentMethod;

use App\Shared\Application\Command\CommandInterface;

/**
 * An owner or manager renames a payment method, active or deactivated (ADR 0030), and marks it as
 * cash or removes the mark (ADR 0033 §9).
 */
final readonly class UpdatePaymentMethodCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $paymentMethodId,
        public string $name,
        public bool $cash,
    ) {
    }
}
