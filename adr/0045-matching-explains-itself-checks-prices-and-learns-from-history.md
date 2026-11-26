# 0045. Matching says why an item is only suggested, checks prices against the owner's confirmed ones, and learns aliases from items confirmed before aliases existed

Status: Accepted

Date: 2026-11-26

## Context

ADR [0042](0042-automatic-matching-of-purchased-items.md) matches purchased items to products and listed three
things as known and open (§9): a price sanity check against the product's earlier prices, aliases for items
confirmed before it shipped, and (with #262) controls in the admin. Issue #257 collects what is left; this
decision is the part of it that needs no real data and no live API. The owner-only part (a labelled sample and
the measurement, the first live call, per-organization consent) is not decided here.

Three gaps:

- **A suggestion is a bare guess to its reader.** The review queue (#246) shows `suggested` with a product, and
  nothing about why: the alias that matched, the model, a missing size. The owner cannot tell a likely hit from
  a coin flip, and the rules of ADR 0042 §6 cannot be judged from outside.
- **A wrong multipack or unit reading survives every rule of §6.** "6x0,5L" read as 0.5 L when the seller counts
  bottles, or a case read as one bottle, gives a size several times off; `assigned` needs only a confident source and
  a size that is known. The error shows only as a price per litre far from the product's others.
- **Items confirmed before ADR 0042 teach nothing.** Aliases are written only when a person confirms (ADR 0042
  §3), so the history the owner already assigned by hand (#242, #246) is invisible to matching: a month's work
  has to be done again to be remembered.

ADR 0042 is Accepted and is not edited beyond its Status line (ADR 0000); this ADR amends its §6 and §9.

## Decision

1. **A matched item keeps what linked it and why it is only suggested.** `purchased_item` gets two nullable
   columns, `match_source` and `match_caveat`, written by `PurchasedItem::match()` from what `MatchDecision` decided
   and nothing else:
   - the **source**: `gtin`, `seller_code`, `seller_name` or `name` (the owner's alias of that kind, the strongest
     one that matched), `model` (the model answered with a product of the catalog) or `proposed_name` (the model
     proposed a new product by a name a product has, ADR 0044 §3);
   - the **caveat**, only for `suggested`, the first rule of ADR 0042 §6 that held the item back: `measure_mismatch`
     (the size read is of another measure than the product, which also discards the size); else the source's own
     doubt, `aliases_conflict`, `model_unsure` or `proposed_name_only`; else the size: `size_disagrees` (an alias
     or the model remembers another size than the text), `size_unverified` (the sizes agree but sit on a unit of
     unknown count, ADR 0043), `size_read_weakly` (only a multipack in the name says it), `size_unknown`. A
     decision with none of these is `assigned` and has no caveat; `price_outlier` (point 2) is the one reason that
     only ever lowers an item that had none.

   A person's `link()` clears both (the item is theirs now), a run that finds nothing leaves them, as it leaves
   the product, and an item matched before this has neither (null: unknown, not "no reason"). They describe the
   last matching run, not a history. `PurchasedItemView` and `PurchasedItemResource` carry them as two nullable
   enums (`matchSource`, `matchCaveat`), and the review queue prints both under a suggested item, in English and
   Polish. A similarity score is not among them: ADR 0042 chose against trigram similarity, so there is none.
2. **The price sanity check.** After `MatchDecision` has decided, a decision that is `assigned` and has a product
   with a measure and a package size is checked, whoever decided it (an alias or the model); one that is
   `suggested` already, or has no product or size, is not. `PriceSanity` (Domain, pure) does it with integers:
   - **the price** of an item is its net value in minor units (the printed net, or gross less VAT, as the analysis
     reads it, ADR 0041 §6) per base unit, `net × 10⁹ ÷ (quantity × package size in millionths)`, so thousandths
     of a minor unit per litre or kilogram, rounded half up, never a float (ADR 0010); an item with no positive
     net, quantity or size, a correction or its "before" row, or arithmetic that does not fit an integer, has no
     price and is never checked or sampled;
   - **the sample** is the product's `confirmed` items in the item's currency, plain purchases only, the most
     recent `MAX_SAMPLE` = 200 by issue date. Only `confirmed` ones: what matching assigned would let a mistake
     vouch for itself, the reason aliases are written only by a person (ADR 0042 §3). A product with a measure
     `none` has no price to compare;
   - **the rule**: with at least `MIN_SAMPLE` = 5 prices in the sample, an item whose price is above
     `MAX_RATIO` = 3 times their median, or below the median divided by 3, is an outlier; the edges are fine.
     The decision becomes `suggested` with the caveat `price_outlier`, keeping its product and package size;
     matching never writes `confirmed` and never unlinks, so a person decides with the reason in view.

   The three numbers are named constants of `PriceSanity`. **They are reasoned, not measured** (there is no real
   data, as in ADR 0042): a wrong pack count multiplies the price per litre by the count (a pack of 6, 12 or 24;
   ml read as l by 1000), which 3 catches from a pack of 4 up, while the real spread of one product's price across
   sellers, promotions and a year of inflation is usually well within 3×; five confirmed prices are the fewest
   that a median can be trusted on, and a product the owner confirmed a handful of times is exactly the one
   whose size reading is least tested. A false alarm costs the owner one click, a missed error a wrong price
   point in the analysis, so the threshold leans to alarms but not to noise. The median, not the mean, keeps one
   wrong confirmed price from moving the baseline. The calibration on a labelled sample is part of #257's owner
   items. The check runs when an item is matched: a confirmation queues the aliases-only re-match
   (ADR 0042 §7), which goes through the `assigned` ones too, so the sample's growth is felt then.
3. **Aliases from the history.** `app:purchasing:backfill-aliases [--organization]` queues
   `BackfillProductAliasesJob` per organization with invoice items (ADR 0025: an organization job, deduplicated, a
   console command under `Ui\Console\Purchasing`). It pages through the organization's `confirmed` items with a
   product, 100 at a time, **newest invoice first** (issue date, then id, both descending, a keyset cursor), one
   `BackfillProductAliasesCommand` and one flush per page, with a time budget after which it queues itself from
   the last item. The handler creates the aliases the item's keys would have got and **only for keys that have
   none**: an alias that exists was taught by a later confirmation, or by a newer item of an earlier page, and is
   the better word, so the run is idempotent and safe to interrupt, and the first (newest) item of a key decides it.
   Newest first stands in for "the last confirmation of a key wins" (ADR 0042 §3): items carry no confirmation
   time. The job only reads items: no status, link or package size changes, the model is not called, and no
   seller multiplier is learned (ADR 0043; a later confirmation does). Run it once per environment before
   `app:purchasing:match-items`, which then uses the aliases (`deploy/README.md`).

## Alternatives considered

- **A reason as free text, or a list of every rule that applied.** Rejected: free text cannot be translated or
  tested, and the rules overlap (no size is also a weak reading); the first reason is what the owner can act on.
- **A reasons history, or a table.** Rejected: the question the owner asks is "why is this suggested now", the
  state of the item is the last run, and an audit trail has no reader yet.
- **The price check against the previous price (the analysis' price change), or against the mean and a
  deviation.** Rejected: one earlier point is noisy and a seller's promotion would flag its successor; a deviation
  needs a sample larger than a small bar has for most products and is moved by the very outliers it looks for.
- **A sample of every linked item, or of `assigned` too.** Rejected: matching would vouch for itself, and a wrong
  multipack reading repeated across a seller's invoices would become the median.
- **A median in SQL (`percentile_cont`).** Rejected: the rule belongs in tested Domain code with the other
  matching rules, and the repository reads only scalars (the same shape as the proposals, ADR 0044 §4).
- **Lowering to `unassigned`, or removing the product.** Rejected: the match is probably right and the size is
  what is doubtful; the owner should see both.
- **Backfilling by sending `LearnProductAliasesCommand` for the history.** Rejected: it repoints an existing alias,
  so the oldest page would overwrite what a recent confirmation taught.
- **Backfilling oldest first.** Rejected: without a confirmation time the only order is the invoice's, and with
  "only keys that have none" the first item wins, so the newest must come first.
- **Learning on every deploy, or in a migration.** Rejected: it is an operator task like
  `app:purchasing:record-existing-items`, with a job, a log line and a time budget, not schema.

## Consequences

- Two nullable columns on `purchased_item`, a migration with no data step, two enums in the API and the OpenAPI
  document, a line in the review queue, a job, a command and a console command; the error codes are unchanged.
- **Unmeasured.** The threshold, the minimum and the sample size are reasoned values (point 2), not tuned on real
  items; whether they flag too much or too little is part of the owner's labelled sample (#257). If
  `price_outlier` is noisy, raise `MAX_RATIO` or `MIN_SAMPLE`; both are one constant.
- A new product has no price check until five of its items are confirmed, so the first month relies on the rules
  of ADR 0042 §6 alone.
- A person confirming an item with a wrong price puts that price into the sample: the owner's word wins, as for
  aliases, and one wild price does not move a median of five or more.
- Items matched before this change show no source and no reason until a run goes through them again
  (`app:purchasing:match-items --again`, or a confirmation's re-match); the screen shows nothing for them rather
  than a wrong "unknown".
- The backfill does not teach package-size multipliers of units of unknown count (ADR 0043); the first
  confirmation of such a unit after it still does.
- ADR 0042 §9's price check and its aliases for earlier confirmations are done by this ADR; the admin button for
  `--again` stays in #262.
