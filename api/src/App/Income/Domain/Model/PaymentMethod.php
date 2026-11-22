<?php

declare(strict_types=1);

namespace App\Income\Domain\Model;

use App\Income\Domain\Exception\CashPaymentMethodTakenException;
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
 * - A method can be marked as cash: the end-of-day close fills its line with the counted cash income
 *   (ADR 0033 §9). At most one active method is: the model checks it against the active cash method
 *   (PaymentMethodRepositoryInterface::findActiveCash()), and the partial unique index below stops two
 *   concurrent writes. A deactivated method keeps its mark, and reactivating it checks the rule again.
 */
#[ORM\Entity]
#[ORM\Table(name: 'payment_method')]
#[ORM\UniqueConstraint(name: self::UNIQUE_NAME, columns: ['organization_id', 'name'])]
#[ORM\UniqueConstraint(name: self::ONE_ACTIVE_CASH, columns: ['organization_id'], options: ['where' => '((cash = true) AND (deactivated_at IS NULL))'])]
class PaymentMethod implements TenantAwareInterface
{
    use TenantAwareTrait;

    /** Name of the unique constraint on names; the repository adapter recognizes its violations. */
    public const string UNIQUE_NAME = 'payment_method_name_unique';

    /** Name of the partial unique index on the active cash method; the repository adapter recognizes its violations. */
    public const string ONE_ACTIVE_CASH = 'payment_method_one_active_cash';

    public const int NAME_MAX_LENGTH = 64;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: self::NAME_MAX_LENGTH)]
    private string $name;

    /** The place in the organization's list, lowest first; not necessarily consecutive. */
    #[ORM\Column]
    private int $position;

    /** Whether the end-of-day close books the counted cash income on this method (ADR 0033 §9). */
    #[ORM\Column(options: ['default' => false])]
    private bool $cash;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deactivatedAt = null;

    private function __construct(Uuid $id, string $name, int $position, bool $cash, \DateTimeImmutable $createdAt)
    {
        $this->id = $id;
        $this->name = $name;
        $this->position = $position;
        $this->cash = $cash;
        $this->createdAt = $createdAt;
    }

    /**
     * @param ?PaymentMethod $sameName the organization's method with this name, if any
     *                                 (PaymentMethodRepositoryInterface::findByName())
     * @param int            $position after every method of the organization
     *                                 (PaymentMethodRepositoryInterface::nextPosition())
     * @param ?PaymentMethod $activeCash the organization's active cash method, if any
     *                                   (PaymentMethodRepositoryInterface::findActiveCash())
     *
     * @throws IncomeManagementForbiddenException when the actor is not an owner or manager
     * @throws InvalidPaymentMethodNameException  when the name is blank or too long
     * @throws PaymentMethodNameTakenException    when another method has the name
     * @throws CashPaymentMethodTakenException    when it is to be cash and another active method is
     */
    public static function create(Uuid $id, string $name, ?self $sameName, int $position, bool $cash, ?self $activeCash, Uuid $actorId, MembershipRole $actorRole, \DateTimeImmutable $now): self
    {
        IncomeAccess::assertCanManage($actorId, $actorRole);
        $name = self::normalizeName($name);
        if (null !== $sameName) {
            throw PaymentMethodNameTakenException::of($name);
        }
        if ($cash && null !== $activeCash) {
            throw CashPaymentMethodTakenException::of($id->toRfc4122(), $name);
        }

        return new self($id, $name, $position, $cash, $now);
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
     * Marks the method as cash, or removes the mark. Setting the current value changes nothing. A
     * deactivated method may be marked too: the rule counts active methods only, and reactivating it
     * checks it.
     *
     * @param ?PaymentMethod $activeCash the organization's active cash method, if any
     *                                   (PaymentMethodRepositoryInterface::findActiveCash())
     *
     * @throws IncomeManagementForbiddenException when the actor is not an owner or manager
     * @throws CashPaymentMethodTakenException    when an active method is marked and another active method is cash
     */
    public function changeCash(bool $cash, ?self $activeCash, Uuid $actorId, MembershipRole $actorRole): void
    {
        IncomeAccess::assertCanManage($actorId, $actorRole);
        if ($cash && !$this->cash && $this->isActive()) {
            $this->assertNoOtherActiveCash($activeCash);
        }

        $this->cash = $cash;
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
     * @param int            $position   after every method of the organization (PaymentMethodRepositoryInterface::nextPosition())
     * @param ?PaymentMethod $activeCash the organization's active cash method, if any
     *                                   (PaymentMethodRepositoryInterface::findActiveCash())
     *
     * @throws IncomeManagementForbiddenException when the actor is not an owner or manager
     * @throws CashPaymentMethodTakenException    when the method is cash and another active method is
     */
    public function reactivate(int $position, ?self $activeCash, Uuid $actorId, MembershipRole $actorRole): void
    {
        IncomeAccess::assertCanManage($actorId, $actorRole);
        if ($this->isActive()) {
            return;
        }
        if ($this->cash) {
            $this->assertNoOtherActiveCash($activeCash);
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

    public function isCash(): bool
    {
        return $this->cash;
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
     * For a method that is, or is about to be, active and cash.
     *
     * @throws CashPaymentMethodTakenException
     */
    private function assertNoOtherActiveCash(?self $activeCash): void
    {
        if (null !== $activeCash && !$activeCash->id->equals($this->id)) {
            throw CashPaymentMethodTakenException::of($this->id->toRfc4122(), $this->name);
        }
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
