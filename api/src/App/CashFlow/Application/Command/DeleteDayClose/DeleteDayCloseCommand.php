<?php

declare(strict_types=1);

namespace App\CashFlow\Application\Command\DeleteDayClose;

use App\Shared\Application\Command\CommandInterface;

/** An owner or manager deletes an end-of-day close (ADR 0033 §7), for example to redo it with another date. */
final readonly class DeleteDayCloseCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $dayCloseId,
    ) {
    }
}
