# 0032. A register is deleted only while no revenue entry refers to it; one with entries is deactivated

Status: Accepted

Date: 2026-11-22

## Context

ADR [0022](0022-income-daily-report-per-register.md) §2 made registers "deactivated, never deleted,
because entries refer to them". A register added by mistake, or a test one, then stays in the
deactivated list for good, and its name stays taken.

ADR [0030](0030-payment-methods-per-organization.md) §4 settled the same question for payment
methods: the product owner chose "delete if unused, otherwise deactivate" (#110), and rejected
"deactivate only, never delete" because a method added by mistake would stay forever. #175 asks for
the same rule for registers. The reason for "never delete" in ADR 0022 is history, as for members
(ADR [0014](0014-deactivate-members-never-delete.md)): a register without entries has none to keep.

## Decision

1. **A register no revenue entry refers to can be deleted**, active or deactivated: `DELETE
   /registers/{id}` answers 204. Owners and managers only, as for the other register endpoints (403
   otherwise); another organization's register is a 404.
2. **A register with any entry, of any date, cannot be deleted**: 409 with the stable code
   `income.register_in_use` (parameters `registerId`, `name`). It is deactivated instead, as today.
3. **The check is the model's, the foreign key backs it.** `Register::assertDeletable()` takes
   whether an entry refers to the register (`RevenueEntryRepositoryInterface::usesRegister()`). The
   foreign key of `revenue_entry.register_id` has no `ON DELETE`, so an entry recorded in the
   meantime makes the delete fail, and the repository reports that as the same 409.
4. **The API says whether a register is `used`**, so that clients offer the delete only when it can
   succeed. The admin's Registers page shows "No entries yet" and a Delete action, with a
   confirmation, for such registers only.

The rest of ADR 0022 §2 stands: names are unique, deactivated registers included; a deactivated
register takes no new entries and its entries stay visible and editable; registers are not
reactivated.

## Alternatives considered

- **Keep "deactivate only".** Rejected for the same reason as for payment methods (ADR 0030): a
  register added by mistake would stay in the list, with its name taken, forever. Deleting one
  without entries loses nothing.
- **Delete a register together with its entries (`ON DELETE CASCADE`).** It would lose income
  history with one click. Entries are deleted one by one (ADR 0022 §8); once the last one is gone,
  the register can be deleted.
- **Soft delete (hide the register, keep the row).** That is what deactivation already is.

## Consequences

- This ADR supersedes the "never deleted" part of ADR 0022 §2. The rest of ADR 0022 stands (point 4
  is superseded by ADR 0030).
- Registers and payment methods follow one rule: delete while unused, otherwise deactivate.
- `used` follows the entries, so the admin refreshes the registers whenever an entry is recorded,
  changed or deleted.
- A deleted register leaves no trace. Accepted: it had no entries.
