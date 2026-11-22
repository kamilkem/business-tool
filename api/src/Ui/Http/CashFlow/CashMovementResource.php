<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\CashFlow\Application\Query\GetCashMovement\CashMovementView;
use App\CashFlow\Application\Query\ListCashMovements\ListCashMovementsQuery;
use Symfony\Component\Routing\Requirement\Requirement;
use Ui\Http\CashFlow\Processor\DeleteCashMovementProcessor;
use Ui\Http\CashFlow\Processor\RecordCashAdjustmentProcessor;
use Ui\Http\CashFlow\Processor\RecordCashCountProcessor;
use Ui\Http\CashFlow\Processor\RecordCashTransferProcessor;
use Ui\Http\CashFlow\Provider\CashMovementCollectionProvider;
use Ui\Http\CashFlow\Provider\CashMovementProvider;

/**
 * One action of an owner or manager on the cash ledger of the organization selected by the
 * X-Organization-Id header (ADR 0033 §4): an adjustment of one storage, a transfer between two, or
 * an audit count of a deposit storage. Owners and managers only; employees get a 403. Movements are
 * not edited: a wrong one is deleted and entered again. A deposit storage never goes below zero
 * pieces of a denomination at any point of its ledger (409, `cash_flow.storage_balance_negative`).
 */
