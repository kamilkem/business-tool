# 0049. A package size set on one item is offered to the product's other items, never applied to them silently

Status: Accepted

Date: 2026-11-27

## Context

The owner reported (#271) that creating a product from a proposal fills the package size of the items that share a
unit (`szt.`) and leaves the items of the same product under another unit (`CO`) without one, and that editing a
size on one item leaves the others as they were. An item with no package size is not counted in the price analysis
(ADR [0041](0041-purchase-analysis-products-and-category-tree.md) §3), so the owner opened each remaining item by hand.

Why the first happens, found before changing anything: accepting a proposal gives each item its own size, or what its
text strongly states in the product's measure (ADR [0044](0044-the-model-proposes-products-the-owner-creates-them.md)
§5, `ItemReading::packageSizeFor()`). A bottle's `0,7l` on a `szt.` is stated; the same text on a `CO` is a weak
reading, because a case holds an unknown number of pieces (ADR [0043](0043-units-of-unknown-count-and-the-sellers-multiplier.md)
§2), so the case gets none. The size is therefore never copied from one item to another. Matching reads the aliases'
remembered size the same way (ADR 0042 §3, ADR 0043 §4). Both are right: a size is per unit of the quantity, and a
`CO` of 0.7 L bottles is not 0.7 L.

## Decision

1. **Nothing is copied by itself; the owner is asked.** After a person sets a package size on an item (one item
   edited, items assigned in bulk, a product made from a proposal), the admin asks whether to set it on the product's
   other items, with the count and the unit of each group, and the owner chooses which groups. Without a yes nothing
   changes. The size to set is editable in the question, because the size of a case is not the size of a bottle.
2. **What is asked about.** Items of the product are grouped by unit and package: the size and, for a bundle (ADR [0048](0048-bundles-next-to-the-package-size.md)), the bundle count, so `6 × 0.7` and `0.7` are two groups. The question lists the groups
   with no size under *other* units than the one just set; and, when an existing size was *changed*, the groups with
   another size, those on the same unit apart from those on other units, with how many of them a person confirmed.
   Everything is unchecked at first. Which groups are asked about is decided in the admin (`lib/package-sizes.ts`), from
   what the API lists.
3. **Two endpoints, no new status.** `GET /products/{id}/package-sizes` lists the groups (`GetProductPackageSizesQuery`;
   units compared by `ItemReader::unitKeyOf()`, so `szt.` and `SZT` are one). `POST /products/{id}/package-sizes`
   (`ApplyPackageSizeCommand`, owners and managers, `204`) sets one package (size and optional bundle count) on the items of the chosen
   groups, each named by its unit key, size and bundle count as it has them now, all or nothing in one flush. It is the second command that changes many
   items for one action of the person (after ADR 0041's bulk assign), so "one command, one aggregate" is not applied to
   it. An item that is in no chosen group is not read; a group that holds nothing any more changes nothing.
4. **Only the size is written.** `PurchasedItem::changePackageSize()` changes the package (size and bundle count) alone: the product,
   the status, what linked the item and its proposal stay, so a `confirmed` link is never touched and a suggestion is
   not confirmed by it. The items that were `confirmed` are published as `PurchasedItemsConfirmed`, so that their aliases
   and the seller's multiplier learn the size the owner gave (ADR 0042 §3, ADR 0043 §3) and the other items are
   matched again; the others are not published, because the owner did not confirm them.
5. **No new error code.** An unknown product is `purchasing.product_not_found`, a size that is not a positive decimal
   is `purchasing.invalid_package_size`.

## Alternatives considered

- **Copy the size to the same product's other units automatically.** Rejected by the issue and by ADR 0043: a size is
  per unit of the quantity, so the copy would be wrong for every case, carton and pack.
- **Fill the other units from a learned ratio.** The seller's multiplier (ADR 0043) already does this for the weak
  reading of a name, after one confirmation; a ratio between two units of the same product is a second guess.
- **Chosen items by id.** Exact, but a product with hundreds of items on one unit would exceed the bulk limit of 500 and
  make the owner's "yes" depend on paging. A group (unit and size) is what the owner sees, so it is what is sent.
- **Ask before saving.** The question needs the product's groups as they will be, and the owner's first change must
  not wait for it; the change is saved and the question follows.

## Consequences

- A dialog follows the three dialogs that set a size. It opens only when there is something to ask, so a product
  whose items all have the same size is not interrupted.
- Items that matching linked (`assigned`, `suggested`) get the size without being confirmed; they stay in the review
  queue as they were, with a size the owner gave.
- The follow-up after a product is made from a proposal finds the product by its name, because accepting answers with
  no body; the name is unique.
- A seller who prints one unit for different counts (a `CO` of 6 and a `CO` of 12) gets one group, which the owner
  chooses as one (the consequence ADR 0043 already accepts, unchanged).
