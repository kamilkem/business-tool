<?php

declare(strict_types=1);

namespace App\Secret\Domain\Repository;

use App\Secret\Domain\Model\OrganizationSecret;
use Symfony\Component\Uid\Uuid;

/** Secrets of the current organization only (TenantFilter). */
interface OrganizationSecretRepositoryInterface
{
    public function findById(Uuid $id): ?OrganizationSecret;

    /** @return list<OrganizationSecret> secrets whose value is not encrypted with this key, by id */
    public function findNotUnderKey(string $keyId): array;

    public function add(OrganizationSecret $secret): void;

    /** Persists changes made to an already managed secret. */
    public function save(OrganizationSecret $secret): void;

    public function remove(OrganizationSecret $secret): void;
}
