import { afterEach, beforeEach, describe, expect, it } from "vitest"

import { i18n } from "../i18n"
import { type KsefConnection, ksefAvailableIn, ksefBacklogLabel, ksefErrorLabel, ksefImportMethodLabel, ksefState } from "./ksef"

function connection(overrides: Partial<KsefConnection> = {}): KsefConnection {
  return {
    id: "connection-1",
    status: "active",
    tokenSet: true,
    tokenSetAt: "2026-11-13T10:00:00+00:00",
    nip: "1111111111",
    verifiedAt: "2026-11-13T10:00:00+00:00",
    lastCheckedAt: "2026-11-13T10:00:00+00:00",
    lastCheckError: null,
    lastCheckErrorMessage: null,
    syncStartDate: null,
    defaultSyncStartDate: "2026-10-01",
    syncedUntil: null,
    lastSyncAt: null,
    lastSyncSucceededAt: null,
    lastSyncError: null,
    lastSyncErrorMessage: null,
    lastSyncImportedCount: 0,
    lastSyncSkippedCount: 0,
    syncRetryNotBefore: null,
    lastImportRequestedAt: null,
    lastImportMethod: null,
    importBacklogCount: null,
    importBacklogIsMinimum: false,
    exportPending: false,
    importEstimatedAt: null,
    createdAt: "2026-11-13T10:00:00+00:00",
    ...overrides,
  }
}

describe("ksefAvailableIn", () => {
  it("test_it_offers_ksef_in_poland_only", () => {
    expect(ksefAvailableIn("PL")).toBe(true)
    expect(ksefAvailableIn("pl")).toBe(true)
    expect(ksefAvailableIn("DE")).toBe(false)
  })
})

describe("ksefState", () => {
  it("test_it_reads_no_connection_or_a_removed_token_as_not_connected", () => {
    expect(ksefState(null).label).toBe("Not connected")
    expect(ksefState(connection({ status: "disabled", tokenSet: false })).label).toBe("Not connected")
  })

  it("test_it_reads_a_verified_token_as_connected", () => {
    expect(ksefState(connection())).toEqual({ label: "Connected", tone: "ok", hint: null })
  })

  it("test_it_asks_for_a_new_token_when_ksef_refused_it_or_it_cannot_be_read", () => {
    const refused = ksefState(connection({ status: "credentials_rejected", lastCheckError: "credentials_rejected" }))
    const unreadable = ksefState(connection({ status: "credentials_rejected", lastCheckError: "token_unreadable" }))

    expect(refused.tone).toBe("error")
    expect(refused.hint).toContain("generate a new one")
    expect(unreadable.hint).toContain("save it again")
  })

  it("test_it_warns_about_a_failed_check_that_keeps_the_token", () => {
    expect(ksefState(connection({ lastCheckError: "unavailable" }))).toEqual({
      label: "Connected, last check failed",
      tone: "warning",
      hint: "KSeF could not be reached. Try again later.",
    })
  })

  it("test_it_warns_about_a_token_not_checked_yet", () => {
    expect(ksefState(connection({ verifiedAt: null, lastCheckedAt: null })).tone).toBe("warning")
  })
})

describe("ksefErrorLabel", () => {
  it("test_it_explains_skipped_unreadable_invoices", () => {
    expect(ksefErrorLabel("invoice_unreadable")).toBe("Some invoices could not be read and were skipped.")
  })


  it("test_it_shows_an_unknown_code_as_is", () => {
    expect(ksefErrorLabel("rate_limited")).toContain("slow down")
    expect(ksefErrorLabel("something_new")).toBe("something_new")
  })
})

describe("in Polish", () => {
  beforeEach(async () => {
    await i18n.changeLanguage("pl")
  })
  afterEach(async () => {
    await i18n.changeLanguage("en")
  })

  it("test_it_reads_the_state_and_the_failure_in_polish", () => {
    expect(ksefState(null).label).toBe("Niepołączony")
    expect(ksefState(connection({ lastCheckError: "unavailable" }))).toEqual({
      label: "Połączony, ostatnie sprawdzenie nie powiodło się",
      tone: "warning",
      hint: "Nie udało się połączyć z KSeF. Spróbuj ponownie później.",
    })
  })

  it("test_it_keeps_an_unknown_code_as_it_is", () => {
    expect(ksefErrorLabel("something_new")).toBe("something_new")
  })
})

describe("ksefBacklogLabel", () => {
  it("test_it_says_nothing_when_nothing_is_known_to_be_waiting", () => {
    expect(ksefBacklogLabel(connection())).toBeNull()
    expect(ksefBacklogLabel(connection({ importBacklogCount: 0 }))).toBeNull()
  })

  it("test_it_counts_the_invoices_left_exactly_or_at_least", () => {
    expect(ksefBacklogLabel(connection({ importBacklogCount: 1 }))).toBe("1 invoice")
    expect(ksefBacklogLabel(connection({ importBacklogCount: 120, importBacklogIsMinimum: true }))).toBe("At least 120 invoices")
  })

  it("test_it_says_ksef_is_preparing_a_package", () => {
    expect(ksefBacklogLabel(connection({ exportPending: true, importBacklogCount: 5 }))).toBe(
      "KSeF is preparing a package of invoices; the next import picks it up.",
    )
  })

  it("test_it_uses_polish_plural_forms", async () => {
    await i18n.changeLanguage("pl")
    try {
      expect(ksefBacklogLabel(connection({ importBacklogCount: 3 }))).toBe("3 faktury")
      expect(ksefBacklogLabel(connection({ importBacklogCount: 25, importBacklogIsMinimum: true }))).toBe("Co najmniej 25 faktur")
    } finally {
      await i18n.changeLanguage("en")
    }
  })
})

describe("ksefImportMethodLabel", () => {
  it("test_it_names_the_import_method", () => {
    expect(ksefImportMethodLabel("single")).toBe("One invoice at a time")
    expect(ksefImportMethodLabel("export")).toBe("Package export (more than 64 invoices waiting)")
    expect(ksefImportMethodLabel(null)).toBeNull()
  })
})
