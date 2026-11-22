<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow;

use ApiPlatform\Metadata\ApiProperty;
use App\CashFlow\Application\Query\GetCashStorage\CashStorageView;
use App\CashFlow\Domain\Model\CashStorage;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Merge-patch input. Mutable on purpose: UpdateCashStorageInputProvider pre-fills it from the
 * current storage and API Platform applies the request body onto it, so a field the client did not
 * send keeps its value.
 */
final class UpdateCashStorageInput
{
    /** Trimmed; unique in the organization, compared as written. */
    #[Assert\NotNull]
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: CashStorage::NAME_MAX_LENGTH, normalizer: 'trim')]
    public ?string $name = null;

    /** `counted` or `deposit`; a change applies from the next close. */
    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['counted', 'deposit']])]
    #[Assert\NotBlank]
    #[Assert\Choice(callback: [AddCashStorageInput::class, 'modes'])]
    public ?string $mode = null;

    public static function fromView(CashStorageView $view): self
    {
        $input = new self();
        $input->name = $view->name;
        $input->mode = $view->mode;

        return $input;
    }
}
