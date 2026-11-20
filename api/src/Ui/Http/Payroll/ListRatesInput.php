<?php

declare(strict_types=1);

namespace Ui\Http\Payroll;

use Symfony\Component\Validator\Constraints as Assert;

/** The ?userId= of GET /hourly-rates and GET /day-rates, validated by their collection providers (422). */
final readonly class ListRatesInput
{
    public function __construct(
        #[Assert\Uuid]
        public ?string $userId = null,
    ) {
    }
}
