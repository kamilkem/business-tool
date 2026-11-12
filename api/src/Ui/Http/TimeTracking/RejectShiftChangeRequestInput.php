<?php

declare(strict_types=1);

namespace Ui\Http\TimeTracking;

use Symfony\Component\Validator\Constraints as Assert;

/** Rejecting a shift change request, optionally saying why; the requester reads it in the e-mail. */
final readonly class RejectShiftChangeRequestInput
{
    public function __construct(
        #[Assert\Length(max: CorrectShiftInput::NOTE_MAX_LENGTH, normalizer: 'trim')]
        public ?string $reason = null,
    ) {
    }
}
