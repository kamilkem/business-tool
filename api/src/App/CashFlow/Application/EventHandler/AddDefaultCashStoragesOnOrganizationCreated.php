<?php

declare(strict_types=1);

namespace App\CashFlow\Application\EventHandler;

use App\CashFlow\Application\Command\AddCashStorage\AddCashStorageCommand;
use App\CashFlow\Domain\Exception\StorageNameTakenException;
use App\CashFlow\Domain\Model\DefaultCashStorages;
use App\Organization\Domain\Event\OrganizationCreated;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Repository\OrganizationRepositoryInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Domain\Event\EventHandlerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A new organization starts with the default cash storages of its country (DefaultCashStorages,
 * ADR 0033 §3), added as its owner adds them, as it gets its payment methods. The request that
 * created the organization may have another one selected (X-Organization-Id), so they are added with
 * the new organization selected, and the previous selection comes back afterwards. A name the
 * organization already has is skipped, so handling the event twice adds nothing.
 */
final readonly class AddDefaultCashStoragesOnOrganizationCreated implements EventHandlerInterface
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
            foreach (DefaultCashStorages::forCountry($organization->getCountryCode()) as $default) {
                try {
                    $this->commandBus->handle(new AddCashStorageCommand(
                        $event->organizationId,
                        $event->ownerId,
                        Uuid::v7()->toRfc4122(),
                        $default['name'],
                        $default['mode']->value,
                    ));
                } catch (StorageNameTakenException) {
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
