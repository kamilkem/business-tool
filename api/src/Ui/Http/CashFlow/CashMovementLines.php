<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow;

/** Turns the line inputs of a movement into the command's primitives, and compares them for Assert\Unique. */
final class CashMovementLines
{
    /**
     * @param list<CashAdjustmentLineInput|CashTransferLineInput|CashCountLineInput> $lines
     *
     * @return list<array{denominationId: string, pieces: int}>
     */
    public static function toCommand(array $lines): array
    {
        return array_map(static fn (CashAdjustmentLineInput|CashTransferLineInput|CashCountLineInput $line): array => [
            'denominationId' => $line->denominationId,
            'pieces' => $line->pieces,
        ], $lines);
    }

    /** For Assert\Unique: lines are the same when their denominations are. */
    public static function denominationOf(mixed $line): mixed
    {
        return ($line instanceof CashAdjustmentLineInput || $line instanceof CashTransferLineInput || $line instanceof CashCountLineInput) && isset($line->denominationId)
            ? strtolower($line->denominationId)
            : $line;
    }
}
