<?php

declare(strict_types=1);

namespace App\Income\Domain\Model;

use App\Income\Domain\Exception\IncomeManagementForbiddenException;
use App\Income\Domain\Exception\InvalidRegisterNameException;
use App\Income\Domain\Exception\RegisterInUseException;
use App\Income\Domain\Exception\RegisterNameTakenException;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\TenantAwareInterface;
use App\Organization\Domain\Model\TenantAwareTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A till with its own fiscal printer, whose daily report becomes one RevenueEntry per business date
 * (ADR 0022 §2). Names are unique within the organization: the model checks it against the register
 * with the same name (RegisterRepositoryInterface::findByName()), and the unique constraint below
 * stops two concurrent writes.
 *
 * A register no entry refers to can be deleted; one with entries is deactivated instead, because
 * the entries refer to it (ADR 0032). A deactivated register takes no new entries; its entries stay
 * visible and editable.
 */
#[ORM\Entity]
#[ORM\Table(name: 'register')]
#[ORM\UniqueConstraint(name: self::UNIQUE_NAME, columns: ['organization_id', 'name'])]
class Register implements TenantAwareInterface
{
    use TenantAwareTrait;

    /** Name of the unique constraint on names; the repository adapter recognizes its violations. */
    public const string UNIQUE_NAME = 'register_name_unique';

    public const int NAME_MAX_LENGTH = 64;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: self::NAME_MAX_LENGTH)]
    private string $name;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deactivatedAt = null;

    private function __construct(Uuid $id, string $name, \DateTimeImmutable $createdAt)
    {
        $this->id = $id;
        $this->name = $name;
        $this->createdAt = $createdAt;
    }

    /**
     * @param ?Register $sameName the organization's register with this name, if any
     *                            (RegisterRepositoryInterface::findByName())
     *
     * @throws IncomeManagementForbiddenException when the actor is not an owner or manager
     * @throws InvalidRegisterNameException       when the name is blank or too long
     * @throws RegisterNameTakenException         when another register has the name
     */
    public static function create(Uuid $id, string $name, ?self $sameName, Uuid $actorId, MembershipRole $actorRole, \DateTimeImmutable $now): self
    {
        IncomeAccess::assertCanManage($actorId, $actorRole);
        $name = self::normalizeName($name);
        if (null !== $sameName) {
            throw RegisterNameTakenException::of($name);
        }

        return new self($id, $name, $now);
    }

    /**
     * Renaming to the current name changes nothing. A deactivated register can be renamed too.
     *
     * @param ?Register $sameName the organization's register with the new name, if any
     *
     * @throws IncomeManagementForbiddenException when the actor is not an owner or manager
     * @throws InvalidRegisterNameException       when the name is blank or too long
     * @throws RegisterNameTakenException         when another register has the name
     */
    public function rename(string $name, ?self $sameName, Uuid $actorId, MembershipRole $actorRole): void
    {
        IncomeAccess::assertCanManage($actorId, $actorRole);
        $name = self::normalizeName($name);
        if (null !== $sameName && !$sameName->id->equals($this->id)) {
            throw RegisterNameTakenException::of($name);
        }

        $this->name = $name;
    }

    /**
     * The register takes no new entries from now on. Deactivating it again keeps the first moment.
     *
     * @throws IncomeManagementForbiddenException when the actor is not an owner or manager
     */
    public function deactivate(Uuid $actorId, MembershipRole $actorRole, \DateTimeImmutable $now): void
    {
        IncomeAccess::assertCanManage($actorId, $actorRole);
        $this->deactivatedAt ??= $now;
    }

    /**
     * Only a register without revenue entries can be deleted (ADR 0032); the repository removes it.
     *
     * @param bool $used whether a revenue entry refers to it (RevenueEntryRepositoryInterface::usesRegister())
     *
     * @throws IncomeManagementForbiddenException when the actor is not an owner or manager
     * @throws RegisterInUseException             when an entry refers to it
     */
    public function assertDeletable(bool $used, Uuid $actorId, MembershipRole $actorRole): void
    {
        IncomeAccess::assertCanManage($actorId, $actorRole);
        if ($used) {
            throw RegisterInUseException::of($this->id->toRfc4122(), $this->name);
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDeactivatedAt(): ?\DateTimeImmutable
    {
        return $this->deactivatedAt;
    }

    /**
     * Trimmed; compared as written ("Bar" and "bar" are two names).
     *
     * @throws InvalidRegisterNameException
     */
    private static function normalizeName(string $name): string
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw InvalidRegisterNameException::of($name, self::NAME_MAX_LENGTH);
        }

        return $name;
    }
}