#[ApiResource(
    shortName: 'CashMovement',
    operations: [
        new GetCollection(
            uriTemplate: '/cash-movements',
            paginationEnabled: false,
            openapi: new Operation(
                summary: 'Lists the cash movements of the selected organization from one business date to another.',
                description: 'Owners and managers only (403 otherwise, `cash_flow.access_forbidden.to_read_ledger`). The movements at a moment within the dates, in the organization\'s time zone, newest first. storageId narrows the list to the movements on one storage of the selected organization (404 otherwise). Invalid dates, to before from and periods longer than '.ListCashMovementsQuery::MAX_DAYS.' days are a 422.',
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The storageId is not a storage of the selected organization.'),
                    '422' => new Response('A date is missing or invalid, to is before from, the period is too long, or storageId is not a UUID.'),
                ],
            ),
            // ListCashMovementsInput validates the parameters, cross-field rules included; the schemas below only document them.
            queryParameterValidationEnabled: false,
            provider: CashMovementCollectionProvider::class,
            parameters: [
                'from' => new QueryParameter(
                    schema: ['type' => 'string', 'format' => 'date'],
                    description: 'First business date, inclusive (YYYY-MM-DD).',
                    required: true,
                ),
                'to' => new QueryParameter(
                    schema: ['type' => 'string', 'format' => 'date'],
                    description: 'Last business date, inclusive (YYYY-MM-DD); at most '.ListCashMovementsQuery::MAX_DAYS.' days from the first.',
                    required: true,
                ),
                'storageId' => new QueryParameter(
                    schema: ['type' => 'string', 'format' => 'uuid'],
                    description: 'Only the movements on this storage.',
                ),
            ],
        ),
        new Get(
            uriTemplate: '/cash-movements/{id}',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: 'One cash movement of the selected organization.',
                description: 'Owners and managers only (403 otherwise). A movement that is not the selected organization\'s is a 404.',
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The movement is not one of the selected organization\'s.'),
                ],
            ),
            provider: CashMovementProvider::class,
        ),
        new Post(
            uriTemplate: '/cash-movements/adjustments',
            openapi: new Operation(
                summary: 'Adds cash to or takes cash out of one storage.',
                description: 'Owners and managers only (403 otherwise). Signed pieces per denomination, never zero, each denomination once (422). The storage and the denominations must be the selected organization\'s (404), the denominations in its currency (422). A deactivated storage or denomination is only taken out of (422). occurredAt is now when left out, and never after now (422). A deposit storage may not go below zero pieces of a denomination at that moment or any later one (409).',
                responses: [
                    '201' => new Response('The recorded movement.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The storage or a denomination is not one of the selected organization\'s.'),
                    '409' => new Response('A deposit storage would hold fewer than zero pieces of a denomination.'),
                    '422' => new Response('A field is missing or invalid, a denomination is not offered, or the moment is after now.'),
                ],
            ),
            input: RecordCashAdjustmentInput::class,
            read: false,
            processor: RecordCashAdjustmentProcessor::class,
        ),
        new Post(
            uriTemplate: '/cash-movements/transfers',
            openapi: new Operation(
                summary: 'Moves cash from one storage into another.',
                description: 'Owners and managers only (403 otherwise). Positive pieces per denomination, each denomination once, between two different storages (422). The storages and the denominations must be the selected organization\'s (404), the denominations active and in its currency (422), and the storage the cash goes into active (422). occurredAt is now when left out, and never after now (422). A deposit storage may not go below zero pieces of a denomination at that moment or any later one (409).',
                responses: [
                    '201' => new Response('The recorded movement.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('A storage or a denomination is not one of the selected organization\'s.'),
                    '409' => new Response('A deposit storage would hold fewer than zero pieces of a denomination.'),
                    '422' => new Response('A field is missing or invalid, the storages are the same, a denomination or a storage is not offered, or the moment is after now.'),
                ],
            ),
            input: RecordCashTransferInput::class,
            read: false,
            processor: RecordCashTransferProcessor::class,
        ),
        new Post(
            uriTemplate: '/cash-movements/counts',
            openapi: new Operation(
                summary: 'Records an audit count of a deposit storage.',
                description: 'Owners and managers only (403 otherwise). The pieces of each denomination the storage holds, zero or more, each denomination once (422); an active denomination of the organization\'s currency left out counts as zero. The count replaces the storage\'s balance; its discrepancy is the counted value minus the balance just before it. Only an active deposit storage is audited: a counted storage is counted at each close (409, `cash_flow.audit_count_needs_deposit_storage`). occurredAt is now when left out, and never after now (422). A backdated count may not leave the storage below zero later (409).',
                responses: [
                    '201' => new Response('The recorded count.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The storage or a denomination is not one of the selected organization\'s.'),
                    '409' => new Response('The storage is counted at each close, or it would hold fewer than zero pieces of a denomination later.'),
                    '422' => new Response('A field is missing or invalid, a denomination or the storage is not offered, or the moment is after now.'),
                ],
            ),
            input: RecordCashCountInput::class,
            read: false,
            processor: RecordCashCountProcessor::class,
        ),
        new Delete(
            uriTemplate: '/cash-movements/{id}',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: 'Deletes a wrong cash movement, to enter it again.',
                description: 'Owners and managers only (403 otherwise); a movement that is not the selected organization\'s is a 404. A deposit storage may not go below zero pieces of a denomination without it (409).',
                responses: [
                    '204' => new Response('The movement is deleted.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The movement is not one of the selected organization\'s.'),
                    '409' => new Response('A deposit storage would hold fewer than zero pieces of a denomination.'),
                ],
            ),
            read: false,
            processor: DeleteCashMovementProcessor::class,
        ),
    ],
)]
final readonly class CashMovementResource
{
    /** @param list<CashMovementLine> $lines by value, lowest first */
    public function __construct(
        public string $id,
        #[ApiProperty(schema: ['type' => 'string', 'enum' => ['adjustment', 'transfer', 'count']])]
        public string $kind,
        public \DateTimeImmutable $occurredAt,
        /** The storage adjusted or counted, or the one a transfer takes cash out of. */
        public string $storageId,
        /** The storage's current name. */
        public string $storageName,
        /** The storage a transfer puts cash into; null for the other kinds. */
        public ?string $targetStorageId,
        public ?string $targetStorageName,
        /** ISO 4217: the currency of the denominations. */
        public string $currency,
        public array $lines,
        /** The sum of the lines' amounts, in minor units: signed for an adjustment, moved for a transfer, counted for a count. */
        public int $total,
        /** A count's counted total minus the storage's balance just before it, in minor units; null for the other kinds. */
        public ?int $discrepancy,
        public ?string $note,
        /** The owner or manager who entered it. */
        public string $createdBy,
        public string $createdByFirstName,
        public string $createdByLastName,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    public static function fromView(CashMovementView $view): self
    {
        return new self(
            id: $view->id,
            kind: $view->kind,
            occurredAt: $view->occurredAt,
            storageId: $view->storageId,
            storageName: $view->storageName,
            targetStorageId: $view->targetStorageId,
            targetStorageName: $view->targetStorageName,
            currency: $view->currency,
            lines: array_map(CashMovementLine::fromView(...), $view->lines),
            total: $view->total,
            discrepancy: $view->discrepancy,
            note: $view->note,
            createdBy: $view->createdById,
            createdByFirstName: $view->createdByFirstName,
            createdByLastName: $view->createdByLastName,
            createdAt: $view->createdAt,
        );
    }
}
