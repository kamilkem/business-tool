<?php

declare(strict_types=1);

namespace App\Payroll\Application\Query\GetPayrollRun;

use App\Payroll\Domain\Model\PaycheckAdjustment;

final readonly class PaycheckAdjustmentView
{
    public function __construct(
        public string $id,
        public string $label,
        /** Signed minor units. */
        public int $amount,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $updatedAt,
    ) {
    }

    public static function of(PaycheckAdjustment $adjustment): self
    {
        return new self(
            $adjustment->getId()->toRfc4122(),
            $adjustment->getLabel(),
            $adjustment->getAmount(),
            $adjustment->getCreatedAt(),
            $adjustment->getUpdatedAt(),
        );
    }
}
