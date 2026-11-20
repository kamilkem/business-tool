<?php

declare(strict_types=1);

namespace App\Expenses\Application\Command\DeletePurchaseInvoice;

use App\Shared\Application\Command\CommandInterface;

/** An owner or manager deletes an expense entered by hand; no trace is kept (#54). */
final readonly class DeletePurchaseInvoiceCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $invoiceId,
    ) {
    }
}
