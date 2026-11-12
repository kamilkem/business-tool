<?php

declare(strict_types=1);

namespace App\Expenses\Domain\Model;

/** The kind of a purchase invoice, country-neutral; an importer maps its own codes onto it. */
enum PurchaseInvoiceKind: string
{
    case Regular = 'regular';
    case Correction = 'correction';
    case Advance = 'advance';
    case Settlement = 'settlement';
    case Simplified = 'simplified';
    case AdvanceCorrection = 'advance_correction';
    case SettlementCorrection = 'settlement_correction';

    /** A correction may refer to the invoice it corrects and may carry negative amounts. */
    public function isCorrection(): bool
    {
        return match ($this) {
            self::Correction, self::AdvanceCorrection, self::SettlementCorrection => true,
            default => false,
        };
    }
}
