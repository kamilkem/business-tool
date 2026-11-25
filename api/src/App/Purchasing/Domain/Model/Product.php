<?php

declare(strict_types=1);

namespace App\Purchasing\Domain\Model;

use App\Expenses\Domain\Model\DecimalString;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\TenantAwareInterface;
use App\Organization\Domain\Model\TenantAwareTrait;
use App\Purchasing\Domain\Exception\InvalidProductNameException;
use App\Purchasing\Domain\Exception\InvalidProductReferenceQuantityException;
use App\Purchasing\Domain\Exception\ProductInUseException;
use App\Purchasing\Domain\Exception\ProductMergeIntoItselfException;
use App\Purchasing\Domain\Exception\ProductNameTakenException;
use App\Purchasing\Domain\Exception\PurchasingManagementForbiddenException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * What the organization buys, as the owner sees it (ADR 0041 §4): the invoice items printed in many
 * ways are linked to one product (#242). It has
 * - a name, unique in the organization (case-insensitively);
 * - a category (optional; ProductCategory), which every item linked to the product follows;
 * - a measure and a reference quantity: the quantity prices are compared per, in the measure's base
 *   unit (L, kg, pc), 1 by default (0.5 for a beer per 0.5 L). It is null exactly when the measure
 *   is `none`;
 * - an excluded flag: an excluded product and every item linked to it is left out of every total.
 *
 * The model checks the name against the product the caller found
 * (ProductRepositoryInterface::findByName()), and the unique constraint below stops two concurrent
 * writes. A product is deleted only while no purchased item refers to it, and merged into another
 * one, which takes its items (MergeProductHandler).
 */
#[ORM\Entity]
#[ORM\Table(name: 'product')]
#[ORM\UniqueConstraint(name: self::UNIQUE_NAME, columns: ['organization_id', 'name_key'])]
class Product implements TenantAwareInterface
{
    use TenantAwareTrait;

    /** Name of the unique constraint on names; the repository adapter recognizes its violations. */
    public const string UNIQUE_NAME = 'product_name_unique';

    public const int NAME_MAX_LENGTH = 128;

    /** Digits of the reference quantity: NUMERIC(22, 6), like the package size of an item. */
    public const int REFERENCE_QUANTITY_SCALE = 6;
    public const int REFERENCE_QUANTITY_INTEGER_DIGITS = 16;

    public const string DEFAULT_REFERENCE_QUANTITY = '1';

    /** The shape of a reference quantity as a request writes it, for the Ui's validation. */
    public const string REFERENCE_QUANTITY_PATTERN = '/^\d{1,16}(\.\d{1,6})?$/D';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: self::NAME_MAX_LENGTH)]
    private string $name;

    /** The name in lower case: what names are compared by. */
    #[ORM\Column(length: self::NAME_MAX_LENGTH)]
    private string $nameKey;

    /** No ON DELETE: a category with products cannot be deleted (ProductCategory::assertDeletable()). */
    #[ORM\ManyToOne(targetEntity: ProductCategory::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?ProductCategory $category;

    #[ORM\Column(length: 16, enumType: ProductMeasure::class)]
    private ProductMeasure $measure;

    #[ORM\Column(type: Types::DECIMAL, precision: 22, scale: self::REFERENCE_QUANTITY_SCALE, nullable: true)]
    private ?string $referenceQuantity;

    #[ORM\Column]
    private bool $excluded;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    private function __construct(Uuid $id, string $name, ?ProductCategory $category, ProductMeasure $measure, ?string $referenceQuantity, bool $excluded, \DateTimeImmutable $createdAt)
    {
        $this->id = $id;
        $this->name = $name;
        $this->nameKey = self::nameKeyOf($name);
        $this->category = $category;
        $this->measure = $measure;
        $this->referenceQuantity = $referenceQuantity;
        $this->excluded = $excluded;
        $this->createdAt = $createdAt;
    }

    /**
     * @param ?ProductCategory $category          the category it is in, if any
     * @param ?string          $referenceQuantity a positive decimal in the measure's base unit; null is 1 for a
     *                                            measure with a unit, and the only value for `none`
     * @param ?self            $sibling           the product with this name (compared case-insensitively), if any
     *                                            (ProductRepositoryInterface::findByName())
     *
     * @throws PurchasingManagementForbiddenException       when the actor is not an owner or manager
     * @throws InvalidProductNameException                  when the name is blank or too long
     * @throws InvalidProductReferenceQuantityException     when the reference quantity is not positive, or is given for `none`
     * @throws ProductNameTakenException                    when another product has the name
     */
    public static function create(Uuid $id, string $name, ?ProductCategory $category, ProductMeasure $measure, ?string $referenceQuantity, bool $excluded, ?self $sibling, Uuid $actorId, MembershipRole $actorRole, \DateTimeImmutable $now): self
    {
        PurchasingAccess::assertCanManage($actorId, $actorRole);
        $name = self::normalizeName($name);
        if (null !== $sibling) {
            throw ProductNameTakenException::of($name);
        }

        return new self($id, $name, $category, $measure, self::referenceQuantityFor($measure, $referenceQuantity), $excluded, $now);
    }

    /**
     * Changes everything the owner edits as one change; the caller sends the current value of what it
     * does not change. Giving the current values changes nothing, and changing only the letter case of
     * the name is a rename. Items linked to the product keep their package sizes, which stay in the
     * base unit of the measure they were entered for.
     *
     * @param ?ProductCategory $category the category it moves to; null for none
     * @param ?self            $sibling  the product with the new name, if any (ProductRepositoryInterface::findByName());
     *                                   the product itself is fine
     *
     * @throws PurchasingManagementForbiddenException       when the actor is not an owner or manager
     * @throws InvalidProductNameException                  when the name is blank or too long
     * @throws InvalidProductReferenceQuantityException     when the reference quantity is not positive, or is given for `none`
     * @throws ProductNameTakenException                    when another product has the name
     */
    public function change(string $name, ?ProductCategory $category, ProductMeasure $measure, ?string $referenceQuantity, bool $excluded, ?self $sibling, Uuid $actorId, MembershipRole $actorRole): void
    {
        PurchasingAccess::assertCanManage($actorId, $actorRole);
        $name = self::normalizeName($name);
        $referenceQuantity = self::referenceQuantityFor($measure, $referenceQuantity);
        if (null !== $sibling && !$sibling->id->equals($this->id)) {
            throw ProductNameTakenException::of($name);
        }

        $this->name = $name;
        $this->nameKey = self::nameKeyOf($name);
        $this->category = $category;
        $this->measure = $measure;
        $this->referenceQuantity = $referenceQuantity;
        $this->excluded = $excluded;
    }

    /**
     * Whether the product may be deleted: only while no purchased item refers to it.
     *
     * @param bool $hasItems PurchasedItemRepositoryInterface::hasItemsOfProduct()
     *
     * @throws PurchasingManagementForbiddenException when the actor is not an owner or manager
     * @throws ProductInUseException                  when an item refers to it
     */
    public function assertDeletable(bool $hasItems, Uuid $actorId, MembershipRole $actorRole): void
    {
        PurchasingAccess::assertCanManage($actorId, $actorRole);
        if ($hasItems) {
            throw ProductInUseException::of($this->id->toRfc4122());
        }
    }

    /**
     * Whether the product may be merged into the other one: its items then move there and it is
     * deleted. The target keeps its own settings. Nothing about the measures is checked: the owner
     * decides what is the same product.
     *
     * @throws PurchasingManagementForbiddenException when the actor is not an owner or manager
     * @throws ProductMergeIntoItselfException        when the target is this product
     */
    public function assertCanMergeInto(self $target, Uuid $actorId, MembershipRole $actorRole): void
    {
        PurchasingAccess::assertCanManage($actorId, $actorRole);
        if ($this->id->equals($target->id)) {
            throw ProductMergeIntoItselfException::of($this->id->toRfc4122());
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

    public function getCategory(): ?ProductCategory
    {
        return $this->category;
    }

    public function getMeasure(): ProductMeasure
    {
        return $this->measure;
    }

    /** A decimal string with trailing zeros trimmed (`0.5`, `1`); null exactly when the measure is `none`. */
    public function getReferenceQuantity(): ?string
    {
        return DecimalString::trimmed($this->referenceQuantity);
    }

    public function isExcluded(): bool
    {
        return $this->excluded;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * The reference quantity to store: null for `none`, which allows no value; for a measure with a
     * unit the given positive decimal in normal form, or 1 when none is given.
     *
     * @throws InvalidProductReferenceQuantityException
     */
    private static function referenceQuantityFor(ProductMeasure $measure, ?string $value): ?string
    {
        if (!$measure->hasUnit()) {
            return null === $value ? null : throw InvalidProductReferenceQuantityException::notAllowedForMeasure($value, $measure->value);
        }
        if (null === $value) {
            return self::DEFAULT_REFERENCE_QUANTITY;
        }

        $normalized = DecimalString::normalized($value, self::REFERENCE_QUANTITY_INTEGER_DIGITS, self::REFERENCE_QUANTITY_SCALE);
        if (null === $normalized || '0' === $normalized || str_starts_with($normalized, '-')) {
            throw InvalidProductReferenceQuantityException::notPositive($value);
        }

        return $normalized;
    }

    /**
     * Trimmed; the case is kept for display.
     *
     * @throws InvalidProductNameException
     */
    private static function normalizeName(string $name): string
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw InvalidProductNameException::of($name, self::NAME_MAX_LENGTH);
        }

        return $name;
    }

    /** What two names are compared by; the repository looks products up with it. */
    public static function nameKeyOf(string $name): string
    {
        return mb_strtolower(trim($name));
    }
}
