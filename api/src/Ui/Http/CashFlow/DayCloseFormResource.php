<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation;
use App\CashFlow\Application\Query\GetDayCloseForm\DayCloseFormView;
use Ui\Http\CashFlow\Provider\DayCloseFormProvider;

/**
 * Everything the person closing the day needs, in the organization selected by the
 * X-Organization-Id header (ADR 0033 §6): what the close covers, the proposed date and the settings,
 * and whether that date is already closed. No balances: employees see only what they enter.
 */
#[ApiResource(
    shortName: 'DayCloseForm',
    operations: [
        new Get(
            uriTemplate: '/day-close-form',
            openapi: new Operation(
                summary: 'What an end-of-day close of the selected organization covers now.',
                description: 'Any active member. The active storages and denominations, the active registers (with the one to preselect) and non-cash payment methods, the date a close made now gets, the close settings, and who closed that date, if anyone. canClose is false while no storage or no denomination is active.',
            ),
            provider: DayCloseFormProvider::class,
        ),
    ],
)]
final readonly class DayCloseFormResource
{
    /**
     * @param list<DayCloseFormStorage>      $storages       active, by name
     * @param list<DayCloseFormDenomination> $denominations  active, in the currency, by value
     * @param list<DayCloseFormOption>       $registers      active, by name
     * @param list<DayCloseFormOption>       $paymentMethods active and not cash, in the organization's order
     */
    public function __construct(
        /** ISO 4217: the organization's currency. */
        public string $currency,
        /** Whether a close can be made: at least one active storage and one active denomination. */
        public bool $canClose,
        /** The date a close made now gets when none is sent (YYYY-MM-DD), and the one to preselect. */
        public string $proposedDate,
        /** Today in the organization's time zone (YYYY-MM-DD): the latest date anyone may close. */
        public string $today,
        #[ApiProperty(schema: ['type' => 'string', 'enum' => ['day_end_time', 'employee_picks']])]
        public string $mode,
        /** HH:MM in the organization's time zone. */
        #[ApiProperty(schema: ['type' => 'string', 'pattern' => '^\d{2}:\d{2}$', 'example' => '05:00'])]
        public string $dayEndsAt,
        public array $storages,
        public array $denominations,
        public array $registers,
        /** The only active register, else the latest close's if it is active, else null. */
        public ?string $preselectedRegisterId,
        public array $paymentMethods,
        /** Who closed the proposed date and when; null while it is open. */
        public ?DayCloseFormClosed $proposedDateClosed,
    ) {
    }

    public static function fromView(DayCloseFormView $view): self
    {
        return new self(
            currency: $view->currency,
            canClose: $view->canClose,
            proposedDate: $view->proposedDate,
            today: $view->today,
            mode: $view->mode,
            dayEndsAt: $view->dayEndsAt,
            storages: array_map(DayCloseFormStorage::fromView(...), $view->storages),
            denominations: array_map(DayCloseFormDenomination::fromView(...), $view->denominations),
            registers: array_map(DayCloseFormOption::fromView(...), $view->registers),
            preselectedRegisterId: $view->preselectedRegisterId,
            paymentMethods: array_map(DayCloseFormOption::fromView(...), $view->paymentMethods),
            proposedDateClosed: null === $view->proposedDateClosed ? null : DayCloseFormClosed::fromView($view->proposedDateClosed),
        );
    }
}
