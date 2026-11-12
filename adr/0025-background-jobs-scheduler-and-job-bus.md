# 0025. Background and scheduled jobs: Symfony Scheduler and a job bus on a PostgreSQL `jobs` queue, run by a second worker

Status: Proposed

Date: 2026-11-12

## Context

Two features need work outside an HTTP request:

- **The KSeF import** (#52, ADR [0023](0023-ksef-purchase-invoice-import.md) §5,
  ADR [0024](0024-expenses-independent-of-ksef.md)): once an hour from 20:00 to 06:00 Polish time for
  every active connection, organizations one after another (KSeF limits apply per NIP and client IP),
  plus "Import now" from the admin. That request must only *queue* a run and return; the run itself
  (authentication, polling, up to tens of downloads) takes minutes, sometimes close to an hour, and
  must not be killed halfway through an invoice by a worker time limit.
- **Forgotten clock-outs** (#33) and later payroll: periodic work per organization at a time that
  depends on the organization's time zone.

What exists today:

- ADR [0004](0004-layered-modules-and-cqrs-buses.md) keeps the command, query and event buses
  synchronous and without default middleware; it rejected an async transport only because "nothing
  needs it yet", and noted that adding one is a matter of routing.
- ADR [0012](0012-transactional-email-smtp-async-queue.md) added the first async path: Symfony
  Mailer's own bus routes `SendEmailMessage` to the `async` transport (PostgreSQL,
  `messenger_messages`), consumed by the `worker` service. CLAUDE.md forbids routing command, query or
  event messages to it. Its Consequences leave it to this spike to reuse that transport and worker or
  supersede points 4–5.
- CLAUDE.md's pattern for a background job is a console command in `Ui\Console\<Module>\` that asks
  the query bus for ids and sends one command per aggregate after `TenantContextInterface::switchTo()`.
  Nothing uses it yet, and nothing triggers console commands on a schedule.
