<?php

declare(strict_types=1);

namespace Ui\Http\Income;

use App\Income\Domain\Model\Register;
use Symfony\Component\Validator\Constraints as Assert;

/** Uniqueness of the name in the organization is the domain's (409). */
final readonly class AddRegisterInput
{
    public function __construct(
        /** Trimmed; unique in the organization, compared as written ("Bar" and "bar" are two names). */
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: Register::NAME_MAX_LENGTH, normalizer: 'trim')]
        public string $name,
    ) {
    }
}
