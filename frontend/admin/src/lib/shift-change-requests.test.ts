import { ApiError } from "@business-tool/api-client"
import { createDateTimeFormat } from "@business-tool/ui/lib/date-time"
import { describe, expect, it } from "vitest"

import {
  type ShiftChangeRequest,
  changeSummary,
  changedEnds,
  decisionError,
  filterOf,
  rejectBody,
  timeRange,
  validateRequestsSearch,
} from "./shift-change-requests"

const format = createDateTimeFormat("Europe/Warsaw", "en-GB")
const ANNA = "0193a000-0000-7000-8000-000000000001"
const REQUEST_ID = "0193a000-0000-7000-8000-0000000000cc"
const SHIFT_ID = "0193a000-0000-7000-8000-0000000000aa"

function request(overrides: Partial<ShiftChangeRequest> = {}): ShiftChangeRequest {
  return {
    id: REQUEST_ID,
    userId: ANNA,
    firstName: "Anna",
    lastName: "Nowak",
    kind: "new_times",
    shiftId: SHIFT_ID,
    startedAt: "2026-11-10T21:00:00+00:00",
    endedAt: "2026-11-11T03:00:00+00:00",
    note: "I stayed to close",
    status: "pending",
    submittedAt: "2026-11-11T09:00:00+00:00",
    decidedBy: null,
    decidedAt: null,
    rejectionReason: null,
    shiftStartedAt: "2026-11-10T21:00:37+00:00",
    shiftEndedAt: "2026-11-11T01:00:00+00:00",
    ...overrides,
  }
}

const problem = (status: number, detail: string) => new ApiError(status, { detail })

describe("validateRequestsSearch", () => {
  it("test_it_keeps_a_known_status_other_than_pending", () => {
    expect(validateRequestsSearch({ status: "approved" })).toEqual({ status: "approved" })
    expect(validateRequestsSearch({ status: "rejected" })).toEqual({ status: "rejected" })
    expect(validateRequestsSearch({ status: "all" })).toEqual({ status: "all" })
  })

  it("test_it_drops_pending_and_unknown_statuses_explicitly", () => {
    expect(validateRequestsSearch({ status: "pending" })).toEqual({ status: undefined })
    expect(validateRequestsSearch({ status: "nonsense" })).toEqual({ status: undefined })
    expect(validateRequestsSearch({})).toHaveProperty("status", undefined)
  })

  it("test_it_defaults_the_filter_to_pending", () => {
    expect(filterOf({})).toBe("pending")
    expect(filterOf({ status: "all" })).toBe("all")
  })
})

describe("rejectBody", () => {
  it("test_it_sends_a_trimmed_reason_or_none", () => {
    expect(rejectBody("  Not what the rota says ")).toEqual({ reason: "Not what the rota says" })
    expect(rejectBody("   ")).toEqual({})
  })
})

describe("timeRange", () => {
  it("test_it_shows_times_in_the_organization_zone_with_a_later_end_date", () => {
    expect(timeRange("2026-11-10T21:00:00+00:00", "2026-11-11T03:00:00+00:00", format)).toBe(
      "Tue 10 Nov, 22:00 – 04:00 (11 Nov)",
    )
  })

  it("test_it_leaves_out_the_end_date_on_the_same_day", () => {
    expect(timeRange("2026-11-10T08:00:00Z", "2026-11-10T16:30:00Z", format)).toBe("Tue 10 Nov, 09:00 – 17:30")
  })

  it("test_it_shows_an_open_shift", () => {
    expect(timeRange("2026-11-10T21:00:00Z", null, format)).toBe("Tue 10 Nov, 22:00 – open")
  })

  it("test_it_leaves_out_the_start_date_the_row_already_shows", () => {
    const [start, end] = ["2026-11-10T21:00:00Z", "2026-11-11T03:00:00Z"]
    expect(timeRange(start, end, format, "2026-11-10")).toBe("22:00 – 04:00 (11 Nov)")
    expect(timeRange(start, end, format, "2026-11-09")).toBe("Tue 10 Nov, 22:00 – 04:00 (11 Nov)")
  })

  it("test_it_uses_the_local_date_not_the_utc_one", () => {
    // 23:30 UTC on 10 Nov is already 11 Nov in Warsaw.
    expect(timeRange("2026-11-10T23:30:00Z", "2026-11-11T05:00:00Z", format)).toBe("Wed 11 Nov, 00:30 – 06:00")
  })
})

