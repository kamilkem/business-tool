<?php

declare(strict_types=1);

namespace App\Ksef\Application\Query\ListKsefOrganizationsToImport;

use App\Shared\Application\Query\QueryInterface;

/**
 * For the scheduled fan-out (ADR 0025 §4), which runs outside any organization: the ids of the
 * organizations whose KSeF connection is active, has a token and is not waiting for KSeF's
 * Retry-After at `$now` (ATOM). The answer is a list<string>; only ids cross organizations, no
 * tenant data. Whether the organization is still in Poland is checked by its own job.
 */
final readonly class ListKsefOrganizationsToImportQuery implements QueryInterface
{
    public function __construct(public string $now)
    {
    }
}
