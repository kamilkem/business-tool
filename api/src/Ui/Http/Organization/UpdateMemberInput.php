<?php

declare(strict_types=1);

namespace Ui\Http\Organization;

use App\Organization\Application\Query\ListMembers\MemberView;
use App\Organization\Domain\Model\MembershipRole;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Merge-patch input. Mutable on purpose: UpdateMemberInputProvider pre-fills it from the current
 * state and API Platform applies the request body onto it, so fields the client did not send keep
 * their value.
 */
final class UpdateMemberInput
{
    /** Owners give any role, themselves included, as long as an owner remains; managers keep employees as employees. */
    #[Assert\NotNull]
    public MembershipRole $role;

    public static function fromView(MemberView $view): self
    {
        $input = new self();
        $input->role = MembershipRole::from($view->role);

        return $input;
    }
}
