<?php

declare(strict_types=1);

namespace App\Tests\Unit\Secret\Application;

use App\Secret\Domain\Model\OrganizationSecret;
use App\Secret\Domain\Repository\OrganizationSecretRepositoryInterface;
use Symfony\Component\Uid\Uuid;

/** Fake implementing the port for one organization: no container, no database. */
final class InMemoryOrganizationSecretRepository implements OrganizationSecretRepositoryInterface
{
    /** @var array<string, OrganizationSecret> */
    private array $byId = [];

    public function findById(Uuid $id): ?OrganizationSecret
    {
        return $this->byId[$id->toRfc4122()] ?? null;
    }

    public function findNotUnderKey(string $keyId): array
    {
        $secrets = array_values(array_filter($this->byId, static fn (OrganizationSecret $secret): bool => $secret->getKeyId() !== $keyId));
        usort($secrets, static fn (OrganizationSecret $a, OrganizationSecret $b): int => $a->getId()->toRfc4122() <=> $b->getId()->toRfc4122());

        return $secrets;
    }

    public function add(OrganizationSecret $secret): void
    {
        $this->byId[$secret->getId()->toRfc4122()] = $secret;
    }

    public function save(OrganizationSecret $secret): void
    {
        $this->byId[$secret->getId()->toRfc4122()] = $secret;
    }

    public function remove(OrganizationSecret $secret): void
    {
        unset($this->byId[$secret->getId()->toRfc4122()]);
    }
}
