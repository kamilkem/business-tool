<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow;

use App\CashFlow\Domain\Model\CashMovement;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The pieces of one denomination counted in the storage. Whether the denomination is the
 * organization's, in its currency and active is the domain's. Properties, not constructor
 * parameters: the serializer then skips a value of the wrong type (a fraction, a string) and reports
 * it as a 422.
 */
final class CashCountLineInput
{
    /** A denomination of the organization (GET /denominations). */
    #[Assert\NotBlank]
    #[Assert\Uuid]
    public string $denominationId;

    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    #[Assert\LessThanOrEqual(CashMovement::MAX_PIECES)]
    public int $pieces;

    public static function of(string $denominationId, int $pieces): self
    {
        $line = new self();
        $line->denominationId = $denominationId;
        $line->pieces = $pieces;

        return $line;
    }
}
