<?php

declare(strict_types=1);

namespace App\Expenses\Application\Query\ListPurchaseInvoicesWithoutItems;

use App\Shared\Application\Query\QueryInterface;

/**
 * For an importer reading stored documents again (#230): the ids of the current organization's
 * invoices imported from a source that have a document and no items, by id, after `$afterInvoiceId`
 * (keyset paging), at most `$limit`. The answer is a list<string>; the content comes from
 * GetPurchaseInvoiceDocumentQuery::forImporter(). A manual or unknown source has none.
 */
final readonly class ListPurchaseInvoicesWithoutItemsQuery implements QueryInterface
{
    public const int MAX_LIMIT = 500;

    public function __construct(
        public string $organizationId,
        public string $source,
        public ?string $afterInvoiceId,
        public int $limit,
    ) {
    }
}
