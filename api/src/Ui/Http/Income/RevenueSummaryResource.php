<?php

declare(strict_types=1);

namespace Ui\Http\Income;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\Income\Application\Query\GetRevenueSummary\GetRevenueSummaryQuery;
use App\Income\Application\Query\GetRevenueSummary\RevenueCurrencySummaryView;
use App\Income\Application\Query\GetRevenueSummary\RevenueSummaryView;
use Ui\Http\Income\Provider\RevenueSummaryProvider;

/**
 * The revenue of the organization selected by the X-Organization-Id header for a period of business
 * dates (ADR 0022): totals per currency, per payment method, per VAT rate and per register, and the
 * days with entries. Owners and managers only.
 */
#[ApiResource(
    shortName: 'RevenueSummary',
    operations: [
        new Get(
            uriTemplate: '/revenue-summary',
            openapi: new Operation(
                summary: 'The revenue totals of the selected organization from one business date to another.',
                description: 'Owners and managers only (403 otherwise). Totals are given per currency, because an entry keeps the currency it was recorded in: `currencies[0]` is always the organization\'s current currency, also when the period has no entries (all totals 0), and other currencies follow by code. Within a currency: per payment method used, per VAT rate used (gross, VAT, net) and per register with entries; amounts are integer minor units. The VAT totals need not match the payments total. Invalid dates, to before from and periods longer than '.GetRevenueSummaryQuery::MAX_DAYS.' days are a 422.',
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '422' => new Response('A date is missing or invalid, to is before from, or the period is too long.'),
                ],
            ),
            // RevenueSummaryInput validates the parameters, cross-field rules included; the schemas below only document them.
            queryParameterValidationEnabled: false,
            provider: RevenueSummaryProvider::class,
            parameters: [
                'from' => new QueryParameter(
                    schema: ['type' => 'string', 'format' => 'date'],
                    description: 'First business date, inclusive (YYYY-MM-DD).',
                    required: true,
                ),
                'to' => new QueryParameter(
                    schema: ['type' => 'string', 'format' => 'date'],
                    description: 'Last business date, inclusive (YYYY-MM-DD); at most '.GetRevenueSummaryQuery::MAX_DAYS.' days from the first.',
                    required: true,
                ),
            ],
        ),
    ],
)]
final readonly class RevenueSummaryResource
{
    /** @param list<RevenueSummaryCurrency> $currencies */
    public function __construct(
        /** First business date of the period, `YYYY-MM-DD`. */
        public string $from,
        /** Last business date of the period, inclusive, `YYYY-MM-DD`. */
        public string $to,
        /** The number of days in the period. */
        public int $dayCount,
        /** The number of days in the period with at least one entry, of any register; dayCount − daysWithEntries days have none. */
        public int $daysWithEntries,
        /** The number of entries in the period, of all registers and currencies. */
        public int $entryCount,
        /** Totals per currency: the organization's current currency first, always present. */
        public array $currencies,
    ) {
    }

    public static function fromView(RevenueSummaryView $view): self
    {
        return new self(
            from: $view->from,
            to: $view->to,
            dayCount: $view->dayCount,
            daysWithEntries: $view->daysWithEntries,
            entryCount: $view->entryCount,
            currencies: array_map(
                static fn (RevenueCurrencySummaryView $currency): RevenueSummaryCurrency => RevenueSummaryCurrency::fromView($currency),
                $view->currencies,
            ),
        );
    }
}
