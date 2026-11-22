<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Query\GetDayCloseForm;

use App\Shared\Application\Query\QueryInterface;

/**
 * What the person closing the day needs, for any active member (ADR 0033 §6): what the close covers,
 * the proposed date and the settings, and whether that date is closed. No balances. Returns null
 * when the actor is not an active member.
 */
final readonly class GetDayCloseFormQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
    ) {
    }
}
