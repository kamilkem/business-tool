# 0000. We record architecture decisions as ADRs

Status: Accepted

Date: 2026-11-10

## Context

The project starts as a back office for one bar but is built to serve many small hospitality
businesses. Decisions are made in conversations, in chats with Claude Code, or ad hoc during
implementation. Without a written history, later work (by people or by Claude) risks re-proposing
rejected approaches, "fixing" patterns that are unusual on purpose, and losing the reason something
was done a certain way. `CLAUDE.md` says *how* to work in the repo; it is not the place for *why*.

## Decision

We use Architecture Decision Records in Michael Nygard's lightweight format (`adr/template.md`):
number, title, status, date, context, decision, alternatives, consequences. One Markdown file per
decision in `adr/` at the repository root, named `adr/NNNN-decision-title.md`, numbered sequentially.

1. **What gets an ADR.** Any decision with real architectural consequences: choosing a technology or
   library that shapes the code, an integration pattern (e.g. KSeF), module structure, a new row in
   the patterns table of `CLAUDE.md`, a tenancy or security boundary, CI or branching model, and a
   deliberate rejection of an obvious alternative. Bug fixes, refactors inside existing patterns and
   feature work that follows the documented patterns do not need one.
2. **Numbering.** Next free number, no gaps, never reused. A number is a permanent identifier: files
   are not renamed, renumbered or deleted.
3. **Status.** `Proposed` while under discussion, `Accepted` once it applies, `Deprecated` when it no
   longer applies and nothing replaces it, `Superseded by [NNNN](...)` when a later ADR replaces it.
   A partial change is noted next to the status (e.g. `Accepted (point 3 superseded by [0012](...))`).
4. **Accepted ADRs are not rewritten.** After acceptance only the `Status:` line and pointers (paths,
   section names) may change. A changed decision is a new ADR that supersedes the old one.
5. **Index.** Every ADR has a row in `adr/README.md` with the same status as the file.
6. **Check before proposing.** Before proposing anything structural, check `adr/` for a decision
   already made or rejected in that area. Do not re-propose a rejected approach unless its context
   has changed; then write a new ADR that supersedes the old one.
7. **Same change.** An ADR lands in the same pull request as the code that applies it, or before it.
   If the decision changes a rule in `CLAUDE.md`, the rule is updated in the same change and points to
   the ADR.

`.github/scripts/check-adr-hygiene.sh` (`make adr-check`, and CI on every change under `adr/`)
enforces rules 2, 3 and 5 and the template headings of newly added ADRs.

## Consequences

- A few paragraphs of overhead per significant decision, in exchange for a decision history that can
  be read without digging through chat logs.
- ADRs 0001–0010 were written retroactively for the decisions applied in the initial scaffold.
- `CLAUDE.md` keeps the rules short and links to the ADR for the reasoning.
