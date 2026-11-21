<?php

declare(strict_types=1);

namespace Ui\Http\User;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use App\Organization\Domain\Model\MembershipRole;
use App\Shared\Domain\Language\Language;
use App\User\Application\Query\GetMe\MeMembershipView;
use App\User\Application\Query\GetMe\MeView;
use Ui\Http\User\Processor\UpdateMeProcessor;
use Ui\Http\User\Provider\GetMeProvider;
use Ui\Http\User\Provider\UpdateMeInputProvider;

/** The signed-in user and the organizations they can access. */
#[ApiResource(
    shortName: 'Me',
    operations: [
        new Get(uriTemplate: '/me', provider: GetMeProvider::class),
        // The signed-in user's own settings; nobody changes another user's (ADR 0028 §2).
        new Patch(
            uriTemplate: '/me',
            input: UpdateMeInput::class,
            // A URI without variables is not read by default; the provider pre-fills the merge-patch input.
            read: true,
            provider: UpdateMeInputProvider::class,
            processor: UpdateMeProcessor::class,
        ),
    ],
)]
final readonly class MeResource
{
    /** @param list<MeMembership> $memberships */
    public function __construct(
        public string $id,
        public string $email,
        public string $firstName,
        public string $lastName,
        /** The UI language the user chose; null when they never chose one, and the apps use the browser's (ADR 0028). */
        public ?Language $language,
        public array $memberships,
    ) {
    }

    public static function fromView(MeView $view): self
    {
        return new self(
            id: $view->id,
            email: $view->email,
            firstName: $view->firstName,
            lastName: $view->lastName,
            language: null === $view->language ? null : Language::from($view->language),
            memberships: array_map(
                static fn (MeMembershipView $membership): MeMembership => new MeMembership(
                    id: $membership->id,
                    role: MembershipRole::from($membership->role),
                    organization: new MeOrganization(
                        $membership->organizationId,
                        $membership->organizationName,
                        $membership->organizationTimezone,
                        $membership->organizationCurrency,
                    ),
                ),
                $view->memberships,
            ),
        );
    }
}
