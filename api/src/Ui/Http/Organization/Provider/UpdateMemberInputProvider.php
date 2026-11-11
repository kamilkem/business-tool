<?php

declare(strict_types=1);

namespace Ui\Http\Organization\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Organization\Application\Query\GetManageableMember\GetManageableMemberQuery;
use App\Organization\Application\Query\ListMembers\MemberView;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\Organization\UpdateMemberInput;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * Pre-fills the merge-patch input with the member's current state; API Platform then applies the
 * request body onto it. A member the actor may not act on is refused here (404 or 403), before the
 * body is read; which role they may give is decided by Organization::changeMemberRole().
 *
 * @implements ProviderInterface<UpdateMemberInput>
 */
final readonly class UpdateMemberInputProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): UpdateMemberInput
    {
        $organizationId = $this->tenantContext->getOrganization()->getId()->toRfc4122();

        /** @var MemberView|null $view */
        $view = $this->queryBus->ask(new GetManageableMemberQuery(
            $organizationId,
            $this->currentUser->id(),
            (string) $uriVariables['userId'],
        ));

        return UpdateMemberInput::fromView($view ?? throw OrganizationNotFoundException::forId($organizationId));
    }
}
