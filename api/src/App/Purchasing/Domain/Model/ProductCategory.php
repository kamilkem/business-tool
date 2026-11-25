<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Model;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\TenantAwareInterface;
use App\Organization\Domain\Model\TenantAwareTrait;
use App\Purchasing\Domain\Exception\InvalidProductCategoryNameException;
use App\Purchasing\Domain\Exception\ProductCategoryCycleException;
use App\Purchasing\Domain\Exception\ProductCategoryInUseException;
use App\Purchasing\Domain\Exception\ProductCategoryNameTakenException;
use App\Purchasing\Domain\Exception\PurchasingManagementForbiddenException;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * What the organization buys, grouped in a tree (ADR 0041 §5): a name and an optional parent
 * category (n:1, any depth). The category of a product is the product's own (#241).
 *
 * Names are unique among the categories with the same parent, and among the top level ones,
 * compared case-insensitively: the model checks it against the sibling the caller found
 * (ProductCategoryRepositoryInterface::findSibling()), and the unique constraints below stop two
 * concurrent writes. A category moves under any other category but itself and its own descendants.
 * It is deleted only while it has no subcategory and no product; there is no archive.
 */
#[ORM\Entity]
#[ORM\Table(name: 'product_category')]
// A unique constraint treats NULL parents as all different, so the top level has its own.
#[ORM\UniqueConstraint(name: self::UNIQUE_NAME_UNDER_PARENT, columns: ['organization_id', 'parent_id', 'name_key'])]
#[ORM\UniqueConstraint(name: self::UNIQUE_NAME_AT_TOP_LEVEL, columns: ['organization_id', 'name_key'], options: ['where' => '(parent_id IS NULL)'])]
class ProductCategory implements TenantAwareInterface
{
    use TenantAwareTrait;

    /** Names of the unique constraints on sibling names; the repository adapter recognizes their violations. */
    public const string UNIQUE_NAME_UNDER_PARENT = 'product_category_name_under_parent_unique';
    public const string UNIQUE_NAME_AT_TOP_LEVEL = 'product_category_name_at_top_level_unique';

    public const int NAME_MAX_LENGTH = 64;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: self::NAME_MAX_LENGTH)]
    private string $name;

    /** The name in lower case: what sibling names are compared by. */
    #[ORM\Column(length: self::NAME_MAX_LENGTH)]
    private string $nameKey;

    /** Null for a top level category. No ON DELETE: a category with subcategories cannot be deleted. */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?self $parent;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    private function __construct(Uuid $id, string $name, ?self $parent, \DateTimeImmutable $createdAt)
    {
        $this->id = $id;
        $this->name = $name;
        $this->nameKey = self::nameKeyOf($name);
        $this->parent = $parent;
        $this->createdAt = $createdAt;
    }

    /**
     * @param ?self $parent   the category it goes under; null for the top level
     * @param ?self $sibling  the category with this name (compared case-insensitively) under the same
     *                        parent, if any (ProductCategoryRepositoryInterface::findSibling())
     *
     * @throws PurchasingManagementForbiddenException when the actor is not an owner or manager
     * @throws InvalidProductCategoryNameException    when the name is blank or too long
     * @throws ProductCategoryNameTakenException      when a sibling has the name
     */
    public static function create(Uuid $id, string $name, ?self $parent, ?self $sibling, Uuid $actorId, MembershipRole $actorRole, \DateTimeImmutable $now): self
    {
        PurchasingAccess::assertCanManage($actorId, $actorRole);
        $name = self::normalizeName($name);
        if (null !== $sibling) {
            throw ProductCategoryNameTakenException::of($name, $parent?->id->toRfc4122());
        }

        return new self($id, $name, $parent, $now);
    }

    /**
     * Renames the category and moves it under another one (or to the top level with null), as one
     * change: the name must be free among the siblings it ends up with. Giving the current name and
     * parent changes nothing; changing only the letter case of the name is a rename.
     *
     * @param ?self $parent  the category it goes under; null for the top level
     * @param ?self $sibling the category with the new name under that parent, if any
     *                       (ProductCategoryRepositoryInterface::findSibling()); the category itself is fine
     *
     * @throws PurchasingManagementForbiddenException when the actor is not an owner or manager
     * @throws InvalidProductCategoryNameException    when the name is blank or too long
     * @throws ProductCategoryCycleException          when the parent is the category itself or one of its descendants
     * @throws ProductCategoryNameTakenException      when another category under that parent has the name
     */
    public function change(string $name, ?self $parent, ?self $sibling, Uuid $actorId, MembershipRole $actorRole): void
    {
        PurchasingAccess::assertCanManage($actorId, $actorRole);
        $name = self::normalizeName($name);
        if (null !== $parent && $this->isSameOrAncestorOf($parent)) {
            throw ProductCategoryCycleException::of($this->id->toRfc4122(), $parent->id->toRfc4122());
        }
        if (null !== $sibling && !$sibling->id->equals($this->id)) {
            throw ProductCategoryNameTakenException::of($name, $parent?->id->toRfc4122());
        }

        $this->name = $name;
        $this->nameKey = self::nameKeyOf($name);
        $this->parent = $parent;
    }

    /**
     * Whether the category may be deleted: only while it has no subcategory and no product.
     *
     * @param bool $hasSubcategories ProductCategoryRepositoryInterface::hasSubcategories()
     * @param bool $hasProducts      whether a product has this category (#241)
     *
     * @throws PurchasingManagementForbiddenException when the actor is not an owner or manager
     * @throws ProductCategoryInUseException          when it has a subcategory or a product
     */
    public function assertDeletable(bool $hasSubcategories, bool $hasProducts, Uuid $actorId, MembershipRole $actorRole): void
    {
        PurchasingAccess::assertCanManage($actorId, $actorRole);
        if ($hasSubcategories) {
            throw ProductCategoryInUseException::hasSubcategories($this->id->toRfc4122());
        }
        if ($hasProducts) {
            throw ProductCategoryInUseException::hasProducts($this->id->toRfc4122());
        }
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Whether this category is the other one or an ancestor of it: the other is then in this category's subtree. */
    private function isSameOrAncestorOf(self $other): bool
    {
        for ($category = $other; null !== $category; $category = $category->parent) {
            if ($category->id->equals($this->id)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Trimmed; the case is kept for display.
     *
     * @throws InvalidProductCategoryNameException
     */
    private static function normalizeName(string $name): string
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw InvalidProductCategoryNameException::of($name, self::NAME_MAX_LENGTH);
        }

        return $name;
    }

    /** What two names are compared by; the repository looks siblings up with it. */
    public static function nameKeyOf(string $name): string
    {
        return mb_strtolower(trim($name));
    }
}
