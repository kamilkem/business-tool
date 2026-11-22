<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\CashFlow\Application\Query\ListCashBalances\CashBalanceView;
use Ui\Http\CashFlow\Provider\CashBalanceCollectionProvider;

/**
 * What a cash storage of the organization selected by the X-Organization-Id header holds now,
 * derived from its ledger (ADR 0033 §4): its last count plus every movement since. Owners and
 * managers only; employees see no balance (403).
 */
#[ApiResource(
    shortName: 'CashBalance',
    operations: [
        new GetCollection(
            uriTemplate: '/cash-balances',
            paginationEnabled: false,
            openapi: new Operation(
                summary: 'Lists the current balance of each cash storage of the selected organization.',
                description: 'Owners and managers only (403 otherwise, `cash_flow.access_forbidden.to_read_ledger`). Every active storage, and a deactivated one while it still holds cash; active ones first, each group by name. Each lists every active denomination of the organization\'s currency, then any other one it holds. A counted storage\'s balance does not include the takings since its last close: it is an estimate.',
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                ],
            ),
            provider: CashBalanceCollectionProvider::class,
        ),
    ],
)]
final readonly class CashBalanceResource
{
    /** @param list<CashBalanceLine> $lines by currency (the organization's first), then value */
    public function __construct(
        public string $storageId,
        public string $name,
        #[ApiProperty(schema: ['type' => 'string', 'enum' => ['counted', 'deposit']])]
        public string $mode,
        public bool $active,
        /** ISO 4217: the organization's currency, which the total is in. */
        public string $currency,
        public array $lines,
        /** The value of the lines in the organization's currency, in minor units. */
        public int $total,
        /** True for a counted storage: between closes its balance does not include the day's takings. */
        public bool $estimate,
        /** The moment of the last count the balance starts from; null when the storage was never counted. */
        public ?\DateTimeImmutable $countedAt,
    ) {
    }

    public static function fromView(CashBalanceView $view): self
    {
        return new self(
            $view->storageId,
            $view->name,
            $view->mode,
            $view->active,
            $view->currency,
            array_map(CashBalanceLine::fromView(...), $view->lines),
            $view->total,
            $view->estimate,
            $view->countedAt,
        );
    }
}
