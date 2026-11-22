<?php

declare(strict_types=1);

namespace Ui\Http\Income;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\Income\Application\Query\GetPaymentMethod\PaymentMethodView;
use Symfony\Component\Routing\Requirement\Requirement;
use Ui\Http\Income\Processor\AddPaymentMethodProcessor;
use Ui\Http\Income\Processor\DeactivatePaymentMethodProcessor;
use Ui\Http\Income\Processor\DeletePaymentMethodProcessor;
use Ui\Http\Income\Processor\ReactivatePaymentMethodProcessor;
use Ui\Http\Income\Processor\UpdatePaymentMethodProcessor;
use Ui\Http\Income\Provider\PaymentMethodCollectionProvider;
use Ui\Http\Income\Provider\PaymentMethodProvider;
use Ui\Http\Income\Provider\UpdatePaymentMethodInputProvider;

/**
 * A payment method (cash, card, meal vouchers, a delivery platform, …) of the organization selected
 * by the X-Organization-Id header (ADR 0030). Revenue entries hold one line per method. Owners and
 * managers only: anyone else gets a 403. The organization orders its methods (PUT
 * /payment-methods/order); lists, the entry lines and the summaries follow that order. A method no
 * entry has used can be deleted; a used one is deactivated instead and can be reactivated. At most
 * one active method is marked as cash: the end-of-day close books the counted cash on it (ADR 0033
 * §9); a second one is a 409 (`income.cash_payment_method_taken`).
 */
#[ApiResource(
    shortName: 'PaymentMethod',
    operations: [
        new GetCollection(
            uriTemplate: '/payment-methods',
            paginationEnabled: false,
            openapi: new Operation(
                summary: 'Lists the payment methods of the selected organization.',
                description: 'Active methods only, unless includeDeactivated is true; active ones first, each group in the organization\'s order. Owners and managers only (403 otherwise).',
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                ],
            ),
            provider: PaymentMethodCollectionProvider::class,
            parameters: [
                'includeDeactivated' => new QueryParameter(
                    schema: ['type' => 'boolean', 'default' => false],
                    description: 'Also list deactivated methods (to manage them, and for the entries that still have lines for them).',
                    castToNativeType: true,
                ),
            ],
        ),
        new Get(
            uriTemplate: '/payment-methods/{id}',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: 'One payment method of the selected organization, active or deactivated.',
                description: 'Owners and managers only (403 otherwise). A method that is not the selected organization\'s is a 404.',
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The payment method is not one of the selected organization\'s.'),
                ],
            ),
            provider: PaymentMethodProvider::class,
        ),
        new Post(
            uriTemplate: '/payment-methods',
            openapi: new Operation(
                summary: 'Adds a payment method at the end of the selected organization\'s list.',
                description: 'Owners and managers only (403 otherwise). The name is trimmed, 1 to 64 characters (422), and unique in the organization among active and deactivated methods, compared as written (409). `cash` (default false) marks it as cash; another active cash method is a 409 (`income.cash_payment_method_taken`).',
                responses: [
                    '201' => new Response('The new payment method, active.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '409' => new Response('A payment method of the organization already has the name, or another active method is cash.'),
                    '422' => new Response('The name is missing, blank or too long.'),
                ],
            ),
            input: AddPaymentMethodInput::class,
            read: false,
            processor: AddPaymentMethodProcessor::class,
        ),
        new Patch(
            uriTemplate: '/payment-methods/{id}',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: 'Renames a payment method, or marks it as cash.',
                description: 'Owners and managers only (403 otherwise); a method that is not the selected organization\'s is a 404. A deactivated method can be renamed and marked too. The rules of POST apply to the name (409, 422); renaming to the current name changes nothing. Entries and summaries show the new name. Marking an active method as cash while another active method is cash is a 409 (`income.cash_payment_method_taken`): remove the other mark first.',
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The payment method is not one of the selected organization\'s.'),
                    '409' => new Response('Another payment method of the organization has the name, or another active method is cash.'),
                    '422' => new Response('The name is blank or too long.'),
                ],
            ),
            input: UpdatePaymentMethodInput::class,
            provider: UpdatePaymentMethodInputProvider::class,
            processor: UpdatePaymentMethodProcessor::class,
        ),
        new Post(
            uriTemplate: '/payment-methods/{id}/deactivate',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            status: 200,
            openapi: new Operation(
                summary: 'Deactivates a payment method.',
                description: 'Owners and managers only (403 otherwise); a method that is not the selected organization\'s is a 404. The method takes no new lines from now on (422 on an entry that does not have it yet); entries that have a line for it keep it, and an edit of them may keep it. The request has no body. Deactivating a deactivated method changes nothing.',
                responses: [
                    '200' => new Response('The deactivated payment method.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The payment method is not one of the selected organization\'s.'),
                ],
            ),
            input: false,
            read: false,
            processor: DeactivatePaymentMethodProcessor::class,
        ),
        new Post(
            uriTemplate: '/payment-methods/{id}/reactivate',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            status: 200,
            openapi: new Operation(
                summary: 'Reactivates a payment method.',
                description: 'Owners and managers only (403 otherwise); a method that is not the selected organization\'s is a 404. The method takes lines again and goes to the end of the list. The request has no body. Reactivating an active method changes nothing. A method marked as cash cannot be reactivated while another active method is cash (409, `income.cash_payment_method_taken`).',
                responses: [
                    '200' => new Response('The active payment method.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The payment method is not one of the selected organization\'s.'),
                    '409' => new Response('The method is cash and another active method is.'),
                ],
            ),
            input: false,
            read: false,
            processor: ReactivatePaymentMethodProcessor::class,
        ),
        new Delete(
            uriTemplate: '/payment-methods/{id}',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: 'Deletes a payment method that no revenue entry has used.',
                description: 'Owners and managers only (403 otherwise); a method that is not the selected organization\'s is a 404. A method that an entry has a line for cannot be deleted (409, `income.payment_method_in_use`): deactivate it instead.',
                responses: [
                    '204' => new Response('The payment method is deleted.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The payment method is not one of the selected organization\'s.'),
                    '409' => new Response('A revenue entry has a line for the payment method.'),
                ],
            ),
            read: false,
            processor: DeletePaymentMethodProcessor::class,
        ),
    ],
)]
final readonly class PaymentMethodResource
{
    public function __construct(
        public string $id,
        public string $name,
        /** False once deactivated: the method takes no new lines. */
        public bool $active,
        /** Whether the end-of-day close books the counted cash on the method (ADR 0033 §9); at most one active method is cash. */
        public bool $cash,
        /** Whether a revenue entry has a line for the method: a used method cannot be deleted, only deactivated. */
        public bool $used,
        public \DateTimeImmutable $createdAt,
        /** When the method was deactivated; null while it is active. */
        public ?\DateTimeImmutable $deactivatedAt,
    ) {
    }

    public static function fromView(PaymentMethodView $view): self
    {
        return new self($view->id, $view->name, $view->active, $view->cash, $view->used, $view->createdAt, $view->deactivatedAt);
    }
}
