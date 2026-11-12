<?php

declare(strict_types=1);

namespace Ui\Http\Income;

use App\Income\Application\Query\GetRegister\RegisterView;
use App\Income\Domain\Model\Register;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Merge-patch input. Mutable on purpose: UpdateRegisterInputProvider pre-fills it from the current
 * register and API Platform applies the request body onto it, so a field the client did not send
 * keeps its value.
 */
final class UpdateRegisterInput
{
    /** Trimmed; unique in the organization, compared as written. */
    #[Assert\NotNull]
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: Register::NAME_MAX_LENGTH, normalizer: 'trim')]
    public ?string $name = null;

    public static function fromView(RegisterView $view): self
    {
        $input = new self();
        $input->name = $view->name;

        return $input;
    }
}
