# 0044. The model proposes products for items the catalog lacks; only a person creates one

Status: Accepted (the `proposedName` rule "without ABV" amended by [0047](0047-a-different-abv-is-a-different-product.md))

Date: 2026-11-26

## Context

ADR [0042](0042-automatic-matching-of-purchased-items.md) matches an item only to a product the owner
already created (ADR [0041](0041-purchase-analysis-products-and-category-tree.md) §4: the catalog is the
owner's), and listed proposing new products as known and out of scope (§9). With an empty catalog the
model matches nothing, so the first products are all made by hand from the review queue, one group of
items at a time. The owner asked for the model to propose, and for each proposal to be confirmed (#260).

The hard line stays: **the model never creates a product.** A product is the owner's statement of what
the business buys; a wrong one would also teach wrong aliases (ADR 0042 §3).

ADR 0042 is Accepted and is not edited beyond its Status line (ADR 0000); the issue asked for "a
section in 0042", which this ADR is instead, amending 0042 §1, §7 and §9.

## Decision

1. **The same request asks for a proposal.** For an item that is none of the catalog's products, the
   interpreter (ADR 0042 §2) also returns a proposed product: a name, a measure (`volume`, `mass`,
   `count`, `none`) and a category chosen by id from the organization's category tree, never a new
   category. The structured-output schema gets three more strings (`proposedName`, `proposedMeasure`,
   `proposedCategoryId`; empty means none, as for the existing fields). The system block lists the
   categories as `id | path of names`. What is sent grows by those category ids and names, and nothing
   else (ADR 0042 §8: still no prices, quantities, dates, invoice numbers, seller names or tax IDs).
   `ItemInterpreterInterface::interpret()` takes the categories, and the catalog may be empty: with no
   products the model is asked all the same, which is the point.
2. **The proposal is stored on the item and checked.** `purchased_item` gets nullable `proposed_name`,
   `proposed_name_key`, `proposed_measure` and `proposed_category_id` (a foreign key, `ON DELETE SET NULL`,
   so deleting a category blocks nothing). The adapter keeps a proposal only with a non-blank name of at
   most 128 characters and a valid measure, and drops a category id that was not sent. `PurchasedItem`
   alone writes it (`propose()`), and only for an unlinked item that matching may change. Linking the
   item (a person's `link()` or matching's `match()`) clears it; a later run that finds nothing replaces it.
3. **A proposal that is a product's name is a match.** If the proposed name equals the name of an existing
   product (case-insensitively), matching links the item to that product as `suggested` (never `assigned`:
   the model contradicted itself) and stores no proposal. The review list also shows, for a proposal
   whose name a product has since got, that product (`existingProductId`).
4. **Proposals are grouped by name.** Items are grouped by `ItemReader::fullKey()` of the proposed
   name (`proposed_name_key`), so "Jack Daniel's", "JACK DANIELS" and "jack daniel's" are one proposal.
   A group shows the spelling, measure and category most of its items propose (ties: alphabetical, a missing
   category last), the item count and up to three example item names. The grouping is a pure Domain
   class (`ProposalGrouper`) over scalars read from the repository, not over entities.
5. **A person decides each proposal** (`GET /product-proposals` and three `POST` operations on
   `/product-proposals/{id}`, owners and managers only; the id is the key with hyphens for spaces):
   - `accept` creates the product from the name, measure, category and reference quantity in the body (the
     proposal's, as the owner edited them) with `Product::create()` and the same role check and name
     rule as `POST /products`, links every item of the proposal to it as `confirmed`, and publishes
     `ProductCreated` and `PurchasedItemsConfirmed`. The items are changed first and the product is added
     last: `add()` flushes the unit of work, so product and links are one transaction, and a taken name or
     unknown category changes nothing (409, 422). Each item's package size is its own, or what its text
     strongly states for the product's measure (`ItemReading::packageSizeFor()`), so the learned aliases
     carry it;
   - `assign` links the items to a product that exists, as `confirmed` (404 for another organization's
     product), and publishes `PurchasedItemsConfirmed`;
   - `dismiss` keeps the items unassigned, open to matching, and remembers the dismissed key on each
     (`dismissed_proposal_key`): the same name is never proposed for those items again, another name is.
   No job, event handler or query creates a product; `AcceptProductProposalCommand` carries an actor and is
   sent only from the Ui processor. Another organization's proposals, items, products and categories are
   not found (tenant filter).
6. **Creating a product queues the model for the items it never saw.** `CreateProductHandler` and the
   accept handler publish `ProductCreated`; an event handler queues `MatchPurchasedItemsJob` with scope
   `not_yet_matched` and the model, with the same deduplication key as for newly recorded items
   (ADR 0042 §7), so a burst of products created in a row is one queued job (ADR 0025 §5). This is the
   trigger #262 asks for; it does not re-ask the items the model already answered for (scope `unassigned`),
   which stays the console command's `--again`, and the admin "Match again" button stays in #262.
7. **Admin.** The review queue shows the proposals above the items: name, measure, category, count and
   examples, with "create product" (a dialog to edit name, category, measure and reference quantity first),
   "add to a product" (the product picker) and "dismiss". A proposal whose name a product has now gets a
   one-click "add to that product".

## Alternatives considered

- **Create the products automatically and let the owner fix them.** Rejected: ADR 0041 §4 makes the
  catalog the owner's, a wrong product teaches wrong aliases, and merging junk is more work than
  confirming good proposals.
- **A second request to the model only for the unmatched items.** Rejected: the item, the catalog and the
  cached system block are already in the first request; asking in the same answer costs a few output tokens.
- **A `product_proposal` table.** Rejected: a proposal is only ever the model's opinion about an item, groups
  are derived from the items, and a table needs its own lifecycle (staleness, merging groups, tenant
  isolation). Columns on the item follow ADR 0042's `matchedAt` and move with it.
- **The model creates missing categories too.** Out of scope: the tree is the owner's (ADR 0041 §5); the
  issue says a new ADR only if this changes.
- **Dismissal by name across the organization.** Rejected for now: the issue says "for them" (the items), and
  a per-organization list of dismissed names is a table of its own; see the consequences.

## Consequences

- Four nullable columns and one foreign key on `purchased_item`, one more nullable column for dismissals,
  one error code (`purchasing.product_proposal_not_found`), two new events' worth of wiring
  (`ProductCreated`), four endpoints and a section of the review queue.
- With an empty catalog a first run asks the model about every item (it used to ask nothing), at the cost
  estimated in ADR 0042 §8, plus the category list in the cached system block.
- **Unmeasured.** Nothing here was checked against the live API or real names: the adapter's schema and
  parsing are tested against fixtures written to the documented response shape (ADR 0042 §2), and whether the
  model proposes sensible names, one name for the spellings of a product, and the right categories is part of
  the live check of #257. If its names split a product into several proposals, the owner merges them with
  "add to a product"; the prompt is what to change.
- Proposals can go stale: an item matched or confirmed another way drops out of its group on the next read, and
  a product created since is shown as the proposal's `existingProductId`.
- A dismissed name returns for **new** items of the same text (a later invoice's "Delivery"), because
  dismissal is remembered per item. If that is noisy, a per-organization list of dismissed keys is the next step.
- Creating the product and linking the items are one transaction; the job queued by `ProductCreated` matches
  the not-yet-matched items afterwards, so items the model answers "none" for stay proposals, not products.
- ADR 0042 §9's "proposing new products" is done by this ADR; the rest of §9 and the rest of that ADR stand.
