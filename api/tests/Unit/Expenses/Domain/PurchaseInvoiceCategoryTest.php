<?php

declare(strict_types=1);

namespace App\Tests\Unit\Expenses\Domain;

use App\Expenses\Domain\Exception\ExpenseCategoryArchivedException;
use App\Expenses\Domain\Exception\ExpensesManagementForbiddenException;
use App\Expenses\Domain\Model\ExpenseCategory;
use App\Expenses\Domain\Model\PurchaseInvoice;
use App\Organization\Domain\Model\MembershipRole;
use App\Tests\Fixtures\PurchaseInvoiceMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(PurchaseInvoice::class)]
final class PurchaseInvoiceCategoryTest extends TestCase
{
    private const string NOW = '2026-11-13 10:00:00';

    private Uuid $actorId;

    protected function setUp(): void
    {
        $this->actorId = Uuid::v7();
    }

    /** @return iterable<string, array{MembershipRole}> */
    public static function managingRoles(): iterable
    {
        yield 'owner' => [MembershipRole::Owner];
        yield 'manager' => [MembershipRole::Manager];
    }

    #[DataProvider('managingRoles')]
    public function test_it_assigns_changes_and_clears_the_category(MembershipRole $role): void
    {
        $invoice = PurchaseInvoiceMother::imported();
        $food = $this->category('Food');
        $beverages = $this->category('Beverages');

        $invoice->categorize($food, $this->actorId, $role);
        self::assertTrue($food->getId()->equals($invoice->getCategoryId()));

        $invoice->categorize($beverages, $this->actorId, $role);
        self::assertTrue($beverages->getId()->equals($invoice->getCategoryId()));

        $invoice->categorize(null, $this->actorId, $role);
        self::assertNull($invoice->getCategoryId());

        $invoice->categorize(null, $this->actorId, $role);
        self::assertNull($invoice->getCategoryId());
    }

    public function test_it_refuses_to_newly_assign_an_archived_category(): void
    {
        $invoice = PurchaseInvoiceMother::imported();
        $food = $this->category('Food');
        $invoice->categorize($food, $this->actorId, MembershipRole::Owner);
        $archived = $this->category('Old rent', archived: true);

        try {
            $invoice->categorize($archived, $this->actorId, MembershipRole::Owner);
            self::fail('Archived category expected to be refused.');
        } catch (ExpenseCategoryArchivedException $e) {
            self::assertStringContainsString('"Old rent"', $e->getMessage());
        }

        self::assertTrue($food->getId()->equals($invoice->getCategoryId()));
    }

    public function test_it_keeps_a_category_archived_after_it_was_assigned(): void
    {
        $invoice = PurchaseInvoiceMother::imported();
        $rent = $this->category('Rent');
        $invoice->categorize($rent, $this->actorId, MembershipRole::Owner);
        $rent->archive($this->actorId, MembershipRole::Owner, new \DateTimeImmutable(self::NOW));

        $invoice->categorize($rent, $this->actorId, MembershipRole::Manager);

        self::assertTrue($rent->getId()->equals($invoice->getCategoryId()));
    }

    public function test_it_refuses_an_employee(): void
    {
        $invoice = PurchaseInvoiceMother::imported();
        $food = $this->category('Food');

        foreach ([$food, null] as $category) {
            try {
                $invoice->categorize($category, $this->actorId, MembershipRole::Employee);
                self::fail('Employee expected to be refused.');
            } catch (ExpensesManagementForbiddenException) {
            }
        }

        self::assertNull($invoice->getCategoryId());
    }

    public function test_it_marks_a_suggested_category_until_someone_confirms_it(): void
    {
        $invoice = PurchaseInvoiceMother::imported();
        $food = $this->category('Food');

        $invoice->suggestCategory($food);
        self::assertTrue($food->getId()->equals($invoice->getCategoryId()));
        self::assertTrue($invoice->isCategorySuggested());

        $invoice->categorize($food, $this->actorId, MembershipRole::Manager);
        self::assertTrue($food->getId()->equals($invoice->getCategoryId()));
        self::assertFalse($invoice->isCategorySuggested());
    }

    public function test_it_clears_the_suggested_mark_when_someone_picks_another_category_or_none(): void
    {
        $food = $this->category('Food');

        $changed = PurchaseInvoiceMother::imported();
        $changed->suggestCategory($food);
        $beverages = $this->category('Beverages');
        $changed->categorize($beverages, $this->actorId, MembershipRole::Owner);
        self::assertTrue($beverages->getId()->equals($changed->getCategoryId()));
        self::assertFalse($changed->isCategorySuggested());

        $cleared = PurchaseInvoiceMother::imported();
        $cleared->suggestCategory($food);
        $cleared->categorize(null, $this->actorId, MembershipRole::Owner);
        self::assertNull($cleared->getCategoryId());
        self::assertFalse($cleared->isCategorySuggested());
    }

    public function test_it_never_suggests_over_a_category_already_there(): void
    {
        $invoice = PurchaseInvoiceMother::imported();
        $food = $this->category('Food');
        $invoice->categorize($food, $this->actorId, MembershipRole::Owner);

        $invoice->suggestCategory($this->category('Beverages'));

        self::assertTrue($food->getId()->equals($invoice->getCategoryId()));
        self::assertFalse($invoice->isCategorySuggested());
    }

    public function test_it_refuses_to_suggest_an_archived_category(): void
    {
        $invoice = PurchaseInvoiceMother::imported();

        $this->expectException(ExpenseCategoryArchivedException::class);
        $invoice->suggestCategory($this->category('Old rent', archived: true));
    }

    private function category(string $name, bool $archived = false): ExpenseCategory
    {
        $now = new \DateTimeImmutable(self::NOW);
        $category = ExpenseCategory::create(Uuid::v7(), $name, null, $this->actorId, MembershipRole::Owner, $now);
        if ($archived) {
            $category->archive($this->actorId, MembershipRole::Owner, $now);
        }

        return $category;
    }
}
