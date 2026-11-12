<?php

declare(strict_types=1);

namespace Ui\Http\Income;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\Income\Application\Query\GetRegister\RegisterView;
use Symfony\Component\Routing\Requirement\Requirement;
use Ui\Http\Income\Processor\AddRegisterProcessor;
use Ui\Http\Income\Processor\DeactivateRegisterProcessor;
use Ui\Http\Income\Processor\UpdateRegisterProcessor;
use Ui\Http\Income\Provider\RegisterCollectionProvider;
use Ui\Http\Income\Provider\RegisterProvider;
use Ui\Http\Income\Provider\UpdateRegisterInputProvider;

/**
 * A register (a till with its own fiscal printer) of the organization selected by the
 * X-Organization-Id header; revenue entries are recorded per register and business date (ADR 0022
 * §2). Owners and managers only: anyone else gets a 403. Registers are deactivated, never deleted:
 * a deactivated register takes no new entries, and its entries stay visible and editable.
 */
#[ApiResource(
    shortName: 'Register',
    operations: [
        new GetCollection(
            uriTemplate: '/registers',
            paginationEnabled: false,
            openapi: new Operation(
                summary: 'Lists the registers of the selected organization.',
                description: 'Active registers only, unless includeDeactivated is true; active ones first, then by name. Owners and managers only (403 otherwise).',
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                ],
            ),
            provider: RegisterCollectionProvider::class,
            parameters: [
                'includeDeactivated' => new QueryParameter(
                    schema: ['type' => 'boolean', 'default' => false],
                    description: 'Also list deactivated registers (for filters and for the entries they still have).',
                    castToNativeType: true,
                ),
            ],
        ),
        new Get(
            uriTemplate: '/registers/{id}',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: 'One register of the selected organization, active or deactivated.',
                description: 'Owners and managers only (403 otherwise). A register that is not the selected organization\'s is a 404.',
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The register is not one of the selected organization\'s.'),
                ],
            ),
            provider: RegisterProvider::class,
        ),
        new Post(
            uriTemplate: '/registers',
            openapi: new Operation(
                summary: 'Adds a register to the selected organization.',
                description: 'Owners and managers only (403 otherwise). The name is trimmed, 1 to 64 characters (422), and unique in the organization among active and deactivated registers, compared as written (409).',
                responses: [
                    '201' => new Response('The new register, active.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '409' => new Response('A register of the organization already has the name.'),
                    '422' => new Response('The name is missing, blank or too long.'),
                ],
            ),
            input: AddRegisterInput::class,
            read: false,
            processor: AddRegisterProcessor::class,
        ),
        new Patch(
            uriTemplate: '/registers/{id}',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: 'Renames a register.',
                description: 'Owners and managers only (403 otherwise); a register that is not the selected organization\'s is a 404. A deactivated register can be renamed too. The rules of POST apply to the name (409, 422); renaming to the current name changes nothing. Its entries show the new name.',
                responses: [
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The register is not one of the selected organization\'s.'),
                    '409' => new Response('Another register of the organization has the name.'),
                    '422' => new Response('The name is blank or too long.'),
                ],
            ),
            input: UpdateRegisterInput::class,
            provider: UpdateRegisterInputProvider::class,
            processor: UpdateRegisterProcessor::class,
        ),
        new Post(
            uriTemplate: '/registers/{id}/deactivate',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            status: 200,
            openapi: new Operation(
                summary: 'Deactivates a register.',
                description: 'Owners and managers only (403 otherwise); a register that is not the selected organization\'s is a 404. The register takes no new entries from now on (POST /revenue-entries answers 422); its existing entries stay visible and editable. The request has no body. Deactivating a deactivated register changes nothing.',
                responses: [
                    '200' => new Response('The deactivated register.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The register is not one of the selected organization\'s.'),
                ],
            ),
            input: false,
            read: false,
            processor: DeactivateRegisterProcessor::class,
        ),
    ],
)]
final readonly class RegisterResource
{
    public function __construct(
        public string $id,
        public string $name,
        /** False once deactivated: the register takes no new entries. */
        public bool $active,
        public \DateTimeImmutable $createdAt,
        /** When the register was deactivated; null while it is active. */
        public ?\DateTimeImmutable $deactivatedAt,
    ) {
    }

    public static function fromView(RegisterView $view): self
    {
        return new self($view->id, $view->name, $view->active, $view->createdAt, $view->deactivatedAt);
    }
}
