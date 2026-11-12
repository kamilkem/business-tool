<?php

declare(strict_types=1);

namespace App\Expenses\Application\EventHandler;

use App\Expenses\Application\Command\AddExpenseCategory\AddExpenseCategoryCommand;
use App\Expenses\Domain\Exception\ExpenseCategoryNameTakenException;
use App\Expenses\Domain\Model\DefaultExpenseCategories;
use App\Organization\Domain\Event\OrganizationCreated;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Repository\OrganizationRepositoryInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Domain\Event\EventHandlerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A new organization starts with the default expense categories of its country
 * (DefaultExpenseCategories), added as its owner adds them. The request that created the
 * organization may have another one selected (X-Organization-Id), so the categories are added with
 * the new organization selected, and the previous selection comes back afterwards. A name the
 * organization already has is skipped, so handling the event twice adds nothing.
 */
final readonly class AddDefaultCategoriesOnOrganizationCreated implements EventHandlerInterface
{
    public function __construct(
        private OrganizationRepositoryInterface $organizations,
        private TenantContextInterface $tenantContext,
        private CommandBusInterface $commandBus,
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
            foreach (DefaultExpenseCategories::forCountry($organization->getCountryCode()) as $name) {
                try {
                    $this->commandBus->handle(new AddExpenseCategoryCommand(
                        $event->organizationId,
                        $event->ownerId,
                        Uuid::v7()->toRfc4122(),
                        $name,
                    ));
                } catch (ExpenseCategoryNameTakenException) {
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
}
