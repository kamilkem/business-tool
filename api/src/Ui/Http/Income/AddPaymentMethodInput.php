<?php

declare(strict_types=1);

namespace Ui\Http\Income;

use App\Income\Domain\Model\PaymentMethod;
use Symfony\Component\Validator\Constraints as Assert;

/** Uniqueness of the name in the organization, and the one active cash method, are the domain's (409). */
final readonly class AddPaymentMethodInput
{
    public function __construct(
        /** Trimmed; unique in the organization, compared as written ("Card" and "card" are two names). */
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: PaymentMethod::NAME_MAX_LENGTH, normalizer: 'trim')]
        public string $name,
        /** Whether the end-of-day close books the counted cash on it (ADR 0033 §9). */
        public bool $cash = false,
    ) {
    }
}
