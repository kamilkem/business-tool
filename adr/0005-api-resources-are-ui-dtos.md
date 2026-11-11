# 0005. API resources and inputs are readonly DTOs in the Ui layer, never entities

Status: Accepted

Date: 2026-11-10

## Context

ADR [0004](0004-layered-modules-and-cqrs-buses.md) keeps entities free of framework concerns and
puts use cases behind buses. API Platform still has to describe the HTTP contract somewhere, and the
TypeScript clients are generated from that contract, so it must be explicit and stable.

## Decision

1. **Resources** (`src/Ui/Http/<Module>/<Name>Resource.php`) are `final readonly` classes that carry
   the `#[ApiResource]` declaration and a static `fromView()`. They are the response shape; there are
   no serialization groups. API Platform discovers resources only in `src/Ui`.
2. **Reads**: a Provider asks the query bus for a View and returns `Resource::fromView()`.
   **Writes**: a Processor dispatches a command (with an id it generated) and then reads the View back
   through the query bus. Providers and processors never touch repositories or entities.
3. **Inputs** (`<UseCase>Input`) carry all `#[Assert\*]` constraints; validation happens only there.
   They are `final readonly` with constructor parameters: no default means required in OpenAPI.
   Missing or mistyped fields come back as 422 (`collect_denormalization_errors: true`).
4. **PATCH** uses `application/merge-patch+json`: a provider loads the View into a mutable
   `Update<Name>Input::fromView()`, API Platform applies the body, and the processor runs as for any
   write.
5. **The contract is strict**: every resource property is required in OpenAPI
   (`RequiredPropertyMetadataFactory`) and nulls are always serialized (`skip_null_values: false`),
   so generated types have no optional fields that are in fact always present.

## Alternatives considered

- **`#[ApiResource]` on entities with serialization groups.** Couples the database model to the
  public contract and spreads the response shape over group annotations.
- **Symfony controllers with `#[MapRequestPayload]`.** Loses the generated OpenAPI, pagination and
  filters that API Platform provides.

## Consequences

- An entity can be refactored without changing the API, and the API can change without touching the
  entity.
- A field appears in the API only when it is added to the View and the Resource, which is deliberate
  but is two more edits per field.
- The OpenAPI document is precise enough to generate a TypeScript client without hand-written types
  (ADR [0006](0006-plain-json-and-generated-typescript-client.md)).
