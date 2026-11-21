<?php

declare(strict_types=1);

namespace App\Income\Application\Query\ListPaymentMethods;

use App\Shared\Application\Query\QueryInterface;

/**
 * The payment methods of the current organization (TenantFilter) for an owner or manager, in its
 * order: active ones, and the deactivated ones after them when asked. Returns null when the actor
 * is not an active member; throws when the actor is an employee (ADR 0022 §7).
 */
final readonly class ListPaymentMethodsQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public bool $includeDeactivated = false,
    ) {
    }
}
