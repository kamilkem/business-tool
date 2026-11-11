<?php

declare(strict_types=1);

namespace Ui\Http\Organization;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Organization\Application\Query\ListMembers\MemberView;
use App\Organization\Domain\Model\MembershipRole;
use Ui\Http\Organization\Provider\MemberCollectionProvider;

/**
 * People with access to the organization selected by the X-Organization-Id header. Only owners
 * and managers may list them.
 */
#[ApiResource(
    shortName: 'Member',
    operations: [
        new GetCollection(
            uriTemplate: '/members',
            paginationEnabled: false,
            provider: MemberCollectionProvider::class,
        ),
    ],
)]
final readonly class MemberResource
{
    public function __construct(
        public string $userId,
        public string $email,
        public string $firstName,
        public string $lastName,
        public MembershipRole $role,
        /** When the person was added to the organization. */
        public \DateTimeImmutable $joinedAt,
        /** The person was invited but has not set a password yet, so they cannot sign in. */
        public bool $invitationPending,
    ) {
    }

    public static function fromView(MemberView $view): self
    {
        return new self(
            userId: $view->userId,
            email: $view->email,
            firstName: $view->firstName,
            lastName: $view->lastName,
            role: MembershipRole::from($view->role),
            joinedAt: $view->joinedAt,
            invitationPending: $view->invitationPending,
        );
    }
}
