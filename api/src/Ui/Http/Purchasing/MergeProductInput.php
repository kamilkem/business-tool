<?php

declare(strict_types=1);

namespace Ui\Http\Purchasing;

use Symfony\Component\Validator\Constraints as Assert;

/** Whether the product exists in the organization and is another one is the domain's (404, 422). */
final readonly class MergeProductInput
{
    public function __construct(
        /** The product that takes over the items of the merged one. */
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $into,
    ) {
    }
}