- Hosting (#35) is undecided: the options range from a VPS with Docker Compose to a PaaS with web and
  worker processes. Whatever is chosen runs the API image; a host crontab may not exist.
- The image has no `pcntl` extension, so a worker cannot handle `SIGTERM` gracefully or use
  Messenger's `--keepalive`. The Doctrine transport redelivers a message whose handling has lasted
  longer than `redeliver_timeout` (default 3600 s) to another consumer.
- Symfony 8.1 ships what is needed without extra infrastructure: `symfony/scheduler` (schedules as
  Messenger transports), `symfony/lock` with a PostgreSQL store, and Messenger's `DeduplicateStamp`
  (a queued message is not queued again while a lock with its key is held).

## Decision

1. **Schedules are Symfony Scheduler, not cron.** `symfony/scheduler` and `symfony/lock` become
   dependencies. Schedules live in `Ui\Scheduler\` (a delivery mechanism, like console commands), in
   one `default` schedule (`#[AsSchedule]`, transport `scheduler_default`). Each entry is a
   `RecurringMessage::cron(...)` with an explicit time zone (`Europe/Warsaw` for KSeF) whose message is
   a job (point 2) sent to the `jobs` transport: the scheduler only enqueues and never does the work
   itself. The schedule is **stateful** (its last run kept in a PostgreSQL cache pool,
   `cache.adapter.doctrine_dbal`, so a tick missed during a deploy fires once afterwards) and
   **locked** (a `symfony/lock` lock, so a second scheduler consumer cannot fire the same tick).
   Work that depends on each organization's local time (#33) is scheduled in UTC (e.g. hourly), and
   the fan-out job picks the organizations whose local time is due, with `BusinessDate` and the
   organization's time zone (ADR [0017](0017-business-dates-and-periods-as-value-objects.md)).
2. **Queued work is a *job*, on a fourth bus; commands stay synchronous.**
   - A job is a `final readonly` message with primitive fields implementing
     `App\Shared\Application\Job\JobInterface`, or `OrganizationJobInterface` (which adds
     `organizationId()`) when it works on one organization's data. Jobs live in
     `App\<Module>\Application\Job\<UseCase>\` as `<UseCase>Job` + `<UseCase>JobHandler`
     (`JobHandlerInterface`). A job handler orchestrates: it asks the query bus, calls ports (e.g. the
     KSeF client) and dispatches ordinary commands on the command bus, one aggregate each. It may
     change many aggregates over time; a command still changes one.
   - `JobBusInterface::dispatch($job, ?string $deduplicationKey = null)` wraps
     `messenger.bus.job`. Unlike the CQRS buses it keeps Messenger's default middleware (sending to a
     transport, deduplication, failure handling) plus `doctrine_ping_connection`,
     `doctrine_clear_entity_manager` and our tenant middleware (point 4). `JobInterface` is routed to
     the `jobs` transport. HTTP processors, console commands, event handlers and other job handlers
     queue jobs through it.
   - The command, query and event buses and ADR 0012's rule are unchanged: no command, query or
     event message is ever routed to a transport.
3. **The `jobs` transport is the PostgreSQL queue of ADR 0012**, in the same `messenger_messages`
   table with `queue_name=jobs` (`MESSENGER_JOBS_TRANSPORT_DSN`, `in-memory://` in tests). A job that
   throws is retried twice (after 5 and 15 minutes) and then moved to the existing `failed` transport
   (`messenger:failed:show`, `messenger:failed:retry`).
   - **Every job is idempotent and safe to interrupt at any point**: each unit of work is its own
     flush (one invoice, one shift), and progress is recorded after it (KSeF's cursor, ADR 0023 §4).
     A retry or a redelivery then continues where the last run stopped.
   - **Expected outcomes are not exceptions.** A 429, rejected credentials or an organization that no
     longer qualifies is recorded by the job (e.g. the KSeF sync status) and the handler returns
     normally. A failure that a retry cannot fix throws `UnrecoverableMessageHandlingException`, which
     goes straight to `failed`.
   - **Long jobs have a time budget** (KSeF: 45 minutes): the handler stops cleanly after it, and the
     next scheduled run continues.
4. **The tenant is switched by the job bus, not by each handler.** For an `OrganizationJobInterface`
   job, a middleware (`App\Shared\Infrastructure\Bus\Job\`) loads the organization, calls
   `TenantContextInterface::switchTo()` before the handler and `reset()` after it, also on failure. An
   organization that no longer exists makes the job unrecoverable. A job without an organization
   (a fan-out) sees no tenant data (ADR [0001](0001-multi-tenancy-shared-database-organization-filter.md))
   and only asks the query bus for ids of organizations, then queues one `OrganizationJobInterface` job
   each.
5. **Overlapping runs are prevented by locks in PostgreSQL**, with `symfony/lock`'s `DoctrineDbalStore`
   (table `lock_keys`, created by a migration; no new service):
   - **Deduplication at dispatch**: a job that must not be queued twice is dispatched with a key
     (`ksef-import:<organizationId>`), and Messenger's `DeduplicateStamp` drops a second one while the
     first is queued or running. "Import now" during a queued scheduled run, or a missed tick firing
     after a deploy, then adds nothing. The lock's TTL covers queue time plus the job's time budget.
   - **One `jobs` consumer**: jobs are processed one after another, so organizations never run in
     parallel and per-NIP limits are never shared (ADR 0023 §5). Scaling it up later needs a per-key
     lock in the handler first.
   - The scheduler's own lock (point 1).
   PostgreSQL advisory locks were not chosen because they belong to one database session, while a
   deduplication lock is taken by the HTTP request and released by the worker.
6. **Two worker processes from the API image.**
   - `worker` (ADR 0012) consumes `async scheduler_default`: e-mails and schedule ticks, both quick.
   - `jobs-worker` (new) consumes `jobs`, with `--keepalive` so that a job longer than
     `redeliver_timeout` is not redelivered while it runs. A crashed job is redelivered after the
     timeout (15 minutes).
   - The image gets the `pcntl` extension (for `--keepalive` and for finishing the current message on
     `SIGTERM`). `--time-limit` and `--memory-limit` are checked only between messages, so they never
     stop a job halfway.
   - Console commands in `Ui\Console\<Module>\` remain for manual runs, and **queue** the same job
     rather than running it in the console process; `messenger:consume jobs --limit=1` runs it in the
     foreground for debugging.
7. **Development, tests and CI.** Compose gets a `jobs-worker` service like `worker`;
   `make worker-restart` restarts both; `bin/console debug:scheduler` lists the schedule. In tests
   both queues are `in-memory://` and nothing consumes the scheduler: API tests assert that a job was
   queued (with its deduplication key), job handlers are unit-tested with in-memory ports and a
   recording command bus, the tenant middleware has a functional test, and the schedule has a unit
   test of its next run times (including a DST change in `Europe/Warsaw`). CI runs no worker and needs
   no change beyond the new tables in migrations.
8. **Production, whatever #35 chooses**: the platform must run two long-running processes from the
   API image (`worker` and `jobs-worker`, one instance each) with a restart policy, give them a stop
   grace period, and run `messenger:stop-workers` on deploy. No host crontab is needed. The jobs worker
   calls KSeF, so its outgoing traffic needs the one stable IP address ADR 0023 asks for.

## Alternatives considered

- **Cron calling console commands.** Familiar and needs no package, but the schedule then lives in a
  host or container crontab that depends on the hosting decision (#35), cron has no time zone per
  entry, a run longer than its interval overlaps unless we add locking, and the schedule cannot be
  unit-tested. Scheduler keeps the schedule in the code, and the console commands still exist for
  manual runs.
- **Routing chosen commands to a transport** (enable `send_message` on the command bus, route
  `ImportKsefInvoices` to `async`). Fewer concepts, but a command would then sometimes be
  asynchronous, return before it ran and change many aggregates, breaking ADR 0004's "one command
  changes one aggregate, the handler throws on failure". It would also supersede ADR 0012's rule. A
  separate kind of message makes "this runs later, on a worker" visible in its type.
- **Jobs in `Ui`** (next to console commands). Only the delivery layer could then queue a job;
  an event handler (e.g. "a new KSeF token was saved, import now") could not. In `Application` they
  follow the existing layer rules.
- **Each handler calling `switchTo()` itself**, as the console-command pattern says. Easy to forget,
  and a forgotten call silently sees no data. The middleware makes it impossible to forget.
- **One worker for everything.** Cheapest to host, but an hour-long KSeF run would delay password
  resets and invitations (ADR 0012) and the schedule's ticks.
- **A third, scheduler-only process.** More isolation, but the scheduler only enqueues and is as
  quick as sending an e-mail; the e-mail worker can carry it.
- **Running the job in the scheduler's consumer** (`#[AsCronTask]` on a service, or
  `RunCommandMessage` handled where the tick is received). Simple, but the tick consumer is then busy
  for the length of the run, and "Import now" would need another path anyway.
- **Redis for the queue or the locks.** A new service in compose, production and CI for a load that
  PostgreSQL handles, as ADR 0012 already concluded for e-mail.
- **A raised `redeliver_timeout` instead of `pcntl` and `--keepalive`.** No image change, but a
  crashed job would wait hours before it is redelivered, and a worker stopped by a deploy would be
  killed mid-message.

## Consequences

- **A third kind of message.** Developers choose between a command (synchronous, one aggregate),
  an event (a side effect of a write) and a job (later, on the worker, orchestrating). CLAUDE.md gets a
  Patterns row and module-structure entries for jobs (`Application/Job/`, `Ui/Scheduler/`,
  `Ui/Console/`), and the bus rule names the `jobs` path next to e-mail.
- **No more "fire and see the result".** "Import now" answers `202 Accepted`; the result shows up
  later in the job's own status (the KSeF sync status), and failures in the worker's log and the
  `failed` queue. Someone has to look at `messenger:failed:show` until there is monitoring.
- **Jobs are written to be interrupted.** A job that is not idempotent or does its work in one big
  flush is a bug, not a style issue; reviews check it.
- **New dependencies and tables**: `symfony/scheduler`, `symfony/lock`, the `pcntl` extension in the
  image, and migrations for `lock_keys` and the cache pool's table. The `jobs` rows share
  `messenger_messages` with e-mails, separated by `queue_name`.
- **Production runs two worker processes** besides the web process; #35 has to account for them in
  cost and process management. Both must be restarted on every deploy, since neither reloads code.
- **One jobs worker is a throughput limit.** Fine for a few organizations; KSeF's per-NIP limits are
  the real bottleneck. Running jobs in parallel later needs per-key locks in handlers and a new
  decision on KSeF's per-IP limits.
- **The runtime lands with its first job**, the KSeF import (#52): packages, configuration,
  migrations, the compose service and the tests above. This spike adds no code.
- ADR 0004 (async transports) and ADR 0012 (points 4–5, the worker) stay in force; once this ADR is
  accepted, their status lines point here as an addition, the way ADR 0004's points to ADR 0012.
