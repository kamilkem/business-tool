<?php

declare(strict_types=1);

namespace Ui\Http\Income\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Income\Application\Query\GetRevenueEntry\GetRevenueEntryQuery;
use App\Income\Application\Query\GetRevenueEntry\RevenueEntryView;
use App\Income\Domain\Exception\RevenueEntryNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Ui\Http\Income\UpdateRevenueEntryInput;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * Pre-fills the merge-patch input with the entry's current state; API Platform then applies the
 * request body onto it. An employee is refused here (403) and an entry that is not the
 * organization's is a 404, before the body is read.
 *
 * @implements ProviderInterface<UpdateRevenueEntryInput>
 */
final readonly class UpdateRevenueEntryInputProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): UpdateRevenueEntryInput
    {
        $entryId = $uriVariables['id'];
        \assert(\is_string($entryId));

        /** @var RevenueEntryView|null $view */
        $view = $this->queryBus->ask(new GetRevenueEntryQuery(
            $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            $this->currentUser->id(),
            $entryId,
        ));

        return UpdateRevenueEntryInput::fromView($view ?? throw RevenueEntryNotFoundException::withId($entryId));
    }
}
