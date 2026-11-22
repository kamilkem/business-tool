<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Exception;

/** A cash movement that breaks a rule of its shape (ADR 0033 §4); the inputs check the same rules first. */
final class InvalidMovementException extends CashFlowException
{
    public static function withoutLines(): self
    {
        return new self('A cash movement needs at least one denomination.', variant: __FUNCTION__);
    }

    public static function duplicateDenomination(string $denominationId): self
    {
        return new self(\sprintf('The denomination "%s" may appear only once in a cash movement.', $denominationId), ['denominationId' => $denominationId], variant: __FUNCTION__);
    }

    /**
     * @param int $min the fewest pieces a line of this kind takes (1 for a transfer, 0 for a count,
     *                 -$max for an adjustment, which takes no zero)
     */
    public static function pieces(string $denominationId, int $pieces, int $min, int $max): self
    {
        return new self(
            \sprintf('%d pieces of the denomination "%s" is not allowed here (from %d to %d).', $pieces, $denominationId, $min, $max),
            ['denominationId' => $denominationId, 'pieces' => $pieces, 'min' => $min, 'max' => $max],
            variant: __FUNCTION__,
        );
    }

    public static function sameStorage(string $storageId): self
    {
        return new self(\sprintf('A transfer moves cash between two storages; "%s" is both.', $storageId), ['storageId' => $storageId], variant: __FUNCTION__);
    }

    public static function note(int $maxLength): self
    {
        return new self(\sprintf('A note has at most %d characters.', $maxLength), ['maxLength' => $maxLength], variant: __FUNCTION__);
    }
}
