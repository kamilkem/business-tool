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
