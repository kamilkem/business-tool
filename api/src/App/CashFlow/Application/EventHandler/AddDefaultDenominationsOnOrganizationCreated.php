<?php

declare(strict_types=1);

namespace App\CashFlow\Application\EventHandler;

use App\CashFlow\Application\Command\AddDenomination\AddDenominationCommand;
use App\CashFlow\Domain\Exception\DenominationValueTakenException;
use App\CashFlow\Domain\Model\DefaultDenominations;
use App\Organization\Domain\Event\OrganizationCreated;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Repository\OrganizationRepositoryInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Domain\Event\EventHandlerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A new organization starts with the default denominations of its currency (DefaultDenominations,
 * ADR 0033 §2), added as its owner adds them, as it gets its payment methods. The request that
 * created the organization may have another one selected (X-Organization-Id), so they are added with
 * the new organization selected, and the previous selection comes back afterwards. A value the
 * organization already has is skipped, so handling the event twice adds nothing.
 */
final readonly class AddDefaultDenominationsOnOrganizationCreated implements EventHandlerInterface
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
            foreach (DefaultDenominations::forCurrency($organization->getCurrency()) as $default) {
                try {
                    $this->commandBus->handle(new AddDenominationCommand(
                        $event->organizationId,
                        $event->ownerId,
                        Uuid::v7()->toRfc4122(),
                        $default['value'],
                        $default['kind']->value,
                    ));
                } catch (DenominationValueTakenException) {
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
