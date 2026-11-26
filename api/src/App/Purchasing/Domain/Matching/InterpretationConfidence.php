<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Matching;

/** How sure the interpreter says it is. Only `high` can lead to `assigned`; `low` is treated as no answer. */
enum InterpretationConfidence: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
}
