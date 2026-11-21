<?php

declare(strict_types=1);

namespace App\Income\Application\Command\DeletePaymentMethod;

use App\Shared\Application\Command\CommandInterface;

/** An owner or manager deletes a payment method no revenue entry has used (ADR 0030 §4). */
final readonly class DeletePaymentMethodCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $paymentMethodId,
    ) {
    }
}
