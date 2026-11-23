<?php

declare(strict_types=1);

namespace App\Expenses\Application;

use App\Expenses\Domain\Model\ExpenseCategory;
use App\Expenses\Domain\Model\PurchaseInvoice;
use App\Expenses\Domain\Model\SellerKey;
use App\Expenses\Domain\Repository\ExpenseCategoryRepositoryInterface;
use App\Expenses\Domain\Repository\PurchaseInvoiceRepositoryInterface;

/**
 * Which category to suggest for an expense from what the organization already did (#55): the
 * active category owners and managers chose most often for the same seller, matched by tax ID, or
 * by name when there is no tax ID (SellerKey). For an imported correction, the category of the
 * invoice it corrects comes first, when that one is stored and has an active category.
 */
final readonly class SellerCategorySuggestion
{
    public function __construct(
        private PurchaseInvoiceRepositoryInterface $invoices,
        private ExpenseCategoryRepositoryInterface $categories,
    ) {
    }

    /** Null when the seller has no history with an active category, or neither a tax ID nor a name is given. */
    public function forSeller(?string $sellerTaxId, ?string $sellerName): ?ExpenseCategory
    {
        $taxIdKey = SellerKey::ofTaxId($sellerTaxId);
        $nameKey = SellerKey::ofName($sellerName ?? '');
        if (null === $taxIdKey && '' === $nameKey) {
            return null;
        }

        $categoryId = $this->invoices->findMostUsedCategoryIdOfSeller($taxIdKey, $nameKey);

        return null === $categoryId ? null : $this->active($this->categories->findById($categoryId));
    }

    /** For an invoice being imported: the corrected invoice's category, else the seller's. */
    public function forImported(PurchaseInvoice $invoice): ?ExpenseCategory
    {
        $correctedId = $invoice->getCorrectedExternalId();
        if (null !== $correctedId) {
            $correctedCategoryId = $this->invoices->findByExternalId($invoice->getSource(), $correctedId)?->getCategoryId();
            $category = null === $correctedCategoryId ? null : $this->active($this->categories->findById($correctedCategoryId));
            if (null !== $category) {
                return $category;
            }
        }

        return $this->forSeller($invoice->getSellerTaxId(), $invoice->getSellerName());
    }

    private function active(?ExpenseCategory $category): ?ExpenseCategory
    {
        return null === $category || $category->isArchived() ? null : $category;
    }
}
