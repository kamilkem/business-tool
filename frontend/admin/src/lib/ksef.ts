import type { components } from "@business-tool/api-client"

export type KsefConnection = components["schemas"]["KsefConnection"]

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
    return { label: "Not connected", tone: "muted", hint: "An owner saves a KSeF token to import purchase invoices." }
  }
  if (connection.status === "credentials_rejected") {
    return {
      label: "Token rejected",
      tone: "error",
      hint:
        connection.lastCheckError === "token_unreadable"
          ? "The saved token can no longer be read. An owner has to save it again."
          : "KSeF refused the token. An owner has to generate a new one in KSeF and save it.",
    }
  }
  if (connection.lastCheckError) {
    return { label: "Connected, last check failed", tone: "warning", hint: ksefErrorLabel(connection.lastCheckError) }
  }
  if (!connection.verifiedAt) {
    return { label: "Token saved, not checked yet", tone: "warning", hint: "Test the connection to check it." }
  }
  return { label: "Connected", tone: "ok", hint: null }
}

/** A failed check or import, in words. */
export function ksefErrorLabel(code: string): string {
  switch (code) {
    case "credentials_rejected":
      return "KSeF refused the token."
    case "token_unreadable":
      return "The saved token can no longer be read."
    case "rate_limited":
      return "KSeF asked us to slow down. Try again later."
    case "unavailable":
      return "KSeF could not be reached. Try again later."
    case "request_failed":
      return "KSeF answered in an unexpected way."
    case "invoice_unreadable":
      return "Some invoices could not be read and were skipped."
    default:
      return code
  }
}
