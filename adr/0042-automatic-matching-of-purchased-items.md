# 0042. Purchased items are matched to products by remembered aliases first and trigram suggestions second; the package size is read from the name and the unit

Status: Proposed

Date: 2026-11-26

## Context

ADR [0041](0041-purchase-analysis-products-and-category-tree.md) gave every purchased item a
`product`, a link `status` (`unassigned`, `suggested`, `assigned`, `confirmed`) and a `packageSize`,
and left how they are filled automatically to spike #233. Until then the owner assigns every item
from the review queue (#246). The goal: "JACK DANIELS 0,7L 40%", "Whiskey Jack Daniel's Old No.7
700ml" and "J.DANIELS 1L" land on one product, and the 0.7 L and 1 L purchases are compared per
litre. The first bar buys on the order of a thousand items a month (ADR 0041 §8).

**What this ADR is based on, and what it is not.** The issue asks for a measurement on the first
bar's real invoice items, labelled by the owner (product and package size per item). **No such data
exists in the repository or in any development database** (the fixtures are demo data), and the labels
can only come from the owner. So the following is *not* measured and is not claimed anywhere below:
the share of real items with a GTIN, a seller code or a size, and the precision, recall and monthly
review load of each option. What was done instead:

- the parsing rules of §6 were written and run against 28 **synthetic** name/unit forms taken from the
  issue (`0,7L`, `700ml`, `70cl`, `KEG 30L`, `6x0,5L`, `24x330ml`, a size only in the unit, ...) and
  all give the intended result; this shows the rules are coherent, not how often real names obey them;
- `pg_trgm` was run (PostgreSQL 17, a throwaway container) on **synthetic** normalized names, below;
- the LLM option was costed from token counts and the published prices (§9), not run: item and seller
  names are the owner's business data and must not leave the system without the owner's decision.

Trigram scores of synthetic names (`similarity` is the whole-string score, `word_similarity` scores the
best matching part of the second string):

| A (an alias) | B (a new item) | `similarity` | `word_similarity` |
|---|---|---|---|
| jack daniels | j daniels | 0.64 | 0.64 |
| jack daniels | whiskey jack daniels old no 7 | 0.43 | 1.00 |
| jack daniels | jack daniels honey *(another product)* | 0.68 | 1.00 |
| coca cola | coca cola zero *(another product)* | 0.62 | 1.00 |
| jack daniels | jack daniel s old no 7 | 0.44 | 0.85 |
| johnnie walker red label | jw red label | 0.41 | 0.41 |

Two things follow. A name that spells the same product with extra words scores *lower* than a
different flavour of it (0.43 against 0.68), and `word_similarity` calls both a perfect match. So
similarity cannot tell a synonym from a variant, and it must never decide alone; it can rank
candidates. And abbreviations ("J.D.", "JW") are beyond it: they are learned from the owner, not
guessed.

## Decision

This ADR is **Proposed** until the owner's labelled sample confirms the acceptance criteria of §10; the
numbers named in it are starting points that the measurement calibrates (§10), not part of the decision.

1. **Matching is deterministic first, inside `App\Purchasing`, with no new technology but `pg_trgm`.**
   Its input is a `PurchasedItem` (name, unit, seller tax ID, seller code, GTIN, price) and the
   organization's products and aliases; its output is a product, a package size and a status. It
   never creates products (the catalog is the owner's, ADR 0041 §4) and never changes the item's
   copied fields. The reading of an item (§6) is pure Domain code, tested without a database; the
   alias and similarity lookups are repository queries.

2. **Aliases** (`product_alias`, tenant-aware, in Purchasing) remember what the owner confirmed. Each
   is one key that points to one product, and may carry a package size. The kinds, strongest first:
   1. `gtin`: the digits of the GTIN, across sellers. Carries the package size.
   2. `seller_code`: the seller (`SellerKey`) and its code, upper-cased. Carries the package size.
   3. `seller_name`: the seller and the **full** normalized name, the size kept. Carries the package size.
   4. `name`: the normalized name with sizes, multipacks, ABV and packaging words removed (§6),
      across sellers. No package size: each item's own name gives it.

   Keys are unique per organization and kind. An alias is written when a person confirms an item (the
   item's own keys, one alias per key it has) and replaced when a person later confirms the same key to
   another product: **the last confirmation wins**. Matching itself never writes an alias, so a
   machine's mistake cannot reinforce itself. Merging two products moves the aliases (ADR 0041 §4).
   An alias carries the package size because a GTIN, a seller code or an exact name stands for one
   pack, and that is how a size the name does not print (it sits in the seller's code, or in the unit)
   is remembered once; this replaces a package size on the product, which ADR 0041 §4 does not have.

3. **Confidence levels.** For an item that is not `confirmed`, the first rule that applies decides:
   - **`assigned`** (automatic): an alias of kind 1 to 4 exists for one of the item's keys, taking the
     strongest kind, **and** the package size is known (§6), or the product's measure is `none`.
     Similarity never produces `assigned`.
   - **`suggested`** (to confirm): the product comes from an alias but the size is unknown, ambiguous
     or contradicts the product (§7), or it comes from trigram similarity (§4). The item holds the
     best product; the review queue can ask for the next candidates.
   - **`unassigned`**: nothing was found. The package size stays null; the review queue may show the
     parsed size as a prefill, which is a query and writes nothing.

   An alias that ties (two products at the same strength) is not used; the item is `suggested` at most.

4. **Similarity uses `pg_trgm`** (a PostgreSQL contrib extension, a new `CREATE EXTENSION` in the
   follow-up's migration, a GIN index on the key columns). The item's name key is compared with the
   `name` aliases and the product names (normalized the same way) by `word_similarity`, best three
   candidates per item. A candidate is **dropped** by the variant guard unless every token in which
   the two names differ (in either direction) is on a short list of noise words: category and promo
   words ("whisky", "whiskey", "wodka", "vodka", "piwo", "promocja", ...). Any other extra token (a
   flavour, an age, a number, "zero") blocks the suggestion, which is why "jack daniels honey" never
   silently becomes "jack daniels". The price of the guard is that "whiskey jack daniels old no 7"
   against the alias "jack daniels" is dropped too ("old", "no" and "7" are not noise): that spelling
   needs one confirmation, after which its own `name` alias matches it exactly. The
   threshold starts at 0.8 for the best candidate and 0.1 ahead of the second; both are calibrated (§10).

5. **A person's removal is respected.** ADR 0041 §3 makes an item `unassigned` when a person takes its
   product away, which is indistinguishable from an item matching never saw. The follow-up adds a flag
   to `PurchasedItem` that a person's unlinking sets (cleared when a person links it again), and matching
   skips such items. This extends the model of ADR 0041 §3 without changing what it decided.

6. **Reading an item.**
   - **The name key**: lower-case, diacritics folded (`ł` → `l`), apostrophes dropped, other
     punctuation to spaces, then sizes (`0,7L`, `700 ml`), multipacks, ABV (`40%`), and packaging words
     (`szt`, `zgrz`, `kart`, `op`, `keg`) removed. Word order and extra words are not normalized:
     they are left to aliases and §4. "JACK DANIELS 0,7L 40%" → `jack daniels`; "J.DANIELS 1L" →
     `j daniels`.
   - **The package size**: base units (L, kg, pc) per **one unit of the item's quantity** (ADR 0041
     §3). Sources, in this order:
     1. the **unit is a measure** (`l`, `ml`, `hl`, `kg`, `g`, `dkg`): the quantity already is in base
        units, so the size is the unit's factor (1 for `l` and `kg`) and the name's size is ignored (30
        `l` of "KEG 30L" draught beer has size 1);
     2. a **size in the name**: one size, or the same size repeated; a **multipack** (`6x0,5L`,
        `0,5l x 6`, `6*0,5l`, `24x330ml`) is its total (3, 7.92) because the sold unit is the pack;
     3. a size in the **unit text** (`op. 0,2l`), when the name has none;
     4. the package size of the winning alias of kind 1 to 3 (§2);
     5. otherwise null. Two different sizes in one name (`0,5l (kaucja 0,3l)`) are ambiguous: null.
   - **Conversions** to the base unit: `ml` 0.001, `cl` 0.01, `dl` 0.1, `l` 1, `hl` 100 (volume);
     `mg` 0.000001, `g` 0.001, `dkg`/`dag` 0.01, `kg` 1 (mass). A decimal comma or point, any case, a
     space before the unit. A bare number without a unit (`0,7`, `No.7`, `12 YO`, `40%`) is never a
     size. Arithmetic in decimal strings (bcmath or brick/math), never floats (ADR 0040 §4); results
     keep at most six decimals, as `packageSize` does.
   - **Confidence of a parsed size**: *strong* for a measure unit, or a single size on a piece unit
     (`szt.`, `but.`, `op.`) or on the unit text; *weak* for a multipack (a seller may count the
     bottles, not the packs) and for a size on a unit the rules do not know. Only strong sizes may lead
     to `assigned`; a weak one makes the item `suggested`. Pieces (`10 szt.` in a name) are a weak size
     for products with measure `count`; with a piece unit and measure `count` the size is 1.

7. **Guards before `assigned`.** A parsed size whose measure differs from the product's (litres for
   a mass product) makes the item `suggested`. So does a **price sanity check**: the item's price per
   base unit differs from the product's median price per base unit by more than a factor (3 to start)
   when the product has at least five price points, which catches a wrong multipack reading. The
   check only lowers a status; it never edits a size.

8. **When matching runs** (jobs, ADR [0025](0025-background-jobs-scheduler-and-job-bus.md)):
   - **On new items.** `RecordPurchasedItemsHandler` publishes `PurchasedItemsRecorded` (the ids of
     the items it added); an event handler queues `MatchPurchasedItemsJob` for the organization with a
     deduplication key per invoice. Matching is not done in the event handler: it runs inside the
     importer's job, and lookups (and later an external call) do not belong there.
   - **Once for the history**: the console command `app:purchasing:match-items`, run after
     `app:purchasing:record-existing-items`, queues a per-organization job that pages through the
     matchable items by id, one flush per page, idempotent, with a time budget.
   - **After a person's confirmation**: the aliases are written (§2) and a job re-matches the
     organization's matchable items (deduplication key per organization), because a new alias fixes
     the siblings. A wrong `assigned` item that a person fixes thus corrects every item with the same
     key, and a key the person confirmed to two products stops giving `assigned` (§3, tie).
   - **After a merge**: the aliases moved with it; items moved with the product. Nothing re-runs. A new
     product, a rename or a category change does not change keys, so nothing re-runs either.
   - **Matchable** means: status `unassigned`, `suggested` or `assigned`, and not skipped by §5. A
     `confirmed` item is never read or changed by matching. The write is `PurchasedItem::match()`, a
     method that refuses a `confirmed` item, so the rule lives in the model, not only in the query.

9. **An LLM is not part of the first implementation; it is a later stage with fixed limits.**
   It is the third option of the issue (hybrid: rules first, a model for what is left). Its
   decision belongs to its own ADR once the measurement shows the review load that rules leave is too
   high, because it adds a provider, a data-processing agreement, retention terms and a cost. What this ADR fixes
   so that stage can be added without rework:
   - a port `ItemInterpreterInterface` in Domain (item and the catalog's names in, a product id or
     none, a package size and a measure out), an adapter on a scoped `symfony/http-client` in
     `Infrastructure/Anthropic/`, an in-memory adapter and recorded responses in `tests/Fixtures/`;
     tests and CI never reach the network (CLAUDE.md);
   - it runs only in the matching job, once per distinct key (never per request, never in HTTP), and only for
     items the rules left `unassigned` or `suggested`; an unavailable API or a 429 is a recorded outcome,
     not an exception (ADR 0025), and leaves the item as the rules left it;
   - its answer can produce `suggested` only, never `assigned` and never an alias;
   - **off by default and enabled per organization by an owner**, who is told what is sent: the item
     name, the unit and the seller's code and the names of the catalog's products; no prices, no
     quantities, no seller name or tax ID, no invoice number;
   - the model is a Haiku (`claude-haiku-5-5`) unless the measurement shows a need for more.

   *Cost estimate (assumptions, not measured).* A request holds about 4.3k input tokens (instructions and
   schema 0.6k, a catalog of 300 products at about 12 tokens each 3.6k, the item 0.05k) and about 80 output
   tokens. At the published prices (cached 2026-11-03): `claude-haiku-5-5` at $0.10 / $0.50 per million
   tokens costs about $0.0005 per item without caching, so 1000 items a month is under $0.5, and far less once
   deduplicated by key and with the catalog cached; `claude-sonnet-5-5` ($2 / $10) costs about $0.009 per
   item, about $9 for 1000. Cost is not the deciding factor; privacy, the owner's consent and
   availability are. Latency is assumed to be seconds per call and is not measured; the jobs worker
   takes one job at a time (ADR 0025), so a backlog of N distinct keys takes N calls in a row, which suits
   the one-time history as a batch job rather than interactive use.

10. **How this is accepted.** The owner labels an export of the distinct items (seller index, name,
    code, GTIN, unit, occurrences, the last price; seller names and tax IDs replaced by `S01`, `S02`;
    columns for the product, the package size and the measure), and the evaluation counts, weighted by
    occurrences:
    - the share of items with a GTIN, a seller code, a size in the name and a size in the unit, and the
      distinct (seller, name) keys per item;
    - the precision and recall of the size reading, and *strong but wrong* (must be 0);
    - the precision of `assigned` and the top-3 recall of `suggested` when the aliases are learned in time
      order from the labels (a replay of the history: each item is matched with the aliases confirmed
      before it);
    - the **monthly review load** after the first month: the new distinct keys a person still has to
      confirm, as keys and as minutes.

    Proposed criteria (the owner may change them): `assigned` precision at least 99% of items and
    strong sizes at least 99.5% correct; a review load of at most about 30 new keys a month after the
    first month. If the rules miss the load criterion and the owner agrees to an external provider, the
    LLM stage of §9 gets its own ADR and is measured on the same sample. The calibrated thresholds
    (§4, §7) are constants in the code, recorded with the measurement on the follow-up's PR.

## Alternatives considered

- **An LLM alone, or first.** Parses names well, but sends the owner's data out for every item,
  depends on a provider's availability and prices, gives different answers over time, and cannot be
  tested without recorded responses. Rules and aliases are free, local and reproducible; the model is
  kept for what they leave.
- **Embeddings (a vector extension or a provider's embedding model).** Handles synonyms, but needs
  `pgvector` or a provider, and shares the problem shown in the Context: a model scores "jack daniels honey"
  close to "jack daniels". Not measured; rejected for this stage because trigrams plus aliases need
  neither.
- **Similarity deciding alone (`assigned` above a threshold).** The synthetic scores show variants
  score above synonyms, so any threshold either misses real matches or links variants silently, and a
  wrong `assigned` corrupts the price history without a person seeing it.
- **PostgreSQL full-text search.** No Polish dictionary in the standard image, and brand names and
  abbreviations are not words a dictionary helps with.
- **Fuzzy matching in PHP (`levenshtein`, `similar_text`).** Loads every product and alias for every
  item, and has no index.
- **Aliases derived from the confirmed items instead of a table.** Self-healing, but it ties an alias
  to an existing item, cannot carry a remembered package size that is not an item's, and contradicts ADR
  0041 §4, which moves aliases on merge.
- **The package size on the product.** ADR 0041 §3 put it on the item; the remembered size of an alias
  gives the same effect for a seller's pack without a second place to keep it.
- **Creating products from unmatched items automatically.** The catalog is the owner's (ADR 0041 §4);
  an unmatched item stays in the review queue, grouped by name key by the follow-up so one decision covers
  every spelling of it.

## Consequences

- A new table (`product_alias`), a new extension in the migration (`pg_trgm`; contrib, shipped in the
  PostgreSQL image), a flag on `PurchasedItem` (§5), a new event and two jobs. None of this is in this change;
  the follow-up issue implements it.
- **Cold start.** With no confirmed items there are no aliases, so nothing is `assigned` and
  only similarity to product names suggests. The first months are manual; the review queue shows
  one row per name key, so a confirmation teaches every later invoice. The monthly review load, not
  the history's backlog, is what §10 measures.
- A wrong `assigned` stays visible: it is reviewable like any item, and one correction fixes its
  siblings. The price sanity check (§7) limits silent errors from multipack readings.
- Abbreviations and word-order changes ("J.D." for "Jack Daniel's") are learned only after the owner
  confirms them once.
- Acceptance depends on the owner's labelled sample. Until then the status stays Proposed and the
  thresholds are provisional.
- If the LLM stage is added, a new ADR names the provider, the data sent and the retention terms, and
  the organization's opt-in is recorded.
