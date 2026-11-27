# 0047. A different alcohol strength is a different product: the name alias key keeps the ABV, and a link to a product of another ABV is never made

Status: Accepted

Date: 2026-11-27

## Context

The owner reported on real data (#268) that matching grouped "PIWO LITOVEL BLACK LEMON 4% 0,5l BUT ZWROTNA" and
"PIWO LITOVEL BLACK LEMON 0,5% 0,5l BUT ZWROTNA" under one product, as a suggestion. They are a 4% beer and a 0.5%
one. Reproduced in `ItemReaderTest`: ADR [0042](0042-automatic-matching-of-purchased-items.md) §3 defines the `name`
alias key as "the normalized name without sizes, ABV and packaging words", so both names reduce to
`piwo litovel black lemon zwrotna`, and an alias learned from a confirmation of one settles the other (`assigned`,
or `suggested` when a size or price disagrees). Three more places let a differing ABV through:

- **The model.** Its instructions say "extra words ("40%", promo text)" do not make a product differ, and ask
  for a `proposedName` "without ... ABV", so two proposals for the two beers are one name and are grouped as one
  product (ADR [0044](0044-the-model-proposes-products-the-owner-creates-them.md) groups by the proposed name).
- **The catalog.** When the owner named two products by their strengths, nothing stops the model's answer, or a
  proposed name, from linking an item to the one of the other strength.
- **The data already there.** `name` aliases learned from earlier confirmations carry the old key, and the links they
  made are `suggested`/`assigned` items under the wrong product.

The other variants named in #233 and ADR 0042 (a flavour: "Jack Daniel's Honey" against "Jack Daniel's"; an age:
"12 YO" against "18 YO"; a vintage: 2015 against 2018) are already kept apart by the key: only sizes, ABV and
packaging words are removed, every other word and number stays. Tests pin that now. The model's instructions
already name the flavour and the age; they now name the vintage too.

ADR 0042 is Accepted and is not edited beyond its Status line (ADR 0000); this ADR amends its §3 and §8, and the
`proposedName` rule of ADR 0044 §1.

## Decision

1. **The ABV is part of the `name` key, as one token.** `ItemReader::nameKey()` replaces a strength with
   `abv<digits>` (`abv40`, `abv0p5`; the point is a `p`) in the place it stood, and keeps removing sizes,
   multipacks and packaging words. "40%", "40 %", "40,0%", "40% vol", "40 proc." and "VOL. 40 %" are the same token,
   so the same product printed differently stays one key: the number is read as a decimal and normalized (`0,50` is
   `0.5`), a percent sign, `proc`, `procent...` or `vol` follows it, an optional `vol`/`obj`/`alk` may follow that. A
   number above 100 or after a minus (`-20%`, a discount) is no strength. The `seller_name` key is the seller's
   exact name and is unchanged; the `gtin` and `seller_code` keys name a pack and are unchanged.
2. **A name states one ABV or none.** `ItemReader::abvOf()` is that strength as a normalized decimal string, or null
   when the name states none or two different ones (nothing is guessed). `ItemReader::abvContradicts()` is true
   exactly when both an item's name and a product's name state a strength and they differ. A product named without a
   strength contradicts no item, and an item without one no product.
3. **A contradicting product is never linked by matching.** The handler discards a product the model answered with,
   and a catalog product whose name a proposed name equals, when it contradicts the item's name (the item stays
   `unassigned`, and keeps the proposal the model made). The aliases are not checked this way: a `gtin`,
   `seller_code` or `seller_name` alias is the owner's word about one pack, and a `name` alias now has the ABV in its
   key, so it cannot join two strengths.
4. **A link matching made to a contradicting product is taken back.** At the start of every matching run the handler
   releases the matchable items linked to a product that contradicts them, unless an alias of a pack made the link
   (`PurchasedItem::releaseMatch()`): the item is `unassigned`, without a source or caveat, and `matchedAt` is
   cleared, so the next pass of the model looks at it again. Runs after a person's confirmation (`all_matchable`)
   therefore clean up what the model or an earlier key linked wrongly; the check costs one comparison per item and
   no call.
5. **The model is told.** The instructions say that a different strength is a different product (the two beers as the
   example), that the same strength printed differently is the same, that a differing vintage or age is a different
   product, that a line with no strength may be a product whose name states one only at confidence `medium` at most,
   and "high" needs the strength to agree too. A `proposedName` now keeps the strength the line states
   ("Litovel Black Lemon 4%"), so the two beers become two proposals. The proposals of the same product with and
   without a printed strength are two as well; the owner assigns one to the other's product.
6. **Existing data** is fixed by a migration and two commands the operator runs once per environment:
   - the migration drops every `name` alias (a key is derived by PHP, not SQL, so the wrong ones cannot be told
     apart from the right ones) and takes back, for every organization, the links they made: items with
     `match_source = 'name'` that are `suggested` or `assigned` and not taken off their product by a person become
     `unassigned` with `matched_at` cleared. `confirmed` items and links of any other source are untouched;
   - `app:purchasing:backfill-aliases` (ADR 0045 §3) learns the `name` aliases again from the confirmed items with the
     new key, newest first, and `app:purchasing:match-items` then matches the released items with them and the model.
     Both are idempotent. Items matched before ADR 0045 have no `match_source`; they are not touched by the migration,
     and the check of point 4 releases the ones whose product states another strength.

## Alternatives considered

- **The ABV as a separate field compared on its own, the key without it.** An alias would then settle the
  items and a differing ABV lower the confidence to `suggested`. It keeps "same product, ABV only printed on some
  invoices" together, but it is the behaviour that grouped the beers, only marked; a suggestion is what the owner reads
  and confirms in bulk, and a wrong one teaches an alias (ADR 0042 §3). The issue asks that two names never be grouped.
- **ABV as a wildcard when the item states none.** An item without a strength would look up the aliases of any
  strength. That re-creates the case for the beers whenever one invoice leaves the strength out, and needs several
  lookups. Not used: such an item goes to the model, which sees the raw name.
- **Rebuilding the aliases in SQL in the migration.** The key is a PHP function; a SQL copy would drift from it.
- **Re-running the model for every non-confirmed item.** Costs a call per item to find the few that are wrong; the
  contradiction check finds the ones it can without a call.

## Consequences

- An alias taught by "Jack Daniels 0,7L 40%" no longer settles "Jack Daniels 1L" (no ABV printed): the `name` key
  differs, so it goes to the model. That costs calls and some `suggested` where there was an alias hit, and is the
  price of never joining two strengths. A seller that prints the strength on some lines only teaches two aliases.
- The model still sees raw names, so its answers for an item with no printed strength rely on the instructions and
  the catalog; the check of point 3 only catches a catalog product whose *name* states a different strength. A
  product the owner named without its strength (`Piwo Litovel Black Lemon`) cannot be told from either beer by
  rules; naming products with their strength is the owner's remedy, and a wrong suggestion is corrected in the
  review queue like any other (one correction teaches one alias).
- The migration changes data in every organization and has no `down`. Until the operator runs the backfill and the
  matching command, the released items are `unassigned`, and aliases from confirmations made meanwhile are learned
  with the new key already.
- A strength read from a promotion text ("promo 20%") next to a product that states a strength could hold back a
  link; a percent after a minus is excluded and the rest is left to the review queue.
- Tested on synthetic names only (the two beers, a 37,5% and a 40% vodka, the Jack Daniel's, whisky age and wine
  vintage forms); nothing was sent to the Claude API (ADR 0042 §8), and the prompt is pinned by a test of the
  request body.
