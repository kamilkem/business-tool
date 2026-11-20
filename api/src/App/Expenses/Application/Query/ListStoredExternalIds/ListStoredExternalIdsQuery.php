<?php

declare(strict_types=1);

namespace App\Expenses\Application\Query\ListStoredExternalIds;

use App\Shared\Application\Query\QueryInterface;

/**
 * Which of these ids in a source (e.g. KSeF numbers) the current organization already stores, so an
 * importer skips them without downloading them again (ADR 0024 §1). The answer is a list<string>, a
 * subset of `$externalIds`; an unknown source stores none.
 */
final readonly class ListStoredExternalIdsQuery implements QueryInterface
{
    /** @param list<string> $externalIds */
    public function __construct(
        public string $organizationId,
        public string $source,
        public array $externalIds,
    ) {
    }
}
