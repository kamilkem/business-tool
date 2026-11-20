<?php

declare(strict_types=1);

namespace App\Secret\Application\Query\RevealOrganizationSecret;

/**
 * A secret's plaintext (ADR 0026 §5–6). Kept in memory for the use it was revealed for and dropped;
 * never logged, serialized or returned through the API (Deptrac keeps it out of Ui).
 */
final readonly class RevealedSecretView
{
    public function __construct(
        public string $id,
        public string $kind,
        #[\SensitiveParameter]
        public string $value,
    ) {
    }

    /** @return array{id: string, kind: string, value: string} */
    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'kind' => $this->kind, 'value' => '***'];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('A revealed secret cannot be serialized.');
    }
}
