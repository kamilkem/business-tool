<?php

declare(strict_types=1);

namespace Ui\Http\Income;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Put;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use Ui\Http\Income\Processor\ReorderPaymentMethodsProcessor;

/**
 * The order of the active payment methods of the organization selected by the X-Organization-Id
 * header (ADR 0030 §3). Write-only: GET /payment-methods lists them in this order.
 */
#[ApiResource(
    shortName: 'PaymentMethodOrder',
    operations: [
        new Put(
            uriTemplate: '/payment-methods/order',
            status: 204,
            openapi: new Operation(
                summary: 'Puts the active payment methods of the selected organization in a new order.',
                description: 'Owners and managers only (403 otherwise). The body lists every active method\'s id once, in the new order; deactivated methods keep their place after the active ones. Ids that are not exactly the active methods (one was added, deactivated or reactivated meanwhile, or belongs to another organization) are a 409 (`income.payment_method_order_mismatch`): read the list again. Duplicates and malformed ids are a 422.',
                responses: [
                    '204' => new Response('The methods are in the new order.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '409' => new Response('The ids are not exactly the organization\'s active payment methods.'),
                    '422' => new Response('The list is empty, has a duplicate or a malformed id.'),
                ],
            ),
            input: ReorderPaymentMethodsInput::class,
            output: false,
            read: false,
            processor: ReorderPaymentMethodsProcessor::class,
            allowCreate: true,
        ),
    ],
)]
final readonly class PaymentMethodOrderResource
{
    /** @param list<string> $paymentMethodIds */
    public function __construct(
        public array $paymentMethodIds,
    ) {
    }
}
