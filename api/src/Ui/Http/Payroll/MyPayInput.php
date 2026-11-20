<?php

declare(strict_types=1);

namespace Ui\Http\Payroll;

use Symfony\Component\Validator\Constraints as Assert;

/** The ?month= of GET /my-pay, validated by its provider (422). */
final readonly class MyPayInput
{
    public function __construct(
        #[Assert\Regex(pattern: '/^\d{4}-(0[1-9]|1[0-2])$/', message: 'This is not a month (YYYY-MM).')]
        public ?string $month = null,
    ) {
    }
}
