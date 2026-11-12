<?php

declare(strict_types=1);

namespace App\Expenses\Domain\Model;

/**
 * Where a purchase invoice came from. An imported one carries the id it has in its source
 * (PurchaseInvoice::getExternalId()); Expenses treats that id as opaque (ADR 0024).
 */
enum PurchaseInvoiceSource: string
{
    case Ksef = 'ksef';
    case Manual = 'manual';
}
