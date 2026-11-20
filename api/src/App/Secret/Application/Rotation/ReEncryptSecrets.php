<?php

declare(strict_types=1);

namespace App\Secret\Application\Rotation;

use App\Organization\Domain\Repository\OrganizationRepositoryInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Secret\Application\Command\ReEncryptOrganizationSecret\ReEncryptOrganizationSecretCommand;
use App\Secret\Application\Query\ListOrganizationSecretsToReEncrypt\ListOrganizationSecretsToReEncryptQuery;
use App\Secret\Domain\Exception\SecretUnreadableException;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;

/**
 * The rotation of ADR 0026 §4: for each organization, switches to it, asks for its secrets that are
 * not under the current key and sends one ReEncryptOrganizationSecretCommand each. A secret that does
 * not decrypt is counted and skipped (the command logs it); the others go on. Running it again only
 * touches what is left. Started by `app:secret:re-encrypt`; when the jobs runtime of ADR 0025 lands
 * (#52) this becomes its fan-out job.
 */
final readonly class ReEncryptSecrets
{
    public function __construct(
        private OrganizationRepositoryInterface $organizations,
        private TenantContextInterface $tenantContext,
        private QueryBusInterface $queryBus,
        private CommandBusInterface $commandBus,
    ) {
    }

    public function __invoke(): ReEncryptionReport
    {
        $previous = $this->tenantContext->hasOrganization() ? $this->tenantContext->getOrganization() : null;
        $organizations = 0;
        $reEncrypted = 0;
        $unreadable = [];

        try {
            foreach ($this->organizations->findAll() as $organization) {
                ++$organizations;
                $organizationId = $organization->getId()->toRfc4122();
                $this->tenantContext->switchTo($organization);

                /** @var list<string> $secretIds */
                $secretIds = $this->queryBus->ask(new ListOrganizationSecretsToReEncryptQuery($organizationId));
                foreach ($secretIds as $secretId) {
                    try {
                        $this->commandBus->handle(new ReEncryptOrganizationSecretCommand($organizationId, $secretId));
                        ++$reEncrypted;
                    } catch (SecretUnreadableException) {
                        $unreadable[] = $secretId;
                    }
                }
            }
        } finally {
            if (null === $previous) {
                $this->tenantContext->reset();
            } else {
                $this->tenantContext->switchTo($previous);
            }
        }

        return new ReEncryptionReport($organizations, $reEncrypted, $unreadable);
    }
}
