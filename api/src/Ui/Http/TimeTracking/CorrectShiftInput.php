<?php

declare(strict_types=1);

namespace Ui\Http\TimeTracking;

use ApiPlatform\Metadata\ApiProperty;
use App\TimeTracking\Application\Query\GetShift\ShiftView;
use Symfony\Component\Validator\Constraints as Assert;
use Ui\Http\Shared\Validator\IsoDateTime;

/**
 * Merge-patch input. Mutable on purpose: CorrectShiftInputProvider pre-fills it from the current
 * state and API Platform applies the request body onto it, so fields the client did not send keep
 * their value. Times are ISO 8601 date-times with an offset (IsoDateTime).
 */
final class CorrectShiftInput
{
    public const int NOTE_MAX_LENGTH = 1000;

    /** When the shift started, with a time zone offset; not after now. */
    #[ApiProperty(schema: ['type' => 'string', 'format' => 'date-time', 'description' => 'When the shift started, with a time zone offset; not after now.'])]
    #[Assert\NotBlank]
    #[IsoDateTime]
    public ?string $startedAt = null;

    /** When the shift ended, with a time zone offset; not after now. An open shift may stay open (null); an ended one keeps an end. */
    #[ApiProperty(schema: ['type' => ['string', 'null'], 'format' => 'date-time', 'description' => 'When the shift ended, with a time zone offset; not after now. An open shift may stay open (null); an ended one keeps an end.'])]
    #[IsoDateTime]
    public ?string $endedAt = null;

    /** An empty note removes it. */
    #[Assert\Length(max: self::NOTE_MAX_LENGTH, normalizer: 'trim')]
    public ?string $note = null;

    public static function fromView(ShiftView $view): self
    {
        $input = new self();
        $input->startedAt = $view->startedAt->format(\DATE_ATOM);
        $input->endedAt = $view->endedAt?->format(\DATE_ATOM);
        $input->note = $view->note;

        return $input;
    }
}
