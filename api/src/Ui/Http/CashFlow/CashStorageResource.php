<?php

declare(strict_types=1);

namespace Ui\Http\CashFlow;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\CashFlow\Application\Query\GetCashStorage\CashStorageView;
use Symfony\Component\Routing\Requirement\Requirement;
use Ui\Http\CashFlow\Processor\AddCashStorageProcessor;
use Ui\Http\CashFlow\Processor\DeactivateCashStorageProcessor;
use Ui\Http\CashFlow\Processor\DeleteCashStorageProcessor;
use Ui\Http\CashFlow\Processor\ReactivateCashStorageProcessor;
use Ui\Http\CashFlow\Processor\UpdateCashStorageProcessor;
use Ui\Http\CashFlow\Provider\CashStorageCollectionProvider;
use Ui\Http\CashFlow\Provider\CashStorageProvider;
use Ui\Http\CashFlow\Provider\UpdateCashStorageInputProvider;

/**
 * A place the organization selected by the X-Organization-Id header keeps cash in (ADR 0033 §3),
 * counted at each close or deposited into. Every active member reads them, because the end-of-day
 * close needs them; they carry no balance. Owners and managers add, rename, change, deactivate,
 * reactivate and delete them (403 otherwise). One that no close or movement refers to can be
 * deleted; a used one is deactivated instead.
 */
#[ApiResource(
    shortName: 'CashStorage',
    operations: [
        new GetCollection(
            uriTemplate: '/cash-storages',
            paginationEnabled: false,
            openapi: new Operation(
                summary: 'Lists the cash storages of the selected organization.',
                description: 'Active storages only, unless includeDeactivated is true; active ones first, each group by name. Any active member.',
            ),
            provider: CashStorageCollectionProvider::class,
            parameters: [
                'includeDeactivated' => new QueryParameter(
                    schema: ['type' => 'boolean', 'default' => false],
                    description: 'Also list deactivated storages (to manage them).',
                    castToNativeType: true,
                ),
            ],
        ),
        new Get(
            uriTemplate: '/cash-storages/{id}',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: 'One cash storage of the selected organization, active or deactivated.',
                description: 'Any active member. A storage that is not the selected organization\'s is a 404.',
                responses: [
                    '404' => new Response('The storage is not one of the selected organization\'s.'),
                ],
            ),
            provider: CashStorageProvider::class,
        ),
        new Post(
            uriTemplate: '/cash-storages',
            openapi: new Operation(
                summary: 'Adds a cash storage to the selected organization.',
                description: 'Owners and managers only (403 otherwise). The name is trimmed and has 1 to 64 characters, and the mode is `counted` or `deposit` (422). The name is unique in the organization among active and deactivated storages, compared as written (409, `cash_flow.storage_name_taken`). The storage starts empty.',
                responses: [
                    '201' => new Response('The new storage, active.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '409' => new Response('Another storage of the organization has the name.'),
                    '422' => new Response('The name or the mode is missing or invalid.'),
                ],
            ),
            input: AddCashStorageInput::class,
            read: false,
            processor: AddCashStorageProcessor::class,
        ),
        new Patch(
            uriTemplate: '/cash-storages/{id}',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: 'Renames a cash storage or changes its mode.',
                description: 'Owners and managers only (403 otherwise); a storage that is not the selected organization\'s is a 404. A deactivated storage can be changed too. The rules of POST apply (409, 422); a field left out keeps its value. A mode change applies from the next close.',
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The storage is not one of the selected organization\'s.'),
                    '409' => new Response('Another storage of the organization has the name.'),
                    '422' => new Response('The name or the mode is invalid.'),
                ],
            ),
            input: UpdateCashStorageInput::class,
            provider: UpdateCashStorageInputProvider::class,
            processor: UpdateCashStorageProcessor::class,
        ),
        new Post(
            uriTemplate: '/cash-storages/{id}/deactivate',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            status: 200,
            openapi: new Operation(
                summary: 'Deactivates a cash storage.',
                description: 'Owners and managers only (403 otherwise); a storage that is not the selected organization\'s is a 404. It is no longer offered from now on. The request has no body. Deactivating a deactivated storage changes nothing.',
                responses: [
                    '200' => new Response('The deactivated storage.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The storage is not one of the selected organization\'s.'),
                ],
            ),
            input: false,
            read: false,
            processor: DeactivateCashStorageProcessor::class,
        ),
        new Post(
            uriTemplate: '/cash-storages/{id}/reactivate',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            status: 200,
            openapi: new Operation(
                summary: 'Reactivates a cash storage.',
                description: 'Owners and managers only (403 otherwise); a storage that is not the selected organization\'s is a 404. It is offered again. The request has no body. Reactivating an active storage changes nothing.',
                responses: [
                    '200' => new Response('The active storage.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The storage is not one of the selected organization\'s.'),
                ],
            ),
            input: false,
            read: false,
            processor: ReactivateCashStorageProcessor::class,
        ),
        new Delete(
            uriTemplate: '/cash-storages/{id}',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: 'Deletes a cash storage that no close or movement refers to.',
                description: 'Owners and managers only (403 otherwise); a storage that is not the selected organization\'s is a 404. A used one cannot be deleted (409, `cash_flow.storage_in_use`): deactivate it instead.',
                responses: [
                    '204' => new Response('The storage is deleted.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The storage is not one of the selected organization\'s.'),
                    '409' => new Response('A close or movement refers to the storage.'),
                ],
            ),
            read: false,
            processor: DeleteCashStorageProcessor::class,
        ),
    ],
)]
final readonly class CashStorageResource
{
    public function __construct(
        public string $id,
        public string $name,
        /** `counted`: the close counts everything in it; `deposit`: the close enters only what was put in. */
        #[ApiProperty(schema: ['type' => 'string', 'enum' => ['counted', 'deposit']])]
        public string $mode,
        /** False once deactivated: the storage is no longer offered. */
        public bool $active,
        /** Whether a close or movement refers to it: a used storage cannot be deleted, only deactivated. */
        public bool $used,
        public \DateTimeImmutable $createdAt,
        /** When the storage was deactivated; null while it is active. */
        public ?\DateTimeImmutable $deactivatedAt,
    ) {
    }

    public static function fromView(CashStorageView $view): self
    {
        return new self($view->id, $view->name, $view->mode, $view->active, $view->used, $view->createdAt, $view->deactivatedAt);
    }
}
