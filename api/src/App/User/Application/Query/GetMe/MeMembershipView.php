<?php

declare(strict_types=1);

namespace App\User\Application\Query\GetMe;

final readonly class MeMembershipView
{
    public function __construct(
        public string $id,
        public string $role,
        public string $organizationId,
        public string $organizationName,
        /** IANA time zone of the organization's business dates (ADR 0002). */
        public string $organizationTimezone,
        /** ISO 4217 code of the organization's money amounts (ADR 0010). */
        public string $organizationCurrency,
    ) {
    }
}
