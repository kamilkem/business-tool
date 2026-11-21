<?php

declare(strict_types=1);

namespace Ui\Http\Income;

use Symfony\Component\Validator\Constraints as Assert;

/** Whether the ids are exactly the organization's active methods is the domain's (409). */
final readonly class ReorderPaymentMethodsInput
{
    /** @param list<string> $paymentMethodIds */
    public function __construct(
        /** Every active payment method's id, once, in the new order. */
        #[Assert\Count(min: 1)]
        #[Assert\Unique(message: 'Each payment method may appear only once.')]
        #[Assert\All([new Assert\NotBlank(), new Assert\Uuid()])]
        public array $paymentMethodIds,
    ) {
    }
}
