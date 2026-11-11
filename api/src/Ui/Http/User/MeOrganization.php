<?php

declare(strict_types=1);

namespace Ui\Http\User;

final readonly class MeOrganization
{
    public function __construct(
        public string $id,
        public string $name,
        /** IANA time zone in which the organization's dates and times are shown, e.g. "Europe/Warsaw". */
        public string $timezone,
    ) {
    }
}
