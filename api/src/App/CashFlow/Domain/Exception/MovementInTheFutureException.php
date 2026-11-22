<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Exception;

/** A movement may be set at an earlier moment than now, never a later one (ADR 0033 §4). */
final class MovementInTheFutureException extends CashFlowException
{
    public static function of(\DateTimeImmutable $occurredAt, \DateTimeImmutable $now): self
    {
        return new self(
            \sprintf('A cash movement cannot happen at %s, after now (%s).', $occurredAt->format(\DATE_ATOM), $now->format(\DATE_ATOM)),
            ['occurredAt' => $occurredAt->format(\DATE_ATOM), 'now' => $now->format(\DATE_ATOM)],
        );
    }
}
