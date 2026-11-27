# 0050. Confirmed items with no package size are offered a size under the unit just set too

Status: Accepted

Date: 2026-11-27

## Context

ADR [0049](0049-a-package-size-is-offered-to-the-products-other-items-never-applied.md) §2 asks, after the owner
sets a package size, about the product's items with no size under *other* units than the one just set, and leaves
the items with no size under that unit alone. The reason was that matching fills those: after the owner's
confirmation the aliases (seller and name, GTIN, seller code) are learned and the unconfirmed items are matched
again (ADR [0042](0042-automatic-matching-of-purchased-items.md) §2, §7).

Found on staging: a product (Sprite) with nine items, all the same printed row from one seller on the unit `CO`, all
`confirmed` before any of them had a size. The owner gave one of them a bundle of 12 × 0.85 L and was asked
nothing; the other eight stayed without a size, so they are in the spend but in no quantity or price (ADR
[0041](0041-purchase-analysis-products-and-category-tree.md) §3). The assumption of ADR 0049 §2 does not hold for
them: matching never changes a `confirmed` item (`PurchasedItem::isMatchable()`, ADR 0042 §3), so no alias can
reach them, and under the same unit the question had nothing to ask. Items are often confirmed first (assigned in
bulk, or by a proposal) and sized later, which is exactly when this happens.

## Decision

1. **The groups with no size under the unit just set are offered when a person confirmed some of their items**
   (`confirmedCount > 0`). They are listed with the other groups with no size ("Without a package size"), unchecked,
   with the same editable size and bundle count, and nothing is applied without a yes.
2. **Groups with no size under that unit and no confirmed item are still not asked about**: matching fills them from
   the aliases of what the owner just confirmed, and the owner confirms them (and is asked) when they do.
3. Everything else in ADR 0049 stands: the other groups, the two endpoints, writing only the package, no new
   status. The rule is in `packageSizeQuestion()` (`frontend/admin/src/lib/package-sizes.ts`); no API change.

## Alternatives considered

- **Offer every same-unit group with no size.** Simpler, but it asks about items that matching is about to fill, so
  the dialog would open after most edits for work that is already done.
- **Re-match confirmed items by alias.** Rejected by ADR 0042 §3: what a person confirmed is never changed by
  matching, and a size on a unit of unknown count is not the same for every row (ADR 0043).
- **Apply the size to the same unit's items without asking.** Rejected by ADR 0049: nothing is copied by itself.

## Consequences

- The owner who sizes one confirmed row is offered the product's other confirmed rows on that unit in one dialog,
  instead of opening each. A product whose confirmed items all have a size, or have none unconfirmed, asks nothing
  more than before.
- ADR 0049 §2 is amended by this ADR (the case of the unit just set); the rest of 0049 stands.
