<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\CashFlow\Application\Query\GetDayClose\DayCloseView;
use Ui\Http\CashFlow\Processor\RecordDayCloseProcessor;

/**
 * The end-of-day close just made in the organization selected by the X-Organization-Id header, as
 * the person closing sees it (ADR 0033 §6): what they entered, the totals per storage and the cash
 * income, with no balance or expected opening.
 */
#[ApiResource(
    shortName: 'DayCloseReceipt',
    operations: [
        new Post(
            uriTemplate: '/day-closes',
            openapi: new Operation(
                summary: 'Closes a business date: counts the cash, records the deposits and the non-cash amounts.',
                description: <<<'TXT'
                    Any active member, employees included. It goes straight in; owners and managers correct it later.
                    - The date follows the organization's close settings (GET /day-close-settings) when left out. An employee in `day_end_time` mode may send only that date, in `employee_picks` mode today or yesterday; owners and managers any date up to today (422 otherwise, `cash_flow.invalid_day_close_date.*`).
                    - One close per date (409 `cash_flow.day_already_closed`, with who closed it and when), in date order: a date before the latest closed one is a 409 (`cash_flow.day_close_out_of_order`).
                    - Every active storage once: a counted storage gets the pieces of each active denomination that stay in it (one left out is zero), a deposit storage the pieces put into it (possibly none). Unknown storages or denominations are a 404, deactivated ones or another currency a 422.
                    - Every active non-cash payment method once, with the day's amount (zero allowed), and one active register (422 otherwise, `cash_flow.invalid_day_close.*`).
                    The cash income is Σ counted storages (count − the balance the ledger expected) + Σ deposits. A negative one is saved and flagged as a shortage.
                    TXT,
                responses: [
                    '201' => new Response('The recorded close.'),
                    '404' => new Response('A storage or a denomination is not one of the selected organization\'s.'),
                    '409' => new Response('The date is already closed, or a later date is.'),
                    '422' => new Response('A field is missing or invalid, the date is not allowed, or the close does not cover what it should.'),
                ],
            ),
            input: RecordDayCloseInput::class,
            read: false,
            processor: RecordDayCloseProcessor::class,
        ),
    ],
)]
final readonly class DayCloseReceiptResource
{
    /**
     * @param list<DayCloseReceiptStorage> $storages by the storages' names
     * @param list<DayClosePaymentLine>    $payments in the organization's order of payment methods
     */
    public function __construct(
        public string $id,
        /** The business date closed (YYYY-MM-DD). */
        public string $date,
        public string $registerId,
        /** The register's name; null when it is no longer offered. */
        public ?string $registerName,
        /** ISO 4217. */
        public string $currency,
        /** Minor units: the cash the close adds to the day's income. Negative for a shortage. */
        public int $cashIncome,
        /** Whether the cash income is negative. */
        public bool $shortage,
        public array $storages,
        public array $payments,
        public \DateTimeImmutable $closedAt,
    ) {
    }

    public static function fromView(DayCloseView $view): self
    {
        return new self(
            id: $view->id,
            date: $view->date,
            registerId: $view->registerId,
            registerName: $view->registerName,
            currency: $view->currency,
            cashIncome: $view->cashIncome,
            shortage: $view->shortage,
            storages: array_map(DayCloseReceiptStorage::fromView(...), $view->storages),
            payments: array_map(DayClosePaymentLine::fromView(...), $view->payments),
            closedAt: $view->closedAt,
        );
    }
}
