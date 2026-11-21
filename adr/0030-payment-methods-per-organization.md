# 0030. Payment methods are a list each organization keeps: added, renamed, ordered, deactivated, and deleted only while unused

Status: Accepted

Date: 2026-11-22

## Context

ADR [0022](0022-income-daily-report-per-register.md) §4 fixed the payment methods of a revenue entry
to an enum, `cash`, `card`, `transfer`, `voucher`, for v1. It stored them as lines (method, amount),
not as columns, so that a per-organization list could replace the enum later. #110 is that change:
real venues take payments the enum does not have, such as meal vouchers, BLIK or delivery platforms,
and they name them their own way.

Constraints:

- Nothing country-specific without an organization setting (ADR
  [0002](0002-locale-specifics-behind-organization-settings.md)).
- Existing entries and the totals the summaries and the monthly business summary (ADR
  [0029](0029-reporting-module-composes-module-queries.md)) show must not change.
- History refers to a method, as it refers to a register or a member, so a method that was used cannot
  simply disappear (ADR [0014](0014-deactivate-members-never-delete.md), ADR 0022 §2).
- Error codes and translations as in ADR [0028](0028-ui-language-per-user-with-i18next.md).

The product owner decided the open questions on 2026-11-21, through the session that coordinates
the issues.

## Decision

1. **`PaymentMethod` is a tenant-aware aggregate of `App\Income`** with a name, a position, and an
   optional deactivation moment. A name is trimmed, 1 to 64 characters, and unique in the
   organization, deactivated methods included, compared as written (as register names are).
   Owners and managers manage the list. Another role gets 403, and a method of another organization
   is a 404.
2. **The initial list is the four former enum values, named by the organization's country**
   (`DefaultPaymentMethods`): `Gotówka`, `Karta`, `Przelew`, `Bon` in Poland, and `Cash`, `Card`,
   `Transfer`, `Voucher` in every other country, until a country gets a list of its own. A new
   organization gets them from a handler of `OrganizationCreated`, in that order and as its owner,
   as it gets its expense categories. Existing organizations get them from the migration, which
   then points every existing payment line at the method that replaces its enum value. No amount
   changes.
3. **The organization orders its methods.** A new or reactivated method goes to the end. `PUT
   /payment-methods/order` takes the ids of exactly the active methods, each once, in the new order.
   Any other set is a 409 (`income.payment_method_order_mismatch`), because the list changed since
   the client read it. Deactivated methods keep their position and are listed after the active
   ones. The order is the list's, not one method's: `PaymentMethodOrder::apply()` moves several
   aggregates, which the repository saves in one flush. That is a deliberate exception to "one
   command, one aggregate".
4. **A method is deactivated and reactivated, and deleted only while no revenue line uses it.**
   - A deactivated method takes no new lines (422 `income.invalid_payment_lines.method_deactivated`).
   - An entry that already has a line for a deactivated method keeps it, shows it, and may keep it
     when it is edited, as an entry of a deactivated register stays editable.
   - Deleting a method that a line uses is a 409 (`income.payment_method_in_use`). The foreign key
     of the lines has no `ON DELETE`, so it also refuses a line recorded in the meantime. The API
     tells clients whether a method is `used`.
5. **Payment lines refer to the method by id.** A request line is `{paymentMethodId, amount}`, a
   response line `{paymentMethodId, paymentMethodName, amount}` with the method's current name. The
   primary key of a line becomes (entry, method). Lines follow the organization's order, with
   deactivated methods last. This replaces `{method, amount}` without a compatibility period: the
   admin is the only client.
6. **Summaries total per method id, in the organization's order, with current names**, deactivated
   methods last. The totals per currency, VAT rate and register, and the business summary, are
   unchanged.

## Alternatives considered

- **Keep the enum and add free-text "other" lines.** This needs no new aggregate, but it gives no
  stable key to total by: "Bon" and "bon" would be two methods. It also lets no venue rename or hide
  the fixed four.
- **Default methods the same everywhere, in English.** This is simpler, but the first customer's
  staff would see English names in a Polish admin. Expense categories already took names by country
  (#48).
- **Deactivate only, never delete.** This is what registers do. It was rejected because a method
  added by mistake would then stay in the list forever. Deleting an unused method loses nothing.
- **Fixed order (by name, or by creation).** This is simpler, but the entry form should list the
  methods a venue uses most first, and that differs per venue.
- **A per-method "move up/down" endpoint, or a position in PATCH.** One request per step and no check
  that the client saw the current list. A whole new order in one request is atomic and catches a
  stale list.
- **Keep accepting `method: "cash"` next to `paymentMethodId` for a while.** No external client
  exists, so a shim would only add code to remove later.

## Consequences

- This ADR supersedes ADR 0022 §4. The rest of ADR 0022 stands.
- The migration is not reversible. Methods added, renamed or reordered have no enum value to go back
  to, so its `down()` throws.
- The admin's entry form builds its rows from the active methods, in order, and keeps a deactivated
  method's row only for an entry that has it. Its Income section gets a "Payment methods" page to
  manage the list. The names are user data: they are shown as entered and not translated.
- An organization could deactivate every method and so take no new entries until it adds or
  reactivates one. That is accepted: the admin shows it.
- A future cash-up or end-of-shift entry (#111) and month close (#112) refer to methods by id too.
