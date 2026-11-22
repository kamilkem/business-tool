<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Put;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\CashFlow\Application\Query\GetDayCloseSettings\DayCloseSettingsView;
use Ui\Http\CashFlow\Processor\UpdateDayCloseSettingsProcessor;
use Ui\Http\CashFlow\Provider\DayCloseSettingsProvider;

/**
 * How the end-of-day closes of the organization selected by the X-Organization-Id header get their
 * business date (ADR 0033 §5). Every active member reads them, because the close form needs them;
 * owners and managers change them (403 otherwise). An organization that never changed them has the
 * defaults: `day_end_time` at 05:00.
 */
#[ApiResource(
    shortName: 'DayCloseSettings',
    operations: [
        new Get(
            uriTemplate: '/day-close-settings',
            openapi: new Operation(
                summary: 'How the end-of-day closes of the selected organization get their date.',
                description: 'Any active member. The defaults (`day_end_time`, 05:00, `updatedAt` null) while nobody changed them.',
            ),
            provider: DayCloseSettingsProvider::class,
        ),
        new Put(
            uriTemplate: '/day-close-settings',
            openapi: new Operation(
                summary: 'Sets how the end-of-day closes of the selected organization get their date.',
                description: 'Owners and managers only (403 otherwise). Both fields are required (422): the mode is `day_end_time` or `employee_picks`, and the day-end time is HH:MM from 00:00 to 12:00 in the organization\'s time zone. It applies to closes from now on; earlier closes keep their dates. Setting the current values changes nothing.',
                responses: [
                    '200' => new Response('The settings as they are now.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '422' => new Response('The mode or the day-end time is missing or invalid.'),
                ],
            ),
            input: UpdateDayCloseSettingsInput::class,
            // A URI without variables is not read by default; the provider refuses employees first.
            read: true,
            provider: DayCloseSettingsProvider::class,
            processor: UpdateDayCloseSettingsProcessor::class,
        ),
    ],
)]
final readonly class DayCloseSettingsResource
{
    public function __construct(
        /** `day_end_time`: the date follows from the day-end time, and the employee does not choose; `employee_picks`: the employee picks today or yesterday. Owners and managers pick any date up to today in either mode. */
        #[ApiProperty(schema: ['type' => 'string', 'enum' => ['day_end_time', 'employee_picks']])]
        public string $mode,
        /** HH:MM in the organization's time zone, from 00:00 to 12:00: a close before it belongs to the previous date. In `employee_picks` mode, the date the time clock preselects. */
        #[ApiProperty(schema: ['type' => 'string', 'pattern' => '^\d{2}:\d{2}$', 'example' => '05:00'])]
        public string $dayEndsAt,
        /** When an owner or manager changed them last; null while the organization has the defaults. */
        public ?\DateTimeImmutable $updatedAt,
    ) {
    }

    public static function fromView(DayCloseSettingsView $view): self
    {
        return new self($view->mode, $view->dayEndsAt, $view->updatedAt);
    }
}
