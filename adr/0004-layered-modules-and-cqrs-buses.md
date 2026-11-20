# 0004. Backend layout: modules per business context, Domain / Application / Infrastructure + Ui, command and query buses

Status: Accepted (async transport for e-mail added by [0012](0012-transactional-email-smtp-async-queue.md), jobs bus and `jobs` transport added by [0025](0025-background-jobs-scheduler-and-job-bus.md))

Date: 2026-11-10

## Context

The backend will grow several business contexts (organizations, users, expenses, income, time
tracking, payroll) with real rules: who may change settings, how a pay period closes, how a KSeF
invoice becomes an expense. Without a fixed shape, rules drift into controllers, API Platform state
providers or Doctrine listeners, and each module ends up structured differently.

## Decision

1. **One module per business context** under `src/App/<Module>/`, each with `Domain/` (entities,
   value objects, enums, repository ports, exceptions), `Application/` (commands, queries, handlers,
   Views) and `Infrastructure/` (Doctrine adapters, external clients). The delivery layer lives
   outside the modules in `src/Ui/` (HTTP resources, inputs, providers, processors; console
   commands; fixtures). PSR-4: `App\` → `src/App`, `Ui\` → `src/Ui`.
2. **Command and query buses** on Symfony Messenger: `messenger.bus.command`, `messenger.bus.query`
   and `messenger.bus.event`, wrapped by `CommandBusInterface`, `QueryBusInterface` and
   `EventPublisherInterface` in `App\Shared`. Handlers register through `_instanceof` tags.
3. **Synchronous and without default middleware.** The buses run in process with only
   `handle_message`: validation happens at the API boundary (Inputs), and repositories flush in
   `add()`/`save()`. No transaction middleware.
4. **One command changes one aggregate root.** Command handlers return nothing and throw domain
   exceptions; query handlers return Views or `null`, never entities. Side effects of a write go
   through domain events.
5. **Rules live in the model.** Entities have constructors with required data and named methods
   instead of setters; permission rules that depend on domain state (e.g. only owners and managers
   change settings) throw domain exceptions from the model. Domain exceptions map to HTTP statuses in
   `api_platform.yaml` `exception_to_status`.
6. **Layer dependencies are enforced** by Deptrac (`api/deptrac.yaml`, `make deptrac`, part of
   `make qa`): Domain depends on nothing outside Domain; Application uses ports and other modules only
   through the buses; Ui never touches Infrastructure. `EntityManagerInterface` appears only in
   Infrastructure.

The detailed rules and the patterns table live in `CLAUDE.md`; `App\Organization` + `Ui\Http\Organization`
is the reference module.

## Alternatives considered

- **API Platform directly on Doctrine entities** (the framework default). Fastest for CRUD, but the
  entity becomes the API contract, rules move into state processors and serialization groups, and
  every schema change is an API change.
- **Plain Symfony services instead of buses.** Fewer indirections, but nothing stops a controller from
  calling three services and a repository; the bus makes "one use case = one message" the path of
  least resistance and gives one place for cross-cutting concerns later.
- **Async transport now.** Nothing needs it yet; it would add a worker to run and failure modes to
  handle. Adding a transport later is a change of routing, not of handlers.

## Consequences

- More files per feature (command, handler, query, View, resource, input, provider, processor) than a
  CRUD-on-entities approach. The reference module and `CLAUDE.md` keep this mechanical.
- Handlers are unit-testable with in-memory repositories, without a container or database.
- Because the buses have no transaction middleware, a handler that needs several writes to be atomic
  has to do so inside one aggregate or ask for an explicit decision.
