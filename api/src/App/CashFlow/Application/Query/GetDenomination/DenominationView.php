<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Query\GetDenomination;

use App\CashFlow\Domain\Model\Denomination;

final readonly class DenominationView
{
    public function __construct(
        public string $id,
        public string $currency,
        /** Face value in minor units. */
        public int $value,
        /** `coin` or `note`. */
        public string $kind,
        public bool $active,
        /** Whether a close or movement uses it: a used denomination cannot be deleted, only deactivated. */
        public bool $used,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $deactivatedAt,
    ) {
    }

    public static function fromDenomination(Denomination $denomination, bool $used): self
    {
        return new self(
            $denomination->getId()->toRfc4122(),
            $denomination->getCurrency(),
            $denomination->getValue(),
            $denomination->getKind()->value,
            $denomination->isActive(),
            $used,
            $denomination->getCreatedAt(),
            $denomination->getDeactivatedAt(),
        );
    }
}
