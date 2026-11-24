import type { components } from "@business-tool/api-client"

import { i18n } from "../i18n"

export type KsefConnection = components["schemas"]["KsefConnection"]

/** The earliest date the import may start at: KSeF API 2.0 became mandatory (ADR 0038 §4). */
export const KSEF_EARLIEST_SYNC_START_DATE = "2026-02-01"

/** KSeF exists only for organizations in Poland (ADR 0002 §3, ADR 0023 §3). */
export function ksefAvailableIn(countryCode: string): boolean {
  return countryCode.toUpperCase() === "PL"
}

export type KsefState = {
  label: string
  tone: "ok" | "warning" | "error" | "muted"
  /** What the owner should do next, if anything. */
  hint: string | null
}

/** How the connection reads in the admin. Null means no token was ever saved. */
export function ksefState(connection: KsefConnection | null): KsefState {
  if (!connection || connection.status === "disabled") {
    return { label: i18n.t("ksef.state.notConnected", { ns: "admin" }), tone: "muted", hint: i18n.t("ksef.hint.notConnected", { ns: "admin" }) }
  }
  if (connection.status === "credentials_rejected") {
    return {
      label: i18n.t("ksef.state.tokenRejected", { ns: "admin" }),
      tone: "error",
      hint:
        connection.lastCheckError === "token_unreadable"
          ? i18n.t("ksef.hint.tokenUnreadable", { ns: "admin" })
          : i18n.t("ksef.hint.tokenRejected", { ns: "admin" }),
    }
  }
  if (connection.lastCheckError) {
    return { label: i18n.t("ksef.state.checkFailed", { ns: "admin" }), tone: "warning", hint: ksefErrorLabel(connection.lastCheckError) }
  }
  if (!connection.verifiedAt) {
    return { label: i18n.t("ksef.state.notChecked", { ns: "admin" }), tone: "warning", hint: i18n.t("ksef.hint.notChecked", { ns: "admin" }) }
  }
  return { label: i18n.t("ksef.state.connected", { ns: "admin" }), tone: "ok", hint: null }
}

/** A failed check or import, in words. */
export function ksefErrorLabel(code: string): string {
  switch (code) {
    case "credentials_rejected":
    case "token_unreadable":
    case "rate_limited":
    case "unavailable":
    case "request_failed":
    case "invoice_unreadable":
      return i18n.t(`ksef.error.${code}`, { ns: "admin" })
    default:
      return code
  }
}
