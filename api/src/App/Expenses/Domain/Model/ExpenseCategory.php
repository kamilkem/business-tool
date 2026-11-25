<?php

declare(strict_types=1);

namespace App\Expenses\Domain\Model;

use App\Expenses\Domain\Exception\ExpenseCategoryNameTakenException;
use App\Expenses\Domain\Exception\ExpensesManagementForbiddenException;
use App\Expenses\Domain\Exception\InvalidExpenseCategoryNameException;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\TenantAwareInterface;
use App\Organization\Domain\Model\TenantAwareTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * What an expense was for (rent, food, beverages, ...), chosen by each organization. A new
 * organization starts with DefaultExpenseCategories. Names are unique within the organization: the
 * model checks it against the category with the same name
 * (ExpenseCategoryRepositoryInterface::findByName()), and the unique constraint below stops two
 * concurrent writes.
 *
 * Categories are archived, never deleted (#53), and an archived one can be restored. Purchase
 * invoices no longer have a category (#228); whether these become product categories is open (#232).
 */
#[ORM\Entity]
#[ORM\Table(name: 'expense_category')]
#[ORM\UniqueConstraint(name: self::UNIQUE_NAME, columns: ['organization_id', 'name'])]
class ExpenseCategory implements TenantAwareInterface
{
    use TenantAwareTrait;

    /** Name of the unique constraint on names; the repository adapter recognizes its violations. */
    public const string UNIQUE_NAME = 'expense_category_name_unique';

    public const int NAME_MAX_LENGTH = 64;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: self::NAME_MAX_LENGTH)]
    private string $name;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    private function __construct(Uuid $id, string $name, \DateTimeImmutable $createdAt)
    {
        $this->id = $id;
        $this->name = $name;
        $this->createdAt = $createdAt;
    }

    /**
     * @param ?ExpenseCategory $sameName the organization's category with this name, if any
     *                                   (ExpenseCategoryRepositoryInterface::findByName())
     *
     * @throws ExpensesManagementForbiddenException when the actor is not an owner or manager
     * @throws InvalidExpenseCategoryNameException  when the name is blank or too long
     * @throws ExpenseCategoryNameTakenException    when another category has the name
     */
    public static function create(Uuid $id, string $name, ?self $sameName, Uuid $actorId, MembershipRole $actorRole, \DateTimeImmutable $now): self
    {
        ExpensesAccess::assertCanManage($actorId, $actorRole);
        $name = self::normalizeName($name);
        if (null !== $sameName) {
            throw ExpenseCategoryNameTakenException::of($name);
        }

        return new self($id, $name, $now);
    }

    /**
     * Renaming to the current name changes nothing. An archived category can be renamed too.
     *
     * @param ?ExpenseCategory $sameName the organization's category with the new name, if any
     *
     * @throws ExpensesManagementForbiddenException when the actor is not an owner or manager
     * @throws InvalidExpenseCategoryNameException  when the name is blank or too long
     * @throws ExpenseCategoryNameTakenException    when another category has the name
     */
    public function rename(string $name, ?self $sameName, Uuid $actorId, MembershipRole $actorRole): void
    {
        ExpensesAccess::assertCanManage($actorId, $actorRole);
        $name = self::normalizeName($name);
        if (null !== $sameName && !$sameName->id->equals($this->id)) {
            throw ExpenseCategoryNameTakenException::of($name);
        }

        $this->name = $name;
    }

    /**
     * The category is no longer offered. Archiving it again keeps the first moment.
     *
     * @throws ExpensesManagementForbiddenException when the actor is not an owner or manager
     */
    public function archive(Uuid $actorId, MembershipRole $actorRole, \DateTimeImmutable $now): void
    {
        ExpensesAccess::assertCanManage($actorId, $actorRole);
        $this->archivedAt ??= $now;
    }

    /**
     * Offers the category again. Restoring an active category changes nothing.
     *
     * @throws ExpensesManagementForbiddenException when the actor is not an owner or manager
     */
    public function restore(Uuid $actorId, MembershipRole $actorRole): void
    {
        ExpensesAccess::assertCanManage($actorId, $actorRole);
        $this->archivedAt = null;
    }

    public function isArchived(): bool
    {
        return null !== $this->archivedAt;
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

    public function getArchivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    /**
     * Trimmed; compared as written ("Food" and "food" are two names).
     *
     * @throws InvalidExpenseCategoryNameException
     */
    private static function normalizeName(string $name): string
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw InvalidExpenseCategoryNameException::of($name, self::NAME_MAX_LENGTH);
        }

        return $name;
    }
}
