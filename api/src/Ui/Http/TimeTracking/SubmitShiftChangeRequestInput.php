<?php

declare(strict_types=1);

namespace Ui\Http\TimeTracking;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Ui\Http\Shared\Validator\IsoDateTime;

/**
 * A shift change request the signed-in member submits for themselves: without shiftId, a missing
 * shift (endedAt required); with shiftId, new times for one of their own shifts (a null endedAt
 * keeps an open shift open). Times are ISO 8601 date-times with an offset (IsoDateTime), stored in
 * UTC to the second; the domain checks that the end is after the start and that nothing is in the
 * future.
 */
final readonly class SubmitShiftChangeRequestInput
{
    public function __construct(
        /** When the shift started, or should start, with a time zone offset. */
        #[ApiProperty(schema: ['type' => 'string', 'format' => 'date-time', 'description' => 'When the shift started, or should start, with a time zone offset.'])]
        #[Assert\NotBlank]
        #[IsoDateTime]
        public string $startedAt,
        /** Why the change is needed, for whoever decides it. */
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: CorrectShiftInput::NOTE_MAX_LENGTH, normalizer: 'trim')]
        public string $note,
        /** The shift to give new times to: one of the signed-in member's own. Leave it out for a missing shift. */
        #[Assert\Uuid]
        public ?string $shiftId = null,
        /** When the shift ended, with a time zone offset. Required for a missing shift; null keeps an open shift open. */
        #[ApiProperty(schema: ['type' => ['string', 'null'], 'format' => 'date-time', 'description' => 'When the shift ended, with a time zone offset. Required for a missing shift; null keeps an open shift open.'])]
        #[IsoDateTime]
        public ?string $endedAt = null,
    ) {
    }

    #[Assert\Callback]
    public function validateMissingShiftEnd(ExecutionContextInterface $context): void
    {
        if (null === $this->shiftId && null === $this->endedAt) {
            $context->buildViolation('A missing shift needs its end.')->atPath('endedAt')->addViolation();
        }
    }
}
