<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Exception;

/** Only owners and managers read and change what the organization buys and how it is grouped (ADR 0041 §1, ADR 0021). */
final class PurchasingManagementForbiddenException extends PurchasingException
{
    public static function forUser(string $userId): self
    {
        return new self(\sprintf('User "%s" may not manage purchasing: only owners and managers do.', $userId), ['userId' => $userId]);
    }
}
