<?php

declare(strict_types=1);

namespace App\Income\Application\Command\RenamePaymentMethod;

use App\Shared\Application\Command\CommandInterface;

/** An owner or manager renames a payment method, active or deactivated (ADR 0030). */
final readonly class RenamePaymentMethodCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $paymentMethodId,
        public string $name,
    ) {
    }
}
