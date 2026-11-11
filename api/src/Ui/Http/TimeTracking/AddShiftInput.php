<?php

declare(strict_types=1);

namespace Ui\Http\TimeTracking;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Validator\Constraints as Assert;
use Ui\Http\Shared\Validator\IsoDateTime;

/**
 * A shift an owner or manager enters for a member. Times are ISO 8601 date-times with an offset
 * (IsoDateTime), stored in UTC to the second; the shift must end after it starts, not after now,
 * and not overlap another shift of the person (the domain checks these).
 */
final readonly class AddShiftInput
{
    public function __construct(
        /** The member who worked the shift; a deactivated member too. */
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $userId,
        /** When the shift started, with a time zone offset. */
        #[ApiProperty(schema: ['type' => 'string', 'format' => 'date-time', 'description' => 'When the shift started, with a time zone offset.'])]
        #[Assert\NotBlank]
        #[IsoDateTime]
        public string $startedAt,
        /** When the shift ended, with a time zone offset: a shift is entered whole, never open. */
        #[ApiProperty(schema: ['type' => 'string', 'format' => 'date-time', 'description' => 'When the shift ended, with a time zone offset: a shift is entered whole, never open.'])]
        #[Assert\NotBlank]
        #[IsoDateTime]
        public string $endedAt,
        #[Assert\Length(max: CorrectShiftInput::NOTE_MAX_LENGTH, normalizer: 'trim')]
        public ?string $note = null,
    ) {
    }
}
