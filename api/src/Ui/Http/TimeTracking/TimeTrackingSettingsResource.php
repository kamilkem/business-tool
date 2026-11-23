<?php

declare(strict_types=1);

namespace Ui\Http\TimeTracking;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Put;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\TimeTracking\Application\Query\GetTimeTrackingSettings\TimeTrackingSettingsView;
use App\TimeTracking\Domain\Model\MaxShiftLength;
use Ui\Http\TimeTracking\Processor\UpdateTimeTrackingSettingsProcessor;
use Ui\Http\TimeTracking\Provider\TimeTrackingSettingsProvider;

/**
 * The time tracking settings of the organization selected by the X-Organization-Id header (#33).
 * Every active member reads them, because the time clock warns with them; owners and managers change
 * them (403 otherwise). An organization that never changed them has the defaults.
 */
#[ApiResource(
    shortName: 'TimeTrackingSettings',
    operations: [
        new Get(
            uriTemplate: '/time-tracking-settings',
            openapi: new Operation(
                summary: 'The time tracking settings of the selected organization.',
                description: 'Any active member. The defaults (a maximum shift length of '.MaxShiftLength::DEFAULT_HOURS.' hours, `updatedAt` null) while nobody changed them.',
            ),
            provider: TimeTrackingSettingsProvider::class,
        ),
        new Put(
            uriTemplate: '/time-tracking-settings',
            openapi: new Operation(
                summary: 'Sets the time tracking settings of the selected organization.',
                description: 'Owners and managers only (403 otherwise). The maximum shift length is required, in whole hours from '.MaxShiftLength::MIN_HOURS.' to '.MaxShiftLength::MAX_HOURS.' (422). An open shift that runs longer is reported once by e-mail, to the person and to the owners and managers; it is never ended for them. Setting the current values changes nothing.',
                responses: [
                    '200' => new Response('The settings as they are now.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '422' => new Response('The maximum shift length is missing or out of range.'),
                ],
            ),
            input: UpdateTimeTrackingSettingsInput::class,
            // A URI without variables is not read by default; the provider refuses employees first.
            read: true,
            provider: TimeTrackingSettingsProvider::class,
            processor: UpdateTimeTrackingSettingsProcessor::class,
        ),
    ],
)]
final readonly class TimeTrackingSettingsResource
{
    public function __construct(
        /** Whole hours a shift may run; a longer one is flagged as a possibly forgotten clock-out. */
        #[ApiProperty(schema: ['type' => 'integer', 'minimum' => MaxShiftLength::MIN_HOURS, 'maximum' => MaxShiftLength::MAX_HOURS, 'example' => MaxShiftLength::DEFAULT_HOURS])]
        public int $maxShiftHours,
        /** When an owner or manager changed them last; null while the organization has the defaults. */
        public ?\DateTimeImmutable $updatedAt,
    ) {
    }

    public static function fromView(TimeTrackingSettingsView $view): self
    {
        return new self($view->maxShiftHours, $view->updatedAt);
    }
}
