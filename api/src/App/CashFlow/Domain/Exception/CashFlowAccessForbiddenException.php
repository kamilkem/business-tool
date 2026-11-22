<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Exception;

/** Only owners and managers change Cash flow's denominations and storages (ADR 0033 §2, §3). */
final class CashFlowAccessForbiddenException extends CashFlowException
{
    public static function toManage(string $userId): self
    {
        return new self(\sprintf('User "%s" may not manage cash flow: only owners and managers do.', $userId), ['userId' => $userId]);
    }
}
