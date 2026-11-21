<?php

declare(strict_types=1);

namespace App\Income\Domain\Model;

use App\Income\Domain\Exception\IncomeManagementForbiddenException;
use App\Income\Domain\Exception\InvalidPaymentMethodNameException;
use App\Income\Domain\Exception\PaymentMethodInUseException;
use App\Income\Domain\Exception\PaymentMethodNameTakenException;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\TenantAwareInterface;
use App\Organization\Domain\Model\TenantAwareTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * How the takings were paid (cash, card, meal vouchers, a delivery platform, …): a list each
 * organization keeps for itself (ADR 0030). Revenue entries hold one line per method.
 *
 * - Names are unique within the organization, deactivated methods included: the model checks it
 *   against the method with the same name (PaymentMethodRepositoryInterface::findByName()), and the
 *   unique constraint below stops two concurrent writes.
 * - The organization orders its methods (`position`); the entry form and the summaries follow it.
 *   A new or reactivated method goes to the end.
 * - A method no entry has used can be deleted. A used one is deactivated instead: it takes no new
 *   lines, its lines stay (and an edit of their entry may keep them), and it can be reactivated.
 */
#[ORM\Entity]
#[ORM\Table(name: 'payment_method')]
#[ORM\UniqueConstraint(name: self::UNIQUE_NAME, columns: ['organization_id', 'name'])]
class PaymentMethod implements TenantAwareInterface
{
    use TenantAwareTrait;

    /** Name of the unique constraint on names; the repository adapter recognizes its violations. */
    public const string UNIQUE_NAME = 'payment_method_name_unique';

    public const int NAME_MAX_LENGTH = 64;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: self::NAME_MAX_LENGTH)]
    private string $name;

    /** The place in the organization's list, lowest first; not necessarily consecutive. */
    #[ORM\Column]
    private int $position;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deactivatedAt = null;

    private function __construct(Uuid $id, string $name, int $position, \DateTimeImmutable $createdAt)
    {
        $this->id = $id;
        $this->name = $name;
        $this->position = $position;
        $this->createdAt = $createdAt;
    }

    /**
     * @param ?PaymentMethod $sameName the organization's method with this name, if any
     *                                 (PaymentMethodRepositoryInterface::findByName())
     * @param int            $position after every method of the organization
     *                                 (PaymentMethodRepositoryInterface::nextPosition())
     *
     * @throws IncomeManagementForbiddenException when the actor is not an owner or manager
     * @throws InvalidPaymentMethodNameException  when the name is blank or too long
     * @throws PaymentMethodNameTakenException    when another method has the name
     */
    public static function create(Uuid $id, string $name, ?self $sameName, int $position, Uuid $actorId, MembershipRole $actorRole, \DateTimeImmutable $now): self
    {
        IncomeAccess::assertCanManage($actorId, $actorRole);
        $name = self::normalizeName($name);
        if (null !== $sameName) {
            throw PaymentMethodNameTakenException::of($name);
        }

        return new self($id, $name, $position, $now);
    }

    /**
     * Renaming to the current name changes nothing. A deactivated method can be renamed too; its
     * entries show the new name.
     *
     * @param ?PaymentMethod $sameName the organization's method with the new name, if any
     *
     * @throws IncomeManagementForbiddenException when the actor is not an owner or manager
     * @throws InvalidPaymentMethodNameException  when the name is blank or too long
     * @throws PaymentMethodNameTakenException    when another method has the name
     */
    public function rename(string $name, ?self $sameName, Uuid $actorId, MembershipRole $actorRole): void
    {
        IncomeAccess::assertCanManage($actorId, $actorRole);
        $name = self::normalizeName($name);
        if (null !== $sameName && !$sameName->id->equals($this->id)) {
            throw PaymentMethodNameTakenException::of($name);
        }

        $this->name = $name;
    }

    /**
     * The method takes no new lines from now on. Deactivating it again keeps the first moment.
     *
     * @throws IncomeManagementForbiddenException when the actor is not an owner or manager
     */
    public function deactivate(Uuid $actorId, MembershipRole $actorRole, \DateTimeImmutable $now): void
    {
        IncomeAccess::assertCanManage($actorId, $actorRole);
        $this->deactivatedAt ??= $now;
    }

    /**
     * The method takes lines again, at the end of the list. Reactivating an active method changes
     * nothing.
     *
     * @param int $position after every method of the organization (PaymentMethodRepositoryInterface::nextPosition())
     *
     * @throws IncomeManagementForbiddenException when the actor is not an owner or manager
     */
    public function reactivate(int $position, Uuid $actorId, MembershipRole $actorRole): void
    {
        IncomeAccess::assertCanManage($actorId, $actorRole);
        if ($this->isActive()) {
            return;
        }

        $this->deactivatedAt = null;
        $this->position = $position;
    }

    /**
     * Checks that the method may be deleted: only one that no revenue entry has a line for.
     *
     * @param bool $used whether a revenue entry has a line for it (RevenueEntryRepositoryInterface::usesPaymentMethod())
     *
     * @throws IncomeManagementForbiddenException when the actor is not an owner or manager
     * @throws PaymentMethodInUseException        when an entry has a line for it
     */
    public function assertDeletable(bool $used, Uuid $actorId, MembershipRole $actorRole): void
    {
        IncomeAccess::assertCanManage($actorId, $actorRole);
        if ($used) {
            throw PaymentMethodInUseException::of($this->id->toRfc4122(), $this->name);
        }
    }

    /**
     * Puts the method at a place in the list; PaymentMethodOrder::apply() moves them all.
     *
     * @internal
     */
    public function moveTo(int $position): void
    {
        $this->position = $position;
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

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDeactivatedAt(): ?\DateTimeImmutable
    {
        return $this->deactivatedAt;
    }

    /** The order of lists and lines: active methods first, then by position (then by id, to be total). */
    public static function compare(self $a, self $b): int
    {
        return [$a->isActive() ? 0 : 1, $a->position, $a->id->toRfc4122()] <=> [$b->isActive() ? 0 : 1, $b->position, $b->id->toRfc4122()];
    }

    /**
     * Trimmed; compared as written ("Card" and "card" are two names), as register names are.
     *
     * @throws InvalidPaymentMethodNameException
     */
    private static function normalizeName(string $name): string
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw InvalidPaymentMethodNameException::of($name, self::NAME_MAX_LENGTH);
        }

        return $name;
    }
}
