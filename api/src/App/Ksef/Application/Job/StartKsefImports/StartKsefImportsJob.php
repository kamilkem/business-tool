<?php

declare(strict_types=1);

namespace App\Ksef\Application\Job\StartKsefImports;

use App\Shared\Application\Job\JobInterface;

/** The scheduled fan-out (ADR 0025 §4): queues one ImportKsefInvoicesJob per organization that may sync. */
final readonly class StartKsefImportsJob implements JobInterface
{
}
