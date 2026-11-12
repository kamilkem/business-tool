import { createDateTimeFormat } from "@business-tool/ui/lib/date-time"
import { describe, expect, it } from "vitest"

import { type ShiftChangeRequest, previousTimes, requestTitle, requestWhen, timeRange } from "./shift-change-requests"

// The tests run with TZ=America/New_York (vitest.config.mts); the bar is in Warsaw.
const warsaw = createDateTimeFormat("Europe/Warsaw", "en-GB")

function request(overrides: Partial<ShiftChangeRequest> = {}): ShiftChangeRequest {
  return {
    id: "0193a1b2-2222-7000-8000-000000000001",
    userId: "0193a1b2-0000-7000-8000-000000000001",
    firstName: "Eve",
    lastName: "Wiśniewska",
    kind: "new_times",
    shiftId: "0193a1b2-1111-7000-8000-000000000001",
    startedAt: "2026-11-10T21:00:00+00:00",
    endedAt: "2026-11-11T03:00:00+00:00",
    note: "Closed late",
    status: "pending",
    submittedAt: "2026-11-11T09:15:00+00:00",
    decidedBy: null,
    decidedAt: null,
    rejectionReason: null,
    shiftStartedAt: "2026-11-10T21:00:00+00:00",
    shiftEndedAt: "2026-11-11T01:00:00+00:00",
    ...overrides,
  }
}

describe("time ranges", () => {
  it("test_it_shows_a_day_shift_on_the_organizations_clock", () => {
    expect(timeRange(warsaw, "2026-11-10T15:00:00Z", "2026-11-10T22:30:00Z")).toBe("Tue 10 Nov, 16:00 – 23:30")
  })

  it("test_it_shows_a_night_shift_without_the_next_days_name", () => {
    expect(timeRange(warsaw, "2026-11-10T21:00:00Z", "2026-11-11T03:00:00Z")).toBe("Tue 10 Nov, 22:00 – 04:00")
  })

  it("test_it_names_the_end_day_of_a_shift_longer_than_a_night", () => {
    expect(timeRange(warsaw, "2026-11-10T09:00:00Z", "2026-11-11T12:00:00Z")).toBe(
      "Tue 10 Nov, 10:00 – Wed 11 Nov 13:00",
    )
  })

  it("test_it_shows_an_open_shift_as_open", () => {
    expect(timeRange(warsaw, "2026-11-10T21:00:00Z", null)).toBe("Tue 10 Nov, 22:00 – open")
  })
})

describe("requests", () => {
  it("test_it_names_the_kind_of_request", () => {
    expect(requestTitle(request())).toBe("New times")
    expect(requestTitle(request({ kind: "missing_shift", shiftId: null }))).toBe("Missing shift")
  })

  it("test_it_shows_the_shifts_times_now_while_new_times_wait", () => {
    expect(previousTimes(warsaw, request())).toBe("Now Tue 10 Nov, 22:00 – 02:00")
    expect(previousTimes(warsaw, request({ status: "rejected" }))).toBe("Now Tue 10 Nov, 22:00 – 02:00")
  })

  it("test_it_shows_no_times_now_for_a_missing_shift_or_once_approved", () => {
    expect(previousTimes(warsaw, request({ kind: "missing_shift", shiftStartedAt: null, shiftEndedAt: null }))).toBeNull()
    expect(previousTimes(warsaw, request({ status: "approved" }))).toBeNull()
  })

  it("test_it_says_when_it_was_sent_or_decided", () => {
    expect(requestWhen(warsaw, request())).toBe("Sent 11 Nov 2026, 10:15")
    expect(requestWhen(warsaw, request({ status: "approved", decidedAt: "2026-11-12T08:00:00+00:00" }))).toBe(
      "Approved 12 Nov 2026, 09:00",
    )
    expect(requestWhen(warsaw, request({ status: "rejected", decidedAt: "2026-11-12T08:00:00+00:00" }))).toBe(
      "Rejected 12 Nov 2026, 09:00",
    )
  })
})
