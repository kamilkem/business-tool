<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Doctrine\Tenant;

use App\Organization\Domain\Model\TenantAwareInterface;
use App\Organization\Domain\TenantContextInterface;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;

/** Assigns the current organization to new tenant-aware entities. */
#[AsDoctrineListener(event: Events::prePersist)]
final readonly class TenantAssignListener
{
    public function __construct(private TenantContextInterface $tenantContext)
    {
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof TenantAwareInterface && null === $entity->getOrganization()) {
            $entity->assignOrganization($this->tenantContext->getOrganization());
        }
    }
}
