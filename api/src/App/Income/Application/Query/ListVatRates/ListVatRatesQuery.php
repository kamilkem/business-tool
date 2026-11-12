<?php

declare(strict_types=1);

namespace App\Income\Application\Query\ListVatRates;

use App\Shared\Application\Query\QueryInterface;

/**
 * The VAT rates an entry's breakdown may use in the organization, by its country (ADR 0022 §6): an
 * empty list for a country without rates. Returns null when the actor is not an active member;
 * throws when the actor is an employee.
 */
final readonly class ListVatRatesQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
    ) {
    }
}
