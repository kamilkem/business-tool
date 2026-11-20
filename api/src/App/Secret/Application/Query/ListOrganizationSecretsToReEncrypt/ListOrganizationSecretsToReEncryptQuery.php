<?php

declare(strict_types=1);

namespace App\Secret\Application\Query\ListOrganizationSecretsToReEncrypt;

use App\Shared\Application\Query\QueryInterface;

/**
 * Ids of the current organization's secrets that are not encrypted with the current key, for a
 * rotation (ADR 0026 §4). Answers list<string>.
 */
final readonly class ListOrganizationSecretsToReEncryptQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
    ) {
    }
}
