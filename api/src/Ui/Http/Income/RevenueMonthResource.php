<?php

declare(strict_types=1);

namespace Ui\Http\Income;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\Response;
use App\Income\Application\Query\GetRevenueMonth\RevenueMonthView;
use Ui\Http\Income\Processor\CloseRevenueMonthProcessor;
use Ui\Http\Income\Processor\ReopenRevenueMonthProcessor;
use Ui\Http\Income\Provider\RevenueMonthProvider;

/**
 * A calendar month of Income in the organization selected by the X-Organization-Id header (ADR 0034):
 * an owner closes it once it has ended, which locks its revenue entries, and reopens it to correct
 * them. Every close and reopen is kept. Owners and managers read it; only owners close and reopen.
 */
#[ApiResource(
    shortName: 'RevenueMonth',
    operations: [
        new Get(
            uriTemplate: '/revenue-months/{month}',
            uriVariables: ['month'],
            requirements: ['month' => self::MONTH],
            openapi: new Operation(
                summary: 'One month of income: open or closed, with every close and reopen.',
                description: 'Owners and managers only (403 otherwise). A month nobody ever closed is open, with no actions.',
                parameters: [new Parameter('month', 'path', 'The month, YYYY-MM.', true)],
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The month is not YYYY-MM.'),
                    '422' => new Response('The month does not exist (e.g. 2026-13).'),
                ],
            ),
            provider: RevenueMonthProvider::class,
        ),
        new Post(
            uriTemplate: '/revenue-months/{month}/close',
            uriVariables: ['month'],
            requirements: ['month' => self::MONTH],
            status: 200,
            openapi: new Operation(
                summary: 'Closes a month, which locks its revenue entries.',
                description: 'Owners only (403 otherwise). The month must have ended in the organization\'s time zone (409) and be open (409); days or registers without an entry do not matter. Once closed, no revenue entry of the month is recorded, changed or deleted (409), and Cash flow refuses end-of-day closes of the month and cash writes that would change their cash income (409).',
                parameters: [new Parameter('month', 'path', 'The month, YYYY-MM.', true)],
                responses: [
                    '200' => new Response('The closed month.'),
                    '403' => new Response('The signed-in user is not an owner.'),
                    '404' => new Response('The month is not YYYY-MM.'),
                    '409' => new Response('The month has not ended, or is closed already.'),
                    '422' => new Response('The month does not exist (e.g. 2026-13).'),
                ],
            ),
            input: false,
            read: false,
            processor: CloseRevenueMonthProcessor::class,
        ),
        new Post(
            uriTemplate: '/revenue-months/{month}/reopen',
            uriVariables: ['month'],
            requirements: ['month' => self::MONTH],
            status: 200,
            openapi: new Operation(
                summary: 'Reopens a closed month, which unlocks its revenue entries.',
                description: 'Owners only (403 otherwise). The month must be closed (409). It can be closed again later.',
                parameters: [new Parameter('month', 'path', 'The month, YYYY-MM.', true)],
                responses: [
                    '200' => new Response('The reopened month.'),
                    '403' => new Response('The signed-in user is not an owner.'),
                    '404' => new Response('The month is not YYYY-MM.'),
                    '409' => new Response('The month is not closed.'),
                    '422' => new Response('The month does not exist (e.g. 2026-13).'),
                ],
            ),
            input: false,
            read: false,
            processor: ReopenRevenueMonthProcessor::class,
        ),
    ],
)]
final readonly class RevenueMonthResource
{
    public const string MONTH = '\d{4}-\d{2}';

    /** @param list<RevenueMonthAction> $actions oldest first */
    public function __construct(
        /** YYYY-MM */
        public string $month,
        /** The month's first business date, YYYY-MM-DD. */
        public string $firstDate,
        /** The month's last business date, YYYY-MM-DD. */
        public string $lastDate,
        /** Whether the month has ended in the organization's time zone: only then can it be closed. */
        public bool $ended,
        public bool $closed,
        /** The owner who closed the month, while it is closed. */
        public ?string $closedBy,
        public ?\DateTimeImmutable $closedAt,
        /** Every close and reopen, oldest first. */
        public array $actions,
    ) {
    }

    public static function fromView(RevenueMonthView $view): self
    {
        return new self(
            month: $view->month,
            firstDate: $view->firstDate,
            lastDate: $view->lastDate,
            ended: $view->ended,
            closed: $view->closed,
            closedBy: $view->closedBy,
            closedAt: $view->closedAt,
            actions: array_map(RevenueMonthAction::fromView(...), $view->actions),
        );
    }
}
