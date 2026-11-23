import { useQuery } from "@tanstack/react-query"
import { useTranslation } from "react-i18next"

import { useLocale } from "@business-tool/ui/hooks/use-locale"
import { useDateTimeFormat } from "@business-tool/ui/hooks/use-organization-time-zone"
import { type MinorUnits, formatMoney } from "@business-tool/ui/lib/money"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"

import { type ChangeLine, type RevenueEntryChange, changeKindKey, changeLines, vatRateLabel } from "@/lib/income"
import { revenueEntryChangesQuery } from "@/lib/queries"

/** Who made a change: a name, or null for the signed-in user. */
type NameOf = (userId: string) => string | null

/** The change history of one entry, newest first, in its dialog (ADR 0034 §4). */
export function RevenueEntryHistory({ organizationId, entryId, nameOf }: { organizationId: string; entryId: string; nameOf: NameOf }) {
  const { t } = useTranslation("admin")
  const { data, error, isPending } = useQuery(revenueEntryChangesQuery(organizationId, { entryId }))

  return (
    <section aria-labelledby="entry-history" className="flex flex-col gap-2 border-t pt-4">
      <h3 id="entry-history" className="text-sm font-medium">
        {t("income.history.title")}
      </h3>
      {isPending ? (
        <p className="text-sm text-muted-foreground">{t("income.history.loading")}</p>
      ) : error ? (
        <p className="text-sm text-destructive">{requestErrorMessage(error)}</p>
      ) : data.length === 0 ? (
        <p className="text-sm text-muted-foreground">{t("income.history.none")}</p>
      ) : (
        <ol className="flex flex-col gap-3">
          {data.map((change) => (
            <RevenueChangeItem key={change.id} change={change} nameOf={nameOf} />
          ))}
        </ol>
      )}
    </section>
  )
}

/** One change: what made it, who and when, and what it changed line by line. */
export function RevenueChangeItem({ change, nameOf, showEntry = false }: { change: RevenueEntryChange; nameOf: NameOf; showEntry?: boolean }) {
  const { t } = useTranslation("admin")
  const format = useDateTimeFormat()
  const locale = useLocale()
  const money = (minor: MinorUnits) => formatMoney(minor, change.currency, locale)
  const name = nameOf(change.changedBy) ?? t("income.history.you")
  const lines = changeLines(change)
  // A created or deleted entry shows its values; a change shows before → after.
  const oneSided = change.before === null || change.after === null

  function value(line: ChangeLine, side: "before" | "after"): string {
    switch (line.kind) {
      case "payment":
      case "total": {
        const amount = line[side]
        return amount === null ? "—" : money(amount)
      }
      case "vat": {
        const vat = line[side]
        return vat === null ? "—" : t("income.history.vatValue", { gross: money(vat.gross), vat: money(vat.vat) })
      }
      case "note": {
        const note = line[side]
        return note === null ? "—" : `“${note}”`
      }
    }
  }

  function label(line: ChangeLine): string {
    switch (line.kind) {
      case "payment":
        return line.name
      case "total":
        return t("income.total")
      case "vat":
        return t("income.history.vatAt", { rate: vatRateLabel(line.rate) })
      case "note":
        return t("time.shifts.note")
    }
  }

  return (
    <li className="flex flex-col gap-1 rounded-md border px-3 py-2 text-sm">
      <div className="flex flex-wrap items-baseline justify-between gap-x-3">
        <span className="font-medium">{t(`income.history.kind.${changeKindKey(change)}`, { name })}</span>
        <time dateTime={change.changedAt} className="text-xs text-muted-foreground tabular-nums">
          {format.dateTime(change.changedAt)}
        </time>
      </div>
      {showEntry && (
        <span className="text-muted-foreground">
          {change.registerName}, {format.businessDate(change.businessDate)}
        </span>
      )}
      {lines.length > 0 && (
        <ul className="flex flex-col gap-0.5 text-muted-foreground">
          {lines.map((line) => (
            <li key={line.kind === "payment" ? `payment-${line.name}` : line.kind === "vat" ? `vat-${line.rate}` : line.kind} className="tabular-nums">
              <span className="text-foreground">{label(line)}:</span>{" "}
              {oneSided ? (
                value(line, change.after === null ? "before" : "after")
              ) : (
                <>
                  {value(line, "before")} → <span className="text-foreground">{value(line, "after")}</span>
                </>
              )}
            </li>
          ))}
        </ul>
      )}
    </li>
  )
}
