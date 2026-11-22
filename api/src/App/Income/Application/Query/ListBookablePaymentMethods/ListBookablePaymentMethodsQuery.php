<?php

declare(strict_types=1);

namespace App\Income\Application\Query\ListBookablePaymentMethods;

use App\Shared\Application\Query\QueryInterface;

/**
 * The active payment methods of the current organization (TenantFilter), with their cash flag
 * (ADR 0033 §9), in its order, without an actor check: Cash flow has checked its own actor, and asks
 * before it saves an end-of-day close so that Income can book it. Never sent from Ui.
 */
final readonly class ListBookablePaymentMethodsQuery implements QueryInterface
{
}
