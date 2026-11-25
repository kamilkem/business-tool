<?php

declare(strict_types=1);

namespace App\Expenses\Application\Query\GetPurchaseInvoiceDocument;

use App\Shared\Application\Query\QueryInterface;

/**
 * The original document of a purchase invoice of the current organization (TenantFilter), from the
 * local copy (ADR 0023 §6, ADR 0024), for an owner or manager. Returns null when the invoice has no
 * document, does not exist there, or the actor is not an active member; throws when the actor is an
 * employee (ADR 0021).
 *
 * An importer reading its stored documents again (#230) has no actor: forImporter() asks for the
 * document inside the organization its job runs in (the tenant), never from Ui.
 */
final readonly class GetPurchaseInvoiceDocumentQuery implements QueryInterface
{
    /** @param ?string $actorId null only for an importer (forImporter()) */
    public function __construct(
        public string $organizationId,
        public ?string $actorId,
        public string $invoiceId,
    ) {
    }

    public static function forImporter(string $organizationId, string $invoiceId): self
    {
        return new self($organizationId, null, $invoiceId);
    }
}
