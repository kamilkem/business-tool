# 0043. A unit that holds several pieces gives no package size on its own: the seller's multiplier for it is learned from the owner's confirmations

Status: Accepted

Date: 2026-11-26

## Context

ADR [0042](0042-automatic-matching-of-purchased-items.md) §5 reads the package size of an item from its name
and treats every unit that is not a measure as one piece, so a size in the name is the size per unit of the
quantity. That holds for `szt.` and `but.`. It does not for a unit that holds several pieces: "Jack Daniels
1L | szt | 1" is 1 L, but "Jack Daniels 1L | CO | 1" is a case of 6, so 6 L, and nothing on the invoice says
6. `CO`, `kart.`, `op.`, `zgrz.`, `kpl.`, `paczka`, `BOX` and whatever else a seller prints are the seller's
words, and the same word may mean another count for another seller.

Under ADR 0042 such an item was read as 1 L with a strong reading and could be `assigned`. Worse, an alias the
owner confirmed from the bottle row (size 1), or the model's own "1", agreed with that reading, so the
item stayed `assigned` with a size six times too small. The seller + exact-name alias also ignores the unit,
so a size confirmed for one unit could be applied to another. Found while answering a question about `szt`
and `CO` (#261).

## Decision

1. **Units are of known or of unknown count.** A unit of known count is a measure (`l`, `ml`, `kg`, `g`, ...),
   no unit at all, a single piece or container (`szt`, `but`, `fl`, `puszka`, `keg`, `worek`, ...), or one that
   carries its size in its text (`op. 0,2l`). **Every other unit is of unknown count**: `CO`, `kart.`, `op.`,
   `zgrz.`, `kpl.`, `paczka`, a seller's own. Nothing is hard-coded for a seller or a spelling.
2. **A size in the name on a unit of unknown count is a weak reading.** It never leads to `assigned` on its
   own, and an alias or the model agreeing with it does not change that: the item is `suggested`, with the
   size read, for the owner to confirm. (This replaces the "one size on a piece unit is strong" rule of ADR
   0042 §5 for these units; sizes on units of known count, and the multipack and measure rules, stay.)
3. **The seller's multiplier** (`seller_unit`, tenant-aware): `(seller, unit) → k`, the number of pieces that
   seller's unit holds, a whole number of at least 1. A seller is its tax ID (or its name without one), a
   unit is as the seller printed it, lower case without diacritics or a trailing dot. When a person confirms
   an item whose name gives a size s on a unit of unknown count and whose package size is p, and p is a whole
   multiple k of s, the multiplier k is remembered, **the last confirmation winning**. A confirmation that is
   not a whole multiple, or on a unit of known count, teaches nothing. Matching never writes a multiplier;
   only a person's confirmation does, with the aliases (`LearnProductAliasesCommand`).
4. **Applying it.** When the seller has a multiplier for the item's unit, the size read from the name is
   multiplied by it and read as strong: one confirmation of a `CO` row teaches every `CO` row of that seller,
   whatever the product, and not another seller's. A strong reading wins over what an alias or the model
   says, as before, so a size an alias remembers for one piece does not override the learned count.
5. **The model's prompt** says that a case, carton or pack unit holds a count that only the text can tell, and
   that the package size is "" rather than a guess when it does not. Whatever it answers, §2 applies.
6. The arithmetic stays exact integer arithmetic on millionths (ADR 0040 §4, ADR 0042 §5); multipliers
   above 999 are not learned.

## Alternatives considered

- **A list of known pack units with their counts** (`CO` = 6, `kart.` = 24). The count is the seller's and the
  product's; a fixed list is wrong as soon as one seller prints `CO` for 12.
- **The unit in every alias key.** Fixes the alias that ignored the unit but teaches nothing across products,
  so every product's case has to be confirmed once, and GTIN aliases would stop matching across sellers.
- **Always `suggested` for units of unknown count.** Safe, but the owner would confirm every row of every
  case forever; the multiplier makes the first confirmation count for all of them.
- **Asking the model for the count.** The count is not in the data, so any answer is a guess.

## Consequences

- A new table, `seller_unit`, and a read of it for each matching run and each confirmation.
- Items on units of unknown count are `suggested` until the owner has confirmed one item of that seller and
  unit; items on `szt.` and the measures behave as before. After that confirmation the same unit is
  `assigned` with the learned count. The review queue should show why an item is only suggested (a unit of
  unknown count); that is a small addition for #257.
- A seller who prints the same unit for different counts (`CO` of 6 and `CO` of 12) gets the last
  confirmation's count for all of them; the items whose size does not fit are corrected by hand and teach the
  new count. If that is common, the unit needs the product in its key; it is not now.
- ADR 0042 §5 (the strength of a size on a piece unit) and the `assigned` rule of §6 are amended by this
  ADR; the rest of 0042 stands.
