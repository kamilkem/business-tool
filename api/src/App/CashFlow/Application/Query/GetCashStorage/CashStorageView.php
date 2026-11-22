<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Query\GetCashStorage;

use App\CashFlow\Domain\Model\CashStorage;

final readonly class CashStorageView
{
    public function __construct(
        public string $id,
        public string $name,
        /** `counted` or `deposit`. */
        public string $mode,
        public bool $active,
        /** Whether a close or movement refers to it: a used storage cannot be deleted, only deactivated. */
        public bool $used,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $deactivatedAt,
    ) {
    }

    public static function fromStorage(CashStorage $storage, bool $used): self
    {
        return new self(
            $storage->getId()->toRfc4122(),
            $storage->getName(),
            $storage->getMode()->value,
            $storage->isActive(),
            $used,
            $storage->getCreatedAt(),
            $storage->getDeactivatedAt(),
        );
    }
}
