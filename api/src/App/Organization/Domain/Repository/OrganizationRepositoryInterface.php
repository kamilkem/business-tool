<?php

declare(strict_types=1);

namespace App\Organization\Domain\Repository;

use App\Organization\Domain\Model\Organization;
use Symfony\Component\Uid\Uuid;

interface OrganizationRepositoryInterface
{
    public function findById(Uuid $id): ?Organization;

    /** @return list<Organization> organizations the user is an active member of, by name */
    public function findByMember(Uuid $userId): array;

    /**
     * Every organization, by id: for work outside a request that goes through all of them
     * (e.g. re-encrypting secrets, ADR 0026 §4), which then switches to each one.
     *
     * @return list<Organization>
     */
    public function findAll(): array;

    public function add(Organization $organization): void;

    /** Persists changes made to an already managed aggregate. */
    public function save(Organization $organization): void;
}
