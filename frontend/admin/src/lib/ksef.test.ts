import { describe, expect, it } from "vitest"

import { type KsefConnection, ksefAvailableIn, ksefErrorLabel, ksefState } from "./ksef"

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
    syncedUntil: null,
    lastSyncAt: null,
    lastSyncSucceededAt: null,
    lastSyncError: null,
    lastSyncErrorMessage: null,
    lastSyncImportedCount: 0,
    lastSyncSkippedCount: 0,
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
  it("test_it_shows_an_unknown_code_as_is", () => {
    expect(ksefErrorLabel("rate_limited")).toContain("slow down")
    expect(ksefErrorLabel("something_new")).toBe("something_new")
  })
})
