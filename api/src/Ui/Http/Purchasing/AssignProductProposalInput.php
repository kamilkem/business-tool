<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing;

use Symfony\Component\Validator\Constraints as Assert;

/** Whether the product is the organization's is the domain's (404, nothing changes). */
final readonly class AssignProductProposalInput
{
    public function __construct(
        /** The product that the proposal's items are linked to. */
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $productId,
    ) {
    }
}
