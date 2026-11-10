<?php

declare(strict_types=1);

namespace Ui\Http\User;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Organization\Domain\Model\MembershipRole;
use App\User\Application\Query\GetMe\MeMembershipView;
use App\User\Application\Query\GetMe\MeView;
use Ui\Http\User\Provider\GetMeProvider;

/** The signed-in user and the organizations they can access. */
#[ApiResource(
    shortName: 'Me',
    operations: [
        new Get(uriTemplate: '/me', provider: GetMeProvider::class),
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
            memberships: array_map(
                static fn (MeMembershipView $membership): MeMembership => new MeMembership(
                    id: $membership->id,
                    role: MembershipRole::from($membership->role),
                    organization: new MeOrganization($membership->organizationId, $membership->organizationName),
                ),
                $view->memberships,
            ),
        );
    }
}
