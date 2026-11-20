<?php

declare(strict_types=1);

namespace App\Secret\Application\Query\RevealOrganizationSecret;

use App\Shared\Application\Query\QueryInterface;

/**
 * A secret's plaintext, for a consumer's Application code only (ADR 0026 §5: KSeF's test connection
 * and sync). Deptrac forbids Ui code to depend on this query or its view, so no provider or processor
 * can return a value. Null when the secret is not one of the current organization's or is of another
 * kind than expected.
 */
final readonly class RevealOrganizationSecretQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $secretId,
        /** A SecretKind value: a secret of another kind is not revealed. */
        public string $expectedKind,
    ) {
    }
}
