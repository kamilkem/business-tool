<?php

declare(strict_types=1);

namespace App\Expenses\Application\Command\ChangePurchaseInvoice;

use App\Shared\Application\Command\CommandInterface;

/**
 * An owner or manager edits an expense (#54): the whole of what a manual one says, and the category
 * of any. An imported expense accepts only the details it already has, so its category can change
 * alone. A null category clears it.
 */
final readonly class ChangePurchaseInvoiceCommand implements CommandInterface
{
    /** @param list<array{rate: string, net: int, vat: int, vatInTaxCurrency: ?int}> $vatLines */
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $invoiceId,
        public ?string $invoiceNumber,
        public ?string $sellerTaxId,
        public string $sellerName,
        /** YYYY-MM-DD. */
        public string $issueDate,
        public string $kind,
        public string $currency,
        public int $netTotal,
        public int $vatTotal,
        public int $grossTotal,
        public array $vatLines,
        public ?string $categoryId,
    ) {
    }
}
