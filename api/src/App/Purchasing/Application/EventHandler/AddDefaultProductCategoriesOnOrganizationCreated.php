<?php

declare(strict_types=1);

namespace App\Purchasing\Application\EventHandler;

use App\Organization\Domain\Event\OrganizationCreated;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Repository\OrganizationRepositoryInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Application\Command\AddProductCategory\AddProductCategoryCommand;
use App\Purchasing\Application\Query\ListProductCategories\ListProductCategoriesQuery;
use App\Purchasing\Domain\Model\DefaultProductCategories;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Event\EventHandlerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A new organization starts with the default product categories of its country
 * (DefaultProductCategories, ADR 0041 §5), added as its owner adds them. The request that created
 * the organization may have another one selected (X-Organization-Id), so the categories are added
 * with the new organization selected, and the previous selection comes back afterwards. An
 * organization that already has categories gets none, so handling the event twice adds nothing.
 */
final readonly class AddDefaultProductCategoriesOnOrganizationCreated implements EventHandlerInterface
{
    public function __construct(
        private OrganizationRepositoryInterface $organizations,
        private TenantContextInterface $tenantContext,
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
    ) {
    }

    /** @throws OrganizationNotFoundException */
    public function __invoke(OrganizationCreated $event): void
    {
        $organization = $this->organizations->findById(Uuid::fromString($event->organizationId))
            ?? throw OrganizationNotFoundException::forId($event->organizationId);

        $previous = $this->tenantContext->hasOrganization() ? $this->tenantContext->getOrganization() : null;
        $this->tenantContext->switchTo($organization);
        try {
            if ([] !== $this->queryBus->ask(new ListProductCategoriesQuery($event->organizationId, $event->ownerId))) {
                return;
            }

            foreach (DefaultProductCategories::forCountry($organization->getCountryCode()) as $top) {
                $parentId = Uuid::v7()->toRfc4122();
                $this->add($event, $parentId, $top['name'], null);
                foreach ($top['children'] as $name) {
                    $this->add($event, Uuid::v7()->toRfc4122(), $name, $parentId);
                }
            }
        } finally {
            if (null === $previous) {
                $this->tenantContext->reset();
            } else {
                $this->tenantContext->switchTo($previous);
            }
        }
    }

    private function add(OrganizationCreated $event, string $categoryId, string $name, ?string $parentId): void
    {
        $this->commandBus->handle(new AddProductCategoryCommand($event->organizationId, $event->ownerId, $categoryId, $name, $parentId));
    }
}
