<?php

declare(strict_types=1);

namespace App\Income\Domain\Model;

/** What an owner did to an Income month (ADR 0034 §1). */
enum RevenueMonthActionKind: string
{
    case Closed = 'closed';
    case Reopened = 'reopened';
}
