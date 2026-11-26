# 0042. Purchased items are matched to products by the owner's remembered aliases first and a language model second, and the package size is read from the unit and the name

Status: Accepted (points 5 and 6 amended for units of unknown count by [0043](0043-units-of-unknown-count-and-the-sellers-multiplier.md))

Date: 2026-11-26

## Context

ADR [0041](0041-purchase-analysis-products-and-category-tree.md) gave every purchased item a `product`,
a link `status` (`unassigned`, `suggested`, `assigned`, `confirmed`) and a `packageSize`, and left how
they are filled automatically to spike #233. Until then the owner assigns every item from the review
queue (#246). The goal: "JACK DANIELS 0,7L 40%", "Whiskey Jack Daniel's Old No.7 700ml" and
"J.DANIELS 1L" land on one product, and the 0.7 L and 1 L purchases are compared per litre. The first
bar buys on the order of a thousand items a month (ADR 0041 §8).

The spike weighed a deterministic matcher (normalization, GTIN, seller code, `pg_trgm` similarity,
remembered aliases), a language model, and both. **The owner chose the model as the primary matcher.**

**What this is based on, and what it is not.** No real or labelled invoice data exists in the
repository or in any development database, and the labels (which product an item is, its package size)
can only come from the owner. So nothing about real items is measured: not the share of items with a
GTIN, a seller code or a size, nor the precision, recall or review load of any option. What was done:

- the size-reading rules (§5) were run against 28 **synthetic** name and unit forms from the issue
  (`0,7L`, `700ml`, `70cl`, `KEG 30L`, `6x0,5L`, `24x330ml`, a size only in the unit, ...); they are
  tests now (`ItemReaderTest`);
- `pg_trgm` was run (PostgreSQL 17, a throwaway container) on synthetic normalized names:

  | alias | new item | `similarity` | `word_similarity` |
  |---|---|---|---|
  | jack daniels | j daniels | 0.64 | 0.64 |
  | jack daniels | whiskey jack daniels old no 7 | 0.43 | 1.00 |
  | jack daniels | jack daniels honey *(another product)* | 0.68 | 1.00 |
  | coca cola | coca cola zero *(another product)* | 0.62 | 1.00 |
  | johnnie walker red label | jw red label | 0.41 | 0.41 |

  A flavour variant scores higher than a longer spelling of the same product, and `word_similarity`
  calls both perfect: trigram similarity cannot tell a synonym from a variant, and cannot read
  abbreviations. That is why it is not used;
- the model was costed from token counts and the published prices, below. **No item name, seller name or
  other real data was sent to any provider** while deciding this.

## Decision

1. **The model is the primary matcher; the owner's confirmations decide before it.** For an item that is
   not `confirmed`, an alias the owner confirmed (§3) settles it first, because it is the owner's own
   word, costs nothing and cannot drift. What no alias knows goes to the model, which says which product
   of the organization's catalog the item is and what package size it has. Matching never creates a
   product (the catalog is the owner's, ADR 0041 §4) and never changes the item's copied fields.

2. **The model sits behind a port** (`ItemInterpreterInterface`, Purchasing Domain), with an adapter on
   the Claude API (`Infrastructure/Anthropic/AnthropicItemInterpreter`), following the pattern for
   external APIs (a scoped `symfony/http-client` named `anthropic.client`, no SDK dependency): one
   request to `/v1/messages` per batch of 20 distinct items, model `claude-haiku-5-5` (`ITEM_MATCHING_MODEL`),
   `effort: low`, the instructions and the catalog in a cached system block, and the answers as
   structured output (`output_config.format`, a JSON schema, so the reply is always the shape the schema
   says; "none" and "unknown" are empty strings, as strict output has no optional fields). Anything that
   is not an answer (a transport error, a status other than 200, a refusal, a cut-off or unreadable
   reply) is an `ItemInterpreterUnavailableException` with the reason only, never the provider's body.
   Tests and CI never reach the network: handlers and API tests use `InMemoryItemInterpreter`
   (`tests/Fixtures`), the adapter test a `MockHttpClient` with fixtures in `tests/Fixtures/Anthropic`.
   **Those fixtures are written to the documented response shape, not recorded from a live call**
   (their README says so); recording a real answer for synthetic names is a step to take when a key is
   at hand.

3. **Aliases** (`product_alias`, tenant-aware) remember what the owner confirmed: one key of one kind
   points to one product, unique per organization and kind, the **last confirmation of a key wins**. The
   kinds, strongest first: `gtin` (the digits, across sellers), `seller_code` (the seller, as `SellerKey`
   names it, and the code), `seller_name` (the seller and the full normalized name, the size kept) and
   `name` (the normalized name without sizes, ABV and packaging words, across sellers). The first three
   name one pack, so they also remember the item's package size. Aliases are written only by a person's
   confirmation (`PurchasedItemsConfirmed` → `LearnProductAliasesCommand`), never by matching, so a
   machine's mistake cannot reinforce itself. Merging two products moves the aliases (ADR 0041 §4);
   deleting a product deletes them. When an item's aliases point to different products, the strongest
   kind's product is only `suggested`.

4. **A person's removal is respected.** ADR 0041 §3 makes an item `unassigned` when a person takes its
   product away, which would be indistinguishable from an item matching never saw. `PurchasedItem` gets a
   flag that a person's unlinking sets (cleared when a person links it again), and matching skips such
   items. This extends the model of ADR 0041 §3 without changing what it decided.

5. **Reading an item** is pure Domain code (`ItemReader`), tested without a database; what it cannot read
   is null, never a guess. The package size is base units (L, kg) per **one unit of the item's
   quantity** (ADR 0041 §3):
   1. the **unit is a measure** (`l`, `ml`, `hl`, `kg`, `g`, `dkg`): the quantity already is in base
      units, so the size is the unit's factor (1 for `l` and `kg`) and the name's size is ignored (30 `l`
      of "KEG 30L" draught beer has size 1);
   2. one **size in the name** (`0,7L`, `700 ml`, `70cl`), or the same size twice; a **multipack**
      (`6x0,5L`, `0,5l x 6`, `24x330ml`) is its total (3, 7.92), because the sold unit is the pack;
   3. a size in the **unit's text** (`op. 0,2l`) when the name has none;
   4. otherwise none; two different sizes in one name are ambiguous: none.

   Conversions: `ml` 0.001, `cl` 0.01, `dl` 0.1, `l` 1, `hl` 100 (volume); `mg` 0.000001, `g` 0.001,
   `dkg`/`dag` 0.01, `kg` 1 (mass). A decimal comma or point, any case, a space before the unit. A bare
   number (`0,7`, `No.7`, `12 YO`, `40%`) is never a size. The arithmetic is exact integer arithmetic on
   millionths of the base unit, never floats (ADR 0040 §4); sizes above 10 000 or with more than six
   decimals are not read. A reading is *strong* for a measure unit, or one size on a piece unit or in the
   unit text, and *weak* for a multipack (a seller may count the bottles, not the packs).

6. **Confidence levels** (`MatchDecision`, pure rules). **`assigned`** needs a confident source (an alias
   with no conflict, or the model's `high`), a package size that is known (or a product with measure
   `none`) and nothing that contradicts it. Everything else that has a product is **`suggested`**: the
   model is only `medium`, the size is unknown, only a weak reading says it or the sources disagree on it,
   the aliases conflict, or the size read from the item is of another measure than the product (litres for
   a mass product). The model's `low` is no match: the item stays `unassigned`. **A strong reading of the
   item itself wins over what an alias remembers or the model says**: what the item's own text states is
   the item's; the model's size is used only when the text gives none. A product counted in pieces with a
   piece unit and no size has size 1. Nothing here lets matching write `confirmed`.

7. **When it runs** (jobs, ADR [0025](0025-background-jobs-scheduler-and-job-bus.md)). Matching is never
   done in the importer's job:
   - **New items**: `RecordPurchasedItemsHandler` publishes `PurchasedItemsRecorded`; an event handler
     queues `MatchPurchasedItemsJob` (scope `not_yet_matched`, with the model), one per organization
     (deduplication key `purchasing-match-items:<org>:not_yet_matched:model`).
   - **The history**: `app:purchasing:match-items [--organization] [--again]` queues the same job for
     the organizations with invoice items, after `app:purchasing:record-existing-items`; `--again` also
     puts the unassigned items the model found nothing for to it (scope `unassigned`), for after the
     catalog has grown.
   - **After a person's confirmation**: `PurchasedItemsConfirmed` writes the aliases and queues the job
     with scope `all_matchable` and **aliases only**: a new alias settles the items that share its key,
     a wrong match that a person corrects corrects every item with the same key, and it costs no call.
   - **After a merge or a rename** nothing re-runs: items and aliases move with the product, and keys do
     not depend on names.
   - The job pages through the matchable items by id (not `confirmed`, not skipped by §4), 100 at a time,
     one `MatchPurchasedItemsCommand` and one flush per page, with a time budget after which it queues
     itself from the last item. The model is asked once per distinct name, unit and seller code. An item
     the model went through, with whatever result, gets `matchedAt`, so it is not asked again. **When the
     model cannot answer, the job records it and ends**; what the aliases decided is saved, and the items
     it did not reach stay for the next run. `PurchasedItem::match()` refuses a `confirmed` item, so the
     rule lives in the model, not only in the query.

8. **What is sent to the provider**: each item's name, unit and seller code, and the catalog's product
   ids, names, measures and category names. Never prices, quantities, dates, invoice numbers, seller
   names or tax IDs (a test pins it). **The model is switched on by `ANTHROPIC_API_KEY`**: empty (the
   default, in dev and tests), only aliases match items. Staging and production inject it as a secret
   (`deploy/stage/app.env.example`, `deploy.sh`, the deploy workflow's optional secret). It is a
   deployment-wide switch, not a per-organization one, because there is one organization; **a second
   organization needs its consent recorded before its names are sent** (a follow-up ADR).

   *Cost estimate (assumptions, not measured).* A request holds about 4.3k input tokens (instructions
   and schema 0.6k, a catalog of 300 products at about 12 tokens each 3.6k, the items) and about 80
   output tokens per item. At the published prices (cached 2026-11-03): `claude-haiku-5-5`
   ($0.10 / $0.50 per million tokens) costs about $0.0005 per item without caching, under $0.5 for 1000
   items a month, far less with batches of 20, deduplication and the cached catalog; `claude-sonnet-5-5`
   ($2 / $10) about $0.009 per item. Cost is not the deciding factor; the owner's consent and the
   provider's availability are. Latency is assumed to be seconds per request and is not measured; the jobs
   worker takes one job at a time (ADR 0025), so a backlog is matched in order.

9. **Not in this decision, and known**: proposing *new* products for what the catalog lacks (with no
   products the model can match nothing: the first products come from the review queue, and `--again`
   re-offers the rest); a price sanity check against the product's earlier prices to catch a wrong
   multipack reading; aliases for items confirmed before this shipped (they teach only when confirmed
   again); a button in the admin for `--again`; and any measurement on real data.

## Alternatives considered

- **Deterministic matching with `pg_trgm` similarity** (the issue's option 1). The synthetic scores above
  show variants outscore synonyms, so any threshold either misses real matches or links variants, and it
  cannot read abbreviations; it would need a variant guard and still only suggest. It also adds a
  database extension. Not used; the owner's aliases keep the deterministic part that is safe.
- **The model alone, without aliases.** Asks (and pays, and risks drift) again for what the owner already
  decided, and could overrule the owner. Aliases are the owner's word.
- **Embeddings (a vector extension or a provider's embedding model).** Handles synonyms but needs
  `pgvector` or a second provider, and shares the variant problem. Not measured; not needed with a model
  that can read the names.
- **The official PHP SDK** instead of the scoped HTTP client. The repository's pattern for external APIs
  is a port plus an adapter on `symfony/http-client` with `MockHttpClient` tests (CLAUDE.md, ADR 0023);
  one request shape does not justify a dependency.
- **One request per item.** Repeats the catalog for each; batches of 20 distinct items share one cached
  catalog.
- **The package size on the product.** ADR 0041 §3 put it on the item; the size an alias remembers for a
  pack gives the same effect without a second place to keep it.
- **Aliases derived from the confirmed items instead of a table.** Self-healing, but ADR 0041 §4 moves
  aliases on merge, and a table is simpler to look up.
- **Creating products from unmatched items automatically.** The catalog is the owner's (ADR 0041 §4).

## Consequences

- A new table (`product_alias`), two columns on `purchased_item` (the removal flag and `matchedAt`), two
  events, a job with three scopes, a console command and a new external dependency: the Claude API, off
  until a key is set. Each tenant-aware piece has its isolation test.
- **Item and product names leave the system for Anthropic once the key is set**, under that provider's
  terms; the owner decided it, and §8 limits what is sent. The key is a secret like the others.
- **Cold start**: with no products and no aliases nothing matches; the first months of the review queue
  teach the aliases, and the model matches what the catalog covers.
- Quality on real data is unknown until the owner reviews a month of `assigned` items. A wrong `assigned`
  is visible and reviewable like any item, and one correction fixes its siblings; the guards of §6 and
  the aliases' priority limit silent errors. If the review shows too many, the thresholds of §6 and the
  prompt are what to change, and a labelled sample (an export of the distinct items with the owner's
  product and size for each) is how to measure it.
- If the provider is down or the key is rejected, matching stops quietly and the aliases keep working;
  the review queue is unchanged.
- ADR 0041's `suggested` and `assigned` statuses now have a producer; its "until matching lands" note no
  longer applies.
