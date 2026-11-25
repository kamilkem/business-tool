<?php

declare(strict_types=1);

namespace App\Organization\Domain\Event;

use App\Shared\Domain\Event\EventInterface;

/**
 * An organization was created with its owner and is saved. Other modules set up what a new
 * organization starts with from here (the default product categories, #238).
 */
final readonly class OrganizationCreated implements EventInterface
{
    public function __construct(
        public string $organizationId,
        public string $ownerId,
    ) {
    }
}
