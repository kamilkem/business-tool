# 0046. Matching runs again when the catalog changes and when the owner asks, and each run is recorded

Status: Accepted

Date: 2026-11-26

## Context

ADR [0042](0042-automatic-matching-of-purchased-items.md) §7 runs the model on newly recorded items and,
for the history, only by `app:purchasing:match-items [--again]` on the server. ADR
[0044](0044-the-model-proposes-products-the-owner-creates-them.md) §6 added one more trigger: a created
product queues the items the model never saw (scope `not_yet_matched`). What is left (#262):

- An item the model *found nothing for* is `unassigned` with a `matchedAt`, so `not_yet_matched` never asks
  again. When the catalog grows (a product is created) or a product is renamed or its measure changes, those
  items may match now, and only the console command asks again.
- The owner cannot ask from the admin, and cannot see what a run did: how many items went through the model,
  how many it assigned or suggested, when it last ran, or that it could not run (no key, provider down).
- A burst of catalog changes (an owner accepting twenty proposals in a row) must not become twenty passes over
  the whole unassigned history.

## Decision

1. **A rename or a measure change is a domain event.** `UpdateProductHandler` publishes `ProductChanged`
   (primitives: organization, product, `renamed`, `measureChanged`) only when the name or the measure
   differs from what the product had: they are what the model is shown of a product (ADR 0042 §8). The
   category and the reference quantity are not (the model sees the category *name*, which a person
   changes rarely and which does not decide a match), so changing them queues nothing.
2. **One more pass over the unassigned items after a catalog change.** `ProductCreated` and `ProductChanged`
   each queue the existing `not_yet_matched` job (unchanged, ADR 0044 §6) and a *settling* job: scope
   `unassigned`, with the model, queued **with a delay of 5 minutes** under its own deduplication key
   `purchasing-match-items:<organization>:after-catalog-change`. The key is held from dispatch until the job was
   handled (ADR 0025 §5), so every product created or changed in those five minutes adds nothing: **a burst is
   one pass, which sees the catalog as it is when it starts.** The delay is a new optional argument of
   `JobBusInterface::dispatch()` (Messenger's `DelayStamp`); the deduplication lock's TTL (2 hours) covers it.
   The settling job has a key of its own so that the owner's "Match again" (point 4), which should start now,
   is never swallowed by a job waiting for its delay.
3. **"Once" is guaranteed by a cutoff, not by luck.** A matching job may carry `askedBefore`, the moment of its
   trigger, and then goes only through items the model has not been through since then (`matchedAt IS NULL OR
   matchedAt < askedBefore`). Whatever the reason a pass runs twice (a retry after a failed attempt, a job
   queued by the button and one by the settling trigger, a continuation after the time budget), **an item is put
   to the model at most once per trigger**: the second run finds the first one's items already newer than its
   cutoff. Without a cutoff (the console command, the `not_yet_matched` job) the scopes behave as before.
   A pass is repeated only by a new catalog change or by a person; it is never scheduled to repeat itself.
4. **"Match again" in the admin.** `POST /purchased-items/match` (owners and managers; 403 for anyone else,
   404 when the caller is not a member) queues the `unassigned` scope with the model and `askedBefore = now`,
   under the key `app:purchasing:match-items --again` uses, and answers `202` with the status of point 5. It
   takes no body (nothing to validate: 422 does not apply). While one is queued or running a second is dropped.
   With no model configured it still runs, for the aliases only, and records nothing (point 6).
5. **Status.** `GET /purchased-items/match` returns, for owners and managers of the selected organization:
   `waitingCount` (the matchable items with no product: what "Match again" goes through), `modelEnabled`
   (an interpreter is configured: `ANTHROPIC_API_KEY`, ADR 0042 §8) and `lastRun`, the newest run or null.
6. **Runs are recorded in `matching_run`** (tenant-aware): id, scope, `startedAt`, `finishedAt`, outcome
   (`done` or `model_unavailable`) and the counts `interpreted`, `assigned`, `suggested`. A run is recorded
   only when the job uses the model and one is configured, once the first page of items is known to be
   non-empty: the job queued by every recorded invoice or confirmation would otherwise bury the last real run
   under empty ones. The job handler sends `StartMatchingRunCommand` and, when it ends, `FinishMatchingRunCommand`
   (one aggregate each); a run that continues in a new job keeps its id (`runId` on the job), so one run is the
   whole chain. A run that never finished (the worker died) shows as `interrupted` once it is older than an hour,
   until then as `running`.
   *Where the counts come from.* The match handler stays as ADR 0042 made it (commands return nothing, one
   flush per page); `FinishMatchingRunCommand`'s handler counts, from the items, those the model went through
   since the run started: `matchedAt >= startedAt` and either no product (the model found nothing) or a
   `matchSource` of `model`/`proposed_name` (ADR 0045 §1); `assigned` and `suggested` split them by status after
   the price check. Jobs run one at a time (ADR 0025 §5), so nothing else writes `matchedAt` in the window;
   an alias decision never counts as the model's. Items a person confirms meanwhile leave the count as they
   leave the queue, which is correct for "what the run did to the queue now".
7. **Admin.** The review queue gets a card above the proposals: the waiting count, the last run (when it
   finished, how many items went through, how many were assigned, suggested, found nothing), "Match again",
   a notice when the model is switched off (the button is disabled) and a warning when the last run could not
   reach the model. After a click it polls the status for a short time and shows the new run.

## Alternatives considered

- **Re-ask the unassigned items on every `ProductCreated`, immediately.** What the issue's wording suggests,
  and the simplest. Rejected: twenty accepted proposals would be twenty passes (and twenty times the cost of
  ADR 0042 §8's estimate for the unassigned history), each one seeing a catalog that the next click changes.
- **A per-organization "catalog version" column** (bumped by every product write, compared with each item's
  asked version) instead of `askedBefore`. Exact, but a new column on `organization` or `product`, written on
  every product change, to answer a question the time of the trigger answers well enough.
- **A debounce that restarts on every event** (the delay restarts, the pass waits for quiet). Needs a lock
  that can be replaced, which `DeduplicateStamp` is not; a fixed window from the first event is bounded
  (the pass runs at most five minutes after the change) and uses what ADR 0025 has.
- **Counts from the match handler** (a result object, or a log line parsed). Command handlers return nothing
  (ADR 0004), and a log is not data the admin can read; counting the items afterwards touches neither.
- **One `matching_status` row per organization, overwritten.** Smaller, but loses the history of runs that is
  the only trace of what the model did to the owner's data, and needs an upsert against two writers.
- **Recording every job**, also the alias-only and empty ones. Rejected in point 6.

## Consequences

- A catalog change costs one extra pass over the unassigned items, five minutes later, with the model: about
  one request per 20 distinct names (ADR 0042 §8's estimate, which is not measured). An owner who creates one
  product a day pays it daily; one who creates twenty in a sitting pays once.
- The five-minute window is a constant (`MatchPurchasedItemsJob::SETTLE_DELAY_SECONDS`), not a setting.
- A run's counts are exact only while jobs run one at a time. Running them in parallel (ADR 0025 §5 warns it
  needs a per-key lock first) would need the counts taken per run, not per window.
- `JobBusInterface::dispatch()` gains an optional delay; ADR 0025's other rules are unchanged.
- ADR 0042 §7's "after a merge or a rename nothing re-runs" is amended for a rename or a measure change; a
  merge still re-runs nothing. ADR 0044 §6 stands, and its "the admin 'Match again' button stays in #262" is
  done here. ADR 0042 §9's "a button in the admin for `--again`" is done here.
- The owner-only parts of #257 (a labelled sample, a live check, per-organization consent for sending names)
  are not touched: the button sends the same fields as the other triggers (ADR 0042 §8).
- New table `matching_run`, new endpoints `GET` and `POST /purchased-items/match`, new event `ProductChanged`,
  and `CLAUDE.md`'s matching row names them.
