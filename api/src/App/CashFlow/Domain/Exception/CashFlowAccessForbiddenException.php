<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Exception;

/**
 * Only owners and managers change Cash flow's denominations and storages and move cash (ADR 0033 §2
 * to §4); employees see no balance and no history (§6).
 */
final class CashFlowAccessForbiddenException extends CashFlowException
{
    public static function toManage(string $userId): self
    {
        return new self(\sprintf('User "%s" may not manage cash flow: only owners and managers do.', $userId), ['userId' => $userId], variant: __FUNCTION__);
    }

    public static function toReadLedger(string $userId): self
    {
        return new self(\sprintf('User "%s" may not see cash balances or movements: only owners and managers do.', $userId), ['userId' => $userId], variant: __FUNCTION__);
    }

    public static function toReadDayCloses(string $userId): self
    {
        return new self(\sprintf('User "%s" may not see end-of-day closes: only owners and managers do.', $userId), ['userId' => $userId], variant: __FUNCTION__);
    }
}
