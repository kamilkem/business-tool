<?php

declare(strict_types=1);

namespace Ui\Http\Income;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\Income\Application\Query\GetRevenueEntry\RevenuePaymentLineView;
use App\Income\Application\Query\GetRevenueEntry\RevenueEntryView;
use App\Income\Application\Query\GetRevenueEntry\RevenueVatLineView;
use App\Income\Domain\Model\PaymentMethod;
use Symfony\Component\Routing\Requirement\Requirement;
use Ui\Http\Income\Processor\DeleteRevenueEntryProcessor;
use Ui\Http\Income\Processor\RecordRevenueEntryProcessor;
use Ui\Http\Income\Processor\UpdateRevenueEntryProcessor;
use Ui\Http\Income\Provider\RevenueEntryProvider;
use Ui\Http\Income\Provider\UpdateRevenueEntryInputProvider;

/**
 * What one register took on one business date, from its daily report, in the organization selected
 * by the X-Organization-Id header (ADR 0022). Owners and managers only: anyone else gets a 403.
 * Amounts are integer minor units of `currency`, the organization's when the entry was recorded
 * (ADR 0010). The totals are computed; the VAT totals need not match the payments total.
 */
#[ApiResource(
    shortName: 'RevenueEntry',
    operations: [
        new Get(
            uriTemplate: '/revenue-entries/{id}',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: 'One revenue entry of the selected organization.',
                description: 'Owners and managers only (403 otherwise). An entry that is not the selected organization\'s is a 404.',
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The entry is not one of the selected organization\'s.'),
                ],
            ),
            provider: RevenueEntryProvider::class,
        ),
        new Post(
            uriTemplate: '/revenue-entries',
            openapi: new Operation(
                summary: "Records a register's daily report for a business date.",
                description: 'Owners and managers only (403 otherwise). One entry per register and business date: a second one is a 409 (correct the existing entry instead). The register must be the selected organization\'s (404) and active (422); the business date may not be after today in the organization\'s time zone (422). At least one payment line, each method once, amounts non-negative integer minor units (422). VAT lines are optional, use the rates of GET /vat-rates, each once, with 0 <= vat <= gross and vat 0 at a 0% or exempt rate (422); an organization whose country has no rates takes none (422). The currency is the organization\'s.',
                responses: [
                    '201' => new Response('The recorded entry.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The register is not one of the selected organization\'s.'),
                    '409' => new Response('The register already has an entry for the business date.'),
                    '422' => new Response('A field is missing or invalid, the register is deactivated, the date is in the future, or a line breaks a rule.'),
                ],
            ),
            input: RecordRevenueEntryInput::class,
            read: false,
            processor: RecordRevenueEntryProcessor::class,
        ),
        new Patch(
            uriTemplate: '/revenue-entries/{id}',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: "Corrects an entry's payment lines, VAT lines and note.",
                description: 'Owners and managers only (403 otherwise); an entry that is not the selected organization\'s is a 404. A list that is sent replaces the current one whole: `vatLines: []` (or null) removes the breakdown. The register and the business date do not change: delete the entry and record it again. An entry of a deactivated register may be corrected. The rules of POST apply to the lines (422). A change records who made it and when (updatedBy, updatedAt); sending what the entry already has changes nothing.',
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The entry is not one of the selected organization\'s.'),
                    '422' => new Response('A field is invalid or a line breaks a rule.'),
                ],
            ),
            input: UpdateRevenueEntryInput::class,
            provider: UpdateRevenueEntryInputProvider::class,
            processor: UpdateRevenueEntryProcessor::class,
        ),
        new Delete(
            uriTemplate: '/revenue-entries/{id}',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: 'Deletes a revenue entry.',
                description: 'Owners and managers only (403 otherwise); an entry that is not the selected organization\'s is a 404. The entry and its lines are removed and no trace is kept (ADR 0022 §8).',
                responses: [
                    '204' => new Response('The entry was deleted.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The entry is not one of the selected organization\'s.'),
                ],
            ),
            read: false,
            processor: DeleteRevenueEntryProcessor::class,
        ),
    ],
)]
final readonly class RevenueEntryResource
{
    /**
     * @param list<RevenueEntryPayment> $payments
     * @param list<RevenueEntryVatLine> $vatLines
     */
    public function __construct(
        public string $id,
        public string $registerId,
        public string $registerName,
        /** The business date the daily report covers, `YYYY-MM-DD`. */
        public string $businessDate,
        /** ISO 4217 code of the amounts: the organization's currency when the entry was recorded. */
        public string $currency,
        /** One line per payment method used, in the order cash, card, transfer, voucher. */
        public array $payments,
        /** The VAT breakdown as printed, highest rate first, exempt last; empty when not entered. */
        public array $vatLines,
        /** The sum of the payment lines, in minor units. */
        public int $paymentsTotal,
        /** The sum of the VAT lines' gross amounts, in minor units; 0 without a breakdown. */
        public int $vatGrossTotal,
        /** The sum of the VAT lines' VAT, in minor units; 0 without a breakdown. */
        public int $vatTotal,
        public ?string $note,
        /** The owner or manager who recorded the entry. */
        public string $createdBy,
        public \DateTimeImmutable $createdAt,
        /** The owner or manager who changed the entry last; null until the first change. */
        public ?string $updatedBy,
        public ?\DateTimeImmutable $updatedAt,
    ) {
    }

    public static function fromView(RevenueEntryView $view): self
    {
        return new self(
            id: $view->id,
            registerId: $view->registerId,
            registerName: $view->registerName,
            businessDate: $view->businessDate,
            currency: $view->currency,
            payments: array_map(
                static fn (RevenuePaymentLineView $line): RevenueEntryPayment => new RevenueEntryPayment(PaymentMethod::from($line->method), $line->amount),
                $view->payments,
            ),
            vatLines: array_map(
                static fn (RevenueVatLineView $line): RevenueEntryVatLine => new RevenueEntryVatLine($line->rate, $line->gross, $line->vat, $line->net),
                $view->vatLines,
            ),
            paymentsTotal: $view->paymentsTotal,
            vatGrossTotal: $view->vatGrossTotal,
            vatTotal: $view->vatTotal,
            note: $view->note,
            createdBy: $view->createdBy,
            createdAt: $view->createdAt,
            updatedBy: $view->updatedBy,
            updatedAt: $view->updatedAt,
        );
    }
}
