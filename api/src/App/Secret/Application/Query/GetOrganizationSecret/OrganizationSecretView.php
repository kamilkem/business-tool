<?php

declare(strict_types=1);

namespace App\Secret\Application\Query\GetOrganizationSecret;

use App\Secret\Domain\Model\OrganizationSecret;

/** Metadata of a secret: no value, no ciphertext (ADR 0026 §5). */
final readonly class OrganizationSecretView
{
    public function __construct(
        public string $id,
        public string $kind,
        public \DateTimeImmutable $setAt,
        public string $setBy,
    ) {
    }

    public static function fromSecret(OrganizationSecret $secret): self
    {
        return new self(
            $secret->getId()->toRfc4122(),
            $secret->getKind()->value,
            $secret->getSetAt(),
            $secret->getSetBy()->toRfc4122(),
        );
    }
}
