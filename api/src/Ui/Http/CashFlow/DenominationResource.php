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
use App\CashFlow\Application\Query\GetDenomination\DenominationView;
use Symfony\Component\Routing\Requirement\Requirement;
use Ui\Http\CashFlow\Processor\AddDenominationProcessor;
use Ui\Http\CashFlow\Processor\DeactivateDenominationProcessor;
use Ui\Http\CashFlow\Processor\DeleteDenominationProcessor;
use Ui\Http\CashFlow\Processor\ReactivateDenominationProcessor;
use Ui\Http\CashFlow\Provider\DenominationCollectionProvider;
use Ui\Http\CashFlow\Provider\DenominationProvider;

/**
 * A coin or note the organization selected by the X-Organization-Id header counts its cash in (ADR
 * 0033 §2), in its currency. Every active member reads them, because the end-of-day close needs
 * them; owners and managers add, deactivate, reactivate and delete them (403 otherwise). One that no
 * close or movement uses can be deleted; a used one is deactivated instead.
 */
#[ApiResource(
    shortName: 'Denomination',
    operations: [
        new GetCollection(
            uriTemplate: '/denominations',
            paginationEnabled: false,
            openapi: new Operation(
                summary: 'Lists the denominations of the selected organization\'s currency.',
                description: 'Active denominations only, unless includeDeactivated is true; active ones first, each group by value, lowest first. Any active member.',
            ),
            provider: DenominationCollectionProvider::class,
            parameters: [
                'includeDeactivated' => new QueryParameter(
                    schema: ['type' => 'boolean', 'default' => false],
                    description: 'Also list deactivated denominations (to manage them).',
                    castToNativeType: true,
                ),
            ],
        ),
        new Get(
            uriTemplate: '/denominations/{id}',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: 'One denomination of the selected organization, active or deactivated.',
                description: 'Any active member. A denomination that is not the selected organization\'s is a 404.',
                responses: [
                    '404' => new Response('The denomination is not one of the selected organization\'s.'),
                ],
            ),
            provider: DenominationProvider::class,
        ),
        new Post(
            uriTemplate: '/denominations',
            openapi: new Operation(
                summary: 'Adds a denomination in the selected organization\'s currency.',
                description: 'Owners and managers only (403 otherwise). The value is 1 to 2147483647 minor units and the kind `coin` or `note` (422). The value is unique in the organization\'s currency among active and deactivated denominations (409, `cash_flow.denomination_value_taken`).',
                responses: [
                    '201' => new Response('The new denomination, active.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '409' => new Response('The organization already has a denomination of the value.'),
                    '422' => new Response('The value or the kind is missing or invalid.'),
                ],
            ),
            input: AddDenominationInput::class,
            read: false,
            processor: AddDenominationProcessor::class,
        ),
        new Post(
            uriTemplate: '/denominations/{id}/deactivate',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            status: 200,
            openapi: new Operation(
                summary: 'Deactivates a denomination.',
                description: 'Owners and managers only (403 otherwise); a denomination that is not the selected organization\'s is a 404. It is no longer offered from now on. The request has no body. Deactivating a deactivated denomination changes nothing.',
                responses: [
                    '200' => new Response('The deactivated denomination.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The denomination is not one of the selected organization\'s.'),
                ],
            ),
            input: false,
            read: false,
            processor: DeactivateDenominationProcessor::class,
        ),
        new Post(
            uriTemplate: '/denominations/{id}/reactivate',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            status: 200,
            openapi: new Operation(
                summary: 'Reactivates a denomination.',
                description: 'Owners and managers only (403 otherwise); a denomination that is not the selected organization\'s is a 404. It is offered again. The request has no body. Reactivating an active denomination changes nothing.',
                responses: [
                    '200' => new Response('The active denomination.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The denomination is not one of the selected organization\'s.'),
                ],
            ),
            input: false,
            read: false,
            processor: ReactivateDenominationProcessor::class,
        ),
        new Delete(
            uriTemplate: '/denominations/{id}',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: 'Deletes a denomination that no close or movement uses.',
                description: 'Owners and managers only (403 otherwise); a denomination that is not the selected organization\'s is a 404. A used one cannot be deleted (409, `cash_flow.denomination_in_use`): deactivate it instead.',
                responses: [
                    '204' => new Response('The denomination is deleted.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The denomination is not one of the selected organization\'s.'),
                    '409' => new Response('A close or movement uses the denomination.'),
                ],
            ),
            read: false,
            processor: DeleteDenominationProcessor::class,
        ),
    ],
)]
final readonly class DenominationResource
{
    public function __construct(
        public string $id,
        /** ISO 4217: the organization's when the denomination was added. */
        public string $currency,
        /** Face value in minor units. */
        public int $value,
        #[ApiProperty(schema: ['type' => 'string', 'enum' => ['coin', 'note']])]
        public string $kind,
        /** False once deactivated: the denomination is no longer offered. */
        public bool $active,
        /** Whether a close or movement uses it: a used denomination cannot be deleted, only deactivated. */
        public bool $used,
        public \DateTimeImmutable $createdAt,
        /** When the denomination was deactivated; null while it is active. */
        public ?\DateTimeImmutable $deactivatedAt,
    ) {
    }

    public static function fromView(DenominationView $view): self
    {
        return new self($view->id, $view->currency, $view->value, $view->kind, $view->active, $view->used, $view->createdAt, $view->deactivatedAt);
    }
}
