<?php

declare(strict_types=1);

namespace App\Income\Application\Query\GetRegister;

use App\Income\Domain\Model\Register;

final readonly class RegisterView
{
    public function __construct(
        public string $id,
        public string $name,
        public bool $active,
        /** Whether a revenue entry refers to it: a used register cannot be deleted, only deactivated. */
        public bool $used,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $deactivatedAt,
    ) {
    }

    public static function fromRegister(Register $register, bool $used): self
    {
        return new self(
            $register->getId()->toRfc4122(),
            $register->getName(),
            $register->isActive(),
            $used,
            $register->getCreatedAt(),
            $register->getDeactivatedAt(),
        );
    }
}
