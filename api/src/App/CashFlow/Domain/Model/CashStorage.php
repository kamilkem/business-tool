<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Model;

use App\CashFlow\Domain\Exception\CashFlowAccessForbiddenException;
use App\CashFlow\Domain\Exception\InvalidStorageNameException;
use App\CashFlow\Domain\Exception\StorageInUseException;
use App\CashFlow\Domain\Exception\StorageNameTakenException;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\TenantAwareInterface;
use App\Organization\Domain\Model\TenantAwareTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A place the organization keeps cash in (a cash box, a safe, …), not linked to a register (ADR 0033
 * §3). Its mode says how the end-of-day close handles it (CashStorageMode).
 *
 * - Names are unique within the organization, deactivated storages included: the model checks it
 *   against the storage with the same name (CashStorageRepositoryInterface::findByName()), and the
 *   unique constraint below stops two concurrent writes.
 * - A storage starts empty; its opening float is an adjustment (#181). Its balance is derived from
 *   the ledger, never stored here.
 * - A mode change applies from the next close: earlier closes keep what they recorded.
 * - One that no close or movement refers to can be deleted. A used one is deactivated instead, and
 *   can be reactivated.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cash_storage')]
#[ORM\UniqueConstraint(name: self::UNIQUE_NAME, columns: ['organization_id', 'name'])]
class CashStorage implements TenantAwareInterface
{
    use TenantAwareTrait;

    /** Name of the unique constraint on names; the repository adapter recognizes its violations. */
    public const string UNIQUE_NAME = 'cash_storage_name_unique';

    public const int NAME_MAX_LENGTH = 64;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: self::NAME_MAX_LENGTH)]
    private string $name;

    #[ORM\Column(length: 8, enumType: CashStorageMode::class)]
    private CashStorageMode $mode;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deactivatedAt = null;

    private function __construct(Uuid $id, string $name, CashStorageMode $mode, \DateTimeImmutable $createdAt)
    {
        $this->id = $id;
        $this->name = $name;
        $this->mode = $mode;
        $this->createdAt = $createdAt;
    }

    /**
     * @param ?CashStorage $sameName the organization's storage with this name, if any
     *                               (CashStorageRepositoryInterface::findByName())
     *
     * @throws CashFlowAccessForbiddenException when the actor is not an owner or manager
     * @throws InvalidStorageNameException      when the name is blank or too long
     * @throws StorageNameTakenException        when another storage has the name
     */
    public static function create(Uuid $id, string $name, CashStorageMode $mode, ?self $sameName, Uuid $actorId, MembershipRole $actorRole, \DateTimeImmutable $now): self
    {
        CashFlowAccess::assertCanManage($actorId, $actorRole);
        $name = self::normalizeName($name);
        if (null !== $sameName) {
            throw StorageNameTakenException::of($name);
        }

        return new self($id, $name, $mode, $now);
    }

    /**
     * Renaming to the current name changes nothing. A deactivated storage can be renamed too.
     *
     * @param ?CashStorage $sameName the organization's storage with the new name, if any
     *
     * @throws CashFlowAccessForbiddenException when the actor is not an owner or manager
     * @throws InvalidStorageNameException      when the name is blank or too long
     * @throws StorageNameTakenException        when another storage has the name
     */
    public function rename(string $name, ?self $sameName, Uuid $actorId, MembershipRole $actorRole): void
    {
        CashFlowAccess::assertCanManage($actorId, $actorRole);
        $name = self::normalizeName($name);
        if (null !== $sameName && !$sameName->id->equals($this->id)) {
            throw StorageNameTakenException::of($name);
        }

        $this->name = $name;
    }

    /**
     * The next close handles the storage in the new mode; earlier closes keep what they recorded.
     * Changing to the current mode changes nothing.
     *
     * @throws CashFlowAccessForbiddenException when the actor is not an owner or manager
     */
    public function changeMode(CashStorageMode $mode, Uuid $actorId, MembershipRole $actorRole): void
    {
        CashFlowAccess::assertCanManage($actorId, $actorRole);
        $this->mode = $mode;
    }

    /**
     * The storage is no longer offered from now on. Deactivating it again keeps the first moment.
     *
     * @throws CashFlowAccessForbiddenException when the actor is not an owner or manager
     */
    public function deactivate(Uuid $actorId, MembershipRole $actorRole, \DateTimeImmutable $now): void
    {
        CashFlowAccess::assertCanManage($actorId, $actorRole);
        $this->deactivatedAt ??= $now;
    }

    /**
     * The storage is offered again. Reactivating an active one changes nothing.
     *
     * @throws CashFlowAccessForbiddenException when the actor is not an owner or manager
     */
    public function reactivate(Uuid $actorId, MembershipRole $actorRole): void
    {
        CashFlowAccess::assertCanManage($actorId, $actorRole);
        $this->deactivatedAt = null;
    }

    /**
     * Checks that the storage may be deleted: only one that no close or movement refers to.
     *
     * @param bool $used whether a close or movement refers to it (CashStorageRepositoryInterface::isUsed())
     *
     * @throws CashFlowAccessForbiddenException when the actor is not an owner or manager
     * @throws StorageInUseException            when it is used
     */
    public function assertDeletable(bool $used, Uuid $actorId, MembershipRole $actorRole): void
    {
        CashFlowAccess::assertCanManage($actorId, $actorRole);
        if ($used) {
            throw StorageInUseException::of($this->id->toRfc4122(), $this->name);
        }
    }

    public function isActive(): bool
    {
        return null === $this->deactivatedAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getMode(): CashStorageMode
    {
        return $this->mode;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDeactivatedAt(): ?\DateTimeImmutable
    {
        return $this->deactivatedAt;
    }

    /** The order of lists: active storages first, then by name (then by id, to be total). */
    public static function compare(self $a, self $b): int
    {
        return [$a->isActive() ? 0 : 1, $a->name, $a->id->toRfc4122()] <=> [$b->isActive() ? 0 : 1, $b->name, $b->id->toRfc4122()];
    }

    /**
     * Trimmed; compared as written ("Safe" and "safe" are two names), as register names are.
     *
     * @throws InvalidStorageNameException
     */
    private static function normalizeName(string $name): string
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw InvalidStorageNameException::of($name, self::NAME_MAX_LENGTH);
        }

        return $name;
    }
}
