<?php

declare(strict_types=1);

namespace Ui\Http\Payroll;

use ApiPlatform\Metadata\ApiProperty;
use App\Payroll\Domain\Model\PaymentMethod;
use Symfony\Component\Validator\Constraints as Assert;

/** A date not after today (422), an approved run (409) and a paycheck not marked paid yet (409) are the domain's. */
final readonly class MarkPaycheckPaidInput
{
    public function __construct(
        #[ApiProperty(schema: ['type' => 'string', 'format' => 'date', 'description' => 'The business date it was paid on (YYYY-MM-DD), today or earlier in the organization\'s time zone.'])]
        #[Assert\NotBlank]
        #[Assert\Date]
        public string $paidOn,
        /** How it was paid. */
        public PaymentMethod $method,
    ) {
    }
}