describe("changedEnds", () => {
  it("test_it_marks_the_ends_that_change_to_the_minute_whatever_the_offset", () => {
    expect(changedEnds(request({ startedAt: "2026-11-10T22:00:37+01:00" }))).toEqual({ start: false, end: true })
  })

  it("test_it_marks_both_ends_of_a_missing_shift", () => {
    expect(
      changedEnds(request({ kind: "missing_shift", shiftId: null, shiftStartedAt: null, shiftEndedAt: null })),
    ).toEqual({ start: true, end: true })
  })

  it("test_it_marks_closing_an_open_shift", () => {
    expect(changedEnds(request({ shiftEndedAt: null, startedAt: "2026-11-10T21:00:37Z" }))).toEqual({
      start: false,
      end: true,
    })
  })
})

describe("decisionError", () => {
  const names = { [ANNA]: "Anna Nowak" }
  const coded = (status: number, code: string, parameters: Record<string, unknown> = {}) =>
    new ApiError(status, { detail: "English detail.", code, parameters })

  it("test_it_explains_an_overlap_in_the_organization_zone_and_keeps_the_request_pending", () => {
    const error = coded(409, "time_tracking.shift_overlap", {
      otherShiftId: SHIFT_ID,
      otherStartedAt: "2026-11-10T21:00:00+00:00",
      otherEndedAt: "2026-11-11T01:00:00+00:00",
    })
    expect(decisionError(error, format, { names })).toEqual({
      message: "The shift would overlap another shift, from 10 Nov 2026, 22:00 to 11 Nov 2026, 02:00.",
      stillPending: true,
    })
  })

  it("test_it_keeps_the_request_pending_after_a_shift_rule_422", () => {
    const error = coded(422, "time_tracking.shift_cannot_be_reopened", { shiftId: SHIFT_ID })
    expect(decisionError(error, format, { names })).toEqual({
      message: "This shift has ended: its end time can be changed but not removed.",
      stillPending: true,
    })
  })

  it("test_it_reports_a_request_someone_else_already_decided", () => {
    const error = coded(409, "time_tracking.shift_change_request_already_decided", { requestId: REQUEST_ID, status: "rejected" })
    expect(decisionError(error, format, { names })).toEqual({
      message: "This request has already been decided.",
      stillPending: false,
    })
  })

  it("test_it_reports_a_request_that_is_gone", () => {
    const error = coded(404, "time_tracking.shift_change_request_not_found", { requestId: REQUEST_ID })
    expect(decisionError(error, format, { names })).toEqual({
      message: "This request no longer exists.",
      stillPending: false,
    })
  })

  it("test_it_falls_back_for_server_and_network_errors", () => {
    expect(decisionError(problem(500, "boom"), format, { names }).message).toMatch(/our side/)
    expect(decisionError(new TypeError("fetch failed"), format, { names }).message).toMatch(/connection/)
  })
})

describe("changeSummary", () => {
  it("test_it_says_what_a_request_changes", () => {
    expect(changeSummary(request())).toBe("Changes the clock-out")
    expect(changeSummary(request({ startedAt: "2026-11-10T20:00:00Z", endedAt: "2026-11-11T01:00:00Z" }))).toBe(
      "Changes the clock-in",
    )
    expect(changeSummary(request({ startedAt: "2026-11-10T20:00:00Z" }))).toBe("Changes the clock-in and the clock-out")
    expect(changeSummary(request({ kind: "missing_shift", shiftStartedAt: null, shiftEndedAt: null }))).toBe(
      "Enters a shift that was not clocked",
    )
  })

  it("test_it_tells_ending_an_open_shift_from_keeping_it_open", () => {
    const open = { startedAt: "2026-11-10T21:00:37Z", shiftEndedAt: null }
    expect(changeSummary(request(open))).toBe("Ends the open shift")
    expect(changeSummary(request({ startedAt: "2026-11-10T20:00:00Z", endedAt: null, shiftEndedAt: null }))).toBe(
      "Changes the clock-in",
    )
  })
})
