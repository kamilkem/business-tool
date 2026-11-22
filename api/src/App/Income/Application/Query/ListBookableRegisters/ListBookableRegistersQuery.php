<?php

declare(strict_types=1);

namespace App\Income\Application\Query\ListBookableRegisters;

use App\Shared\Application\Query\QueryInterface;

/**
 * The active registers of the current organization (TenantFilter) that a booking from another
 * module may go to, by name, without an actor check (ADR 0033 §9): Cash flow has checked its own
 * actor, and asks before it saves an end-of-day close so that Income can book it. Never sent from Ui.
 */
final readonly class ListBookableRegistersQuery implements QueryInterface
{
}
