<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Exception;

/**
 * A counted storage is counted only at a close: an audit count would become the day's anchor and
 * hide the takings so far from the close (ADR 0033 §4).
 */
final class AuditCountNeedsDepositStorageException extends CashFlowException
{
    public static function of(string $storageId, string $name): self
    {
        return new self(\sprintf('The cash storage "%s" is counted at each close; only deposit storages take an audit count.', $name), ['storageId' => $storageId, 'name' => $name]);
    }
}
