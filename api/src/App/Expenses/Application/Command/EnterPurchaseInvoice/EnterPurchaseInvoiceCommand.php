<?php

declare(strict_types=1);

namespace App\Expenses\Application\Command\EnterPurchaseInvoice;

use App\Shared\Application\Command\CommandInterface;

/**
 * An owner or manager enters an expense by hand (source manual, #54): a cash receipt, a foreign
 * invoice. Amounts in minor units of `currency` (ADR 0010); a null currency is the organization's.
 */
final readonly class EnterPurchaseInvoiceCommand implements CommandInterface
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
        public ?string $currency,
        public int $netTotal,
        public int $vatTotal,
        public int $grossTotal,
        public array $vatLines,
        public ?string $categoryId,
    ) {
    }
}
