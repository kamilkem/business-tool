<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Doctrine\Tenant;

use App\Organization\Domain\Model\TenantAwareInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Restricts every query on a TenantAwareInterface entity to the current organization. Enabled
 * globally in doctrine.yaml.
 *
 * Secure by default: with no organization selected (no X-Organization-Id header, or a console
 * command that did not call TenantContextInterface::switchTo()), tenant-aware tables return no rows.
 */
final class TenantFilter extends SQLFilter
{
    public const string NAME = 'tenant';
    public const string PARAMETER = 'organization_id';

    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if (!is_subclass_of($targetEntity->getName(), TenantAwareInterface::class)) {
            return '';
        }

        if (!$this->hasParameter(self::PARAMETER)) {
            return '1 = 0';
        }

        return \sprintf(
            '%s.%s = %s',
            $targetTableAlias,
            $targetEntity->getSingleAssociationJoinColumnName('organization'),
            $this->getParameter(self::PARAMETER),
        );
    }
}
