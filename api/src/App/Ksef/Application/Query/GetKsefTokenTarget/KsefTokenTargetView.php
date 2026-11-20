<?php

declare(strict_types=1);

namespace App\Ksef\Application\Query\GetKsefTokenTarget;

/** The ids to save or remove the KSeF token with; null where the organization has none yet. */
final readonly class KsefTokenTargetView
{
    public function __construct(
        public ?string $connectionId,
        public ?string $secretId,
    ) {
    }
}
