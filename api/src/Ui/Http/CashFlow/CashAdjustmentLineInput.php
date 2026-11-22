<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow;

use App\CashFlow\Domain\Model\CashMovement;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Signed pieces of one denomination for an adjustment: positive adds, negative takes out, never
 * zero. Whether the denomination is the organization's, in its currency and active is the domain's.
 * Properties, not constructor parameters: the serializer then skips a value of the wrong type (a
 * fraction, a string) and reports it as a 422.
 */
final class CashAdjustmentLineInput
{
    /** A denomination of the organization (GET /denominations). */
    #[Assert\NotBlank]
    #[Assert\Uuid]
    public string $denominationId;

    #[Assert\NotNull]
    #[Assert\NotEqualTo(0)]
    #[Assert\Range(min: -CashMovement::MAX_PIECES, max: CashMovement::MAX_PIECES)]
    public int $pieces;

    public static function of(string $denominationId, int $pieces): self
    {
        $line = new self();
        $line->denominationId = $denominationId;
        $line->pieces = $pieces;

        return $line;
    }
}
