<?php

declare(strict_types=1);

namespace App\Expenses\Application\Command\CategorizePurchaseInvoice;

use App\Shared\Application\Command\CommandInterface;

/** An owner or manager sets the expense category of a purchase invoice, or clears it with null. */
final readonly class CategorizePurchaseInvoiceCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $invoiceId,
        public ?string $categoryId,
    ) {
    }
}
