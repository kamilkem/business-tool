<?php

declare(strict_types=1);

namespace App\Secret\Application\Query\GetOrganizationSecret;

use App\Shared\Application\Query\QueryInterface;

/**
 * A secret's metadata, never its value (ADR 0026 §5), for a consumer that shows whether it is set
 * and when. Null when the secret is not one of the current organization's. The consumer checks its
 * own permissions before asking.
 */
final readonly class GetOrganizationSecretQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $secretId,
    ) {
    }
}
