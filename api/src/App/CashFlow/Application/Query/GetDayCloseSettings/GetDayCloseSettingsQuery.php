<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Query\GetDayCloseSettings;

use App\Shared\Application\Query\QueryInterface;

/**
 * How the current organization's end-of-day closes get their date (ADR 0033 §5), for any active
 * member: the close form needs it. The defaults while the organization has no settings. Returns
 * null when the actor is not an active member. With `toManage`, an employee is refused (403), for a
 * write that starts from the current settings (the PUT, so it is refused before its body is
 * validated).
 */
final readonly class GetDayCloseSettingsQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public bool $toManage = false,
    ) {
    }
}
