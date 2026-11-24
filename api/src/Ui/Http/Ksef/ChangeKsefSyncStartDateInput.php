<?php

declare(strict_types=1);

namespace Ui\Http\Ksef;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ChangeKsefSyncStartDateInput
{
    public function __construct(
        /** The date of issue from which invoices are imported (`YYYY-MM-DD`), in the organization's time zone. */
        #[Assert\NotBlank]
        #[Assert\Date]
        public string $syncStartDate,
    ) {
    }
}
