<?php

declare(strict_types=1);

namespace App\Income\Application\Query\GetPaymentMethod;

use App\Income\Domain\Model\PaymentMethod;

final readonly class PaymentMethodView
{
    public function __construct(
        public string $id,
        public string $name,
        public bool $active,
        /** Whether the end-of-day close books the counted cash on it (ADR 0033 §9). */
        public bool $cash,
        /** Whether a revenue entry has a line for it: a used method cannot be deleted, only deactivated. */
        public bool $used,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $deactivatedAt,
    ) {
    }

    public static function fromPaymentMethod(PaymentMethod $method, bool $used): self
    {
        return new self(
            $method->getId()->toRfc4122(),
            $method->getName(),
            $method->isActive(),
            $method->isCash(),
            $used,
            $method->getCreatedAt(),
            $method->getDeactivatedAt(),
        );
    }
}
