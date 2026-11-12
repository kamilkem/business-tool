<?php

declare(strict_types=1);

namespace App\Tests\Unit\Expenses\Domain;

use App\Expenses\Domain\Exception\ExpenseCategoryNameTakenException;
use App\Expenses\Domain\Exception\ExpensesManagementForbiddenException;
use App\Expenses\Domain\Exception\InvalidExpenseCategoryNameException;
use App\Expenses\Domain\Model\ExpenseCategory;
use App\Expenses\Domain\Model\ExpensesAccess;
use App\Organization\Domain\Model\MembershipRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ExpenseCategory::class)]
#[CoversClass(ExpensesAccess::class)]
final class ExpenseCategoryTest extends TestCase
{
    private const string NOW = '2026-11-13 10:00:00';

    private Uuid $actorId;

    protected function setUp(): void
    {
        $this->actorId = Uuid::v7();
    }

    public function test_it_creates_an_active_category_with_a_trimmed_name(): void
    {
        $id = Uuid::v7();
        $now = new \DateTimeImmutable(self::NOW);

        $category = ExpenseCategory::create($id, '  Rent  ', null, $this->actorId, MembershipRole::Manager, $now);

        self::assertSame($id, $category->getId());
        self::assertSame('Rent', $category->getName());
        self::assertSame($now, $category->getCreatedAt());
        self::assertFalse($category->isArchived());
        self::assertNull($category->getArchivedAt());
    }

    public function test_it_lets_an_employee_create_no_category(): void
    {
        $this->expectException(ExpensesManagementForbiddenException::class);

        ExpenseCategory::create(Uuid::v7(), 'Rent', null, $this->actorId, MembershipRole::Employee, new \DateTimeImmutable(self::NOW));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'too long' => [str_repeat('a', 65)];
    }

    #[DataProvider('invalidNames')]
    public function test_it_rejects_a_blank_or_too_long_name(string $name): void
    {
        $this->expectException(InvalidExpenseCategoryNameException::class);

        ExpenseCategory::create(Uuid::v7(), $name, null, $this->actorId, MembershipRole::Owner, new \DateTimeImmutable(self::NOW));
    }

    public function test_it_accepts_64_characters_after_trimming(): void
    {
        $category = $this->category(' '.str_repeat('ż', 64).' ');

        self::assertSame(str_repeat('ż', 64), $category->getName());
    }

    public function test_it_rejects_a_name_another_category_has(): void
    {
        $this->expectException(ExpenseCategoryNameTakenException::class);

        ExpenseCategory::create(Uuid::v7(), 'Rent', $this->category('Rent'), $this->actorId, MembershipRole::Owner, new \DateTimeImmutable(self::NOW));
    }

    public function test_it_renames_a_category_and_keeps_its_own_name(): void
    {
        $category = $this->category('Rent');

        $category->rename(' Rent ', $category, $this->actorId, MembershipRole::Manager);
        self::assertSame('Rent', $category->getName());

        $category->rename('Lease', null, $this->actorId, MembershipRole::Manager);
        self::assertSame('Lease', $category->getName());
    }

    public function test_it_rejects_renaming_to_another_categorys_name(): void
    {
        $category = $this->category('Rent');

        $this->expectException(ExpenseCategoryNameTakenException::class);

        $category->rename('Food', $this->category('Food'), $this->actorId, MembershipRole::Owner);
    }

    public function test_it_lets_an_employee_rename_archive_or_restore_nothing(): void
    {
        $category = $this->category('Rent');
        $refused = 0;
        foreach ([
            fn () => $category->rename('Lease', null, $this->actorId, MembershipRole::Employee),
            fn () => $category->archive($this->actorId, MembershipRole::Employee, new \DateTimeImmutable(self::NOW)),
            fn () => $category->restore($this->actorId, MembershipRole::Employee),
        ] as $change) {
            try {
                $change();
            } catch (ExpensesManagementForbiddenException) {
                ++$refused;
            }
        }

        self::assertSame(3, $refused);
        self::assertSame(['Rent', false], [$category->getName(), $category->isArchived()]);
    }

    public function test_it_archives_once_and_restores(): void
    {
        $category = $this->category('Rent');
        $first = new \DateTimeImmutable(self::NOW);

        $category->archive($this->actorId, MembershipRole::Owner, $first);
        $category->archive($this->actorId, MembershipRole::Owner, $first->modify('+1 day'));

        self::assertTrue($category->isArchived());
        self::assertSame($first, $category->getArchivedAt());

        $category->restore($this->actorId, MembershipRole::Manager);
        $category->restore($this->actorId, MembershipRole::Manager);

        self::assertFalse($category->isArchived());
        self::assertNull($category->getArchivedAt());
    }

    private function category(string $name): ExpenseCategory
    {
        return ExpenseCategory::create(Uuid::v7(), $name, null, $this->actorId, MembershipRole::Owner, new \DateTimeImmutable(self::NOW));
    }
}
