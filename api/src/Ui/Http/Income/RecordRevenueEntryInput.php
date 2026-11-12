<?php

declare(strict_types=1);

namespace Ui\Http\Income;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A register's daily report. The currency is the organization's, never the client's (ADR 0022 §5);
 * whether the register is active, the date not after today and the VAT rates the country's, the
 * domain checks.
 */
final readonly class RecordRevenueEntryInput
{
    /**
     * @param list<PaymentLineInput> $payments
     * @param list<VatLineInput>     $vatLines
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $registerId,
        /** The business date the daily report covers, not after today in the organization's time zone. */
        #[ApiProperty(schema: ['type' => 'string', 'format' => 'date', 'description' => 'The business date the daily report covers (YYYY-MM-DD), not after today in the organization\'s time zone.'])]
        #[Assert\NotBlank]
        #[Assert\Date]
        public string $businessDate,
        /** At least one line, each payment method once; zero amounts are allowed. */
        #[Assert\Count(min: 1)]
        #[Assert\Unique(normalizer: [PaymentLineInput::class, 'methodOf'], message: 'Each payment method may appear only once.')]
        #[Assert\Valid]
        public array $payments,
        /** The VAT breakdown as printed, each rate once; optional. */
        #[Assert\Unique(normalizer: [VatLineInput::class, 'rateOf'], message: 'Each VAT rate may appear only once.')]
        #[Assert\Valid]
        public array $vatLines = [],
        #[Assert\Length(max: UpdateRevenueEntryInput::NOTE_MAX_LENGTH, normalizer: 'trim')]
        public ?string $note = null,
    ) {
    }
}
