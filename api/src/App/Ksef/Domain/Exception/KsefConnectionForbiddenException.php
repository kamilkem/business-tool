<?php

declare(strict_types=1);

namespace App\Ksef\Domain\Exception;

/**
 * Owners and managers see and test the KSeF connection (ADR 0021); only owners save or remove its
 * token (ADR 0023 §2).
 */
final class KsefConnectionForbiddenException extends KsefException
{
    public static function toUse(string $userId): self
    {
        return new self(\sprintf('User "%s" may not use the KSeF connection: only owners and managers do.', $userId), ['userId' => $userId], variant: __FUNCTION__);
    }

    public static function toManageToken(string $userId): self
    {
        return new self(\sprintf('User "%s" may not save or remove the KSeF token: only owners do.', $userId), ['userId' => $userId], variant: __FUNCTION__);
    }
}
