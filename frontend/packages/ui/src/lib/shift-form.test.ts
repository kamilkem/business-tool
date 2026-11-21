import { createDateTimeFormat } from "./date-time"
import { describe, expect, it } from "vitest"

import { i18n } from "../i18n/i18n"

import {
  addShiftBody,
  correctShiftBody,
  editShiftForm,
  emptyShiftForm,
  missingShiftRequestBody,
  newTimesRequestBody,
  repeatedOffsets,
  shiftFormFrom,
  suggestedEndDate,
} from "./shift-form"
import type { Shift } from "./shifts"

const WARSAW = "Europe/Warsaw"
const format = createDateTimeFormat(WARSAW, "en-GB")
const ANNA = "0193a000-0000-7000-8000-000000000001"
const SHIFT_ID = "0193a000-0000-7000-8000-0000000000aa"

function shift(overrides: Partial<Shift> = {}): Shift {
  return {
    id: SHIFT_ID,
    userId: ANNA,
    firstName: "Anna",
    lastName: "Nowak",
    date: "2026-11-10",
    startedAt: "2026-11-10T21:00:37+00:00",
    endedAt: "2026-11-11T02:30:12+00:00",
    durationMinutes: 329,
    note: null,
    source: "clock",
    correctedBy: null,
    correctedAt: null,
    ...overrides,
  }
}

const at = (date: string, time: string, occurrence: "first" | "second" = "first") => ({ date, time, occurrence })

describe("adding a shift", () => {
  it("test_it_sends_the_times_typed_in_the_organizations_zone_with_its_offset", () => {
    // The browser is in New York (vitest TZ); 22:00 is Warsaw time.
    const values = { userId: ANNA, start: at("2026-11-10", "22:00"), end: at("2026-11-11", "03:30"), note: " Late " }
    expect(addShiftBody(values, WARSAW, format)).toEqual({
      ok: true,
      body: {
        userId: ANNA,
        startedAt: "2026-11-10T22:00:00+01:00",
        endedAt: "2026-11-11T03:30:00+01:00",
        note: "Late",
      },
    })
  })

  it("test_it_leaves_out_an_empty_note", () => {
    const values = { ...emptyShiftForm("2026-11-10", ANNA), start: at("2026-11-10", "10:00"), end: at("2026-11-10", "18:00") }
    const result = addShiftBody(values, WARSAW, format)
    expect(result.ok && result.body).toEqual({
      userId: ANNA,
      startedAt: "2026-11-10T10:00:00+01:00",
      endedAt: "2026-11-10T18:00:00+01:00",
    })
  })

  it("test_it_asks_for_the_person_and_both_ends", () => {
    expect(addShiftBody(emptyShiftForm("2026-11-10"), WARSAW, format)).toEqual({
      ok: false,
      errors: {
        userId: "Choose who worked the shift.",
        startedAt: "Enter the clock-in time.",
        endedAt: "Enter the clock-out time.",
      },
    })
  })

  it("test_it_refuses_a_clock_out_not_after_the_clock_in", () => {
    const values = { ...emptyShiftForm("2026-11-10", ANNA), start: at("2026-11-10", "22:00"), end: at("2026-11-10", "21:00") }
    expect(addShiftBody(values, WARSAW, format)).toEqual({
      ok: false,
      errors: { endedAt: "The clock-out must be after the clock-in." },
    })
  })

  it("test_it_refuses_a_time_skipped_when_the_clocks_move_forward", () => {
    // 29 March 2026: 02:00-02:59 does not exist in Warsaw.
    const values = { ...emptyShiftForm("2026-03-28", ANNA), start: at("2026-03-28", "22:00"), end: at("2026-03-29", "02:30") }
    expect(addShiftBody(values, WARSAW, format)).toEqual({
      ok: false,
      errors: {
        endedAt: "02:30 does not exist on 29 Mar 2026: the clocks moved forward. Enter a time after the change.",
      },
    })
  })

  it("test_it_sends_the_chosen_one_of_a_time_repeated_when_the_clocks_go_back", () => {
    // 25 October 2026: 02:30 happens at +02:00, then again at +01:00. A night shift 22:00-02:30.
    const base = { ...emptyShiftForm("2026-10-24", ANNA), start: at("2026-10-24", "22:00") }
    const first = addShiftBody({ ...base, end: at("2026-10-25", "02:30", "first") }, WARSAW, format)
    const second = addShiftBody({ ...base, end: at("2026-10-25", "02:30", "second") }, WARSAW, format)
    expect(first.ok && first.body.endedAt).toBe("2026-10-25T02:30:00+02:00")
    expect(second.ok && second.body.endedAt).toBe("2026-10-25T02:30:00+01:00")
    expect(repeatedOffsets(at("2026-10-25", "02:30"), WARSAW)).toEqual(["+02:00", "+01:00"])
    expect(repeatedOffsets(at("2026-10-25", "03:30"), WARSAW)).toBeNull()
    expect(repeatedOffsets(at("2026-10-25", ""), WARSAW)).toBeNull()
  })

  it("test_it_suggests_the_next_day_for_a_clock_out_not_after_the_clock_in", () => {
    expect(suggestedEndDate(at("2026-11-10", "22:00"), "03:30")).toBe("2026-11-11")
    expect(suggestedEndDate(at("2026-11-10", "22:00"), "22:00")).toBe("2026-11-11")
    expect(suggestedEndDate(at("2026-11-10", "10:00"), "18:00")).toBe("2026-11-10")
    expect(suggestedEndDate(at("2026-12-31", "22:00"), "02:00")).toBe("2027-01-01")
    expect(suggestedEndDate(at("2026-11-10", ""), "02:00")).toBe("2026-11-10")
  })
})

describe("editing a shift", () => {
  it("test_it_prefills_the_dialog_in_the_organizations_zone", () => {
    expect(shiftFormFrom(shift({ note: "Covered for Bob" }), WARSAW)).toEqual({
      userId: ANNA,
      start: at("2026-11-10", "22:00"),
      end: at("2026-11-11", "03:30"),
      note: "Covered for Bob",
    })
  })

  it("test_it_prefills_an_open_shift_without_a_clock_out", () => {
    expect(shiftFormFrom(shift({ endedAt: null, durationMinutes: null }), WARSAW).end).toEqual(at("", ""))
  })

  it("test_it_prefills_the_second_of_a_repeated_time_when_the_shift_ended_then", () => {
    const form = shiftFormFrom(shift({ startedAt: "2026-10-24T20:00:00Z", endedAt: "2026-10-25T01:30:45Z" }), WARSAW)
    expect(form.end).toEqual(at("2026-10-25", "02:30", "second"))
    const earlier = shiftFormFrom(shift({ startedAt: "2026-10-24T20:00:00Z", endedAt: "2026-10-25T00:30:00Z" }), WARSAW)
    expect(earlier.end).toEqual(at("2026-10-25", "02:30", "first"))
  })

  it("test_it_sends_only_what_changed", () => {
    const initial = shiftFormFrom(shift(), WARSAW)
    expect(correctShiftBody(initial, initial, WARSAW, format)).toEqual({ ok: true, body: {} })
    // The clocked times keep their seconds: they are not sent when only the note changes.
    expect(correctShiftBody(initial, { ...initial, note: "Stayed to clean up" }, WARSAW, format)).toEqual({
      ok: true,
      body: { note: "Stayed to clean up" },
    })
    expect(correctShiftBody(initial, { ...initial, end: at("2026-11-11", "04:00") }, WARSAW, format)).toEqual({
      ok: true,
      body: { endedAt: "2026-11-11T04:00:00+01:00" },
    })
  })

  it("test_it_sends_an_empty_note_to_remove_it", () => {
    const initial = shiftFormFrom(shift({ note: "Typo" }), WARSAW)
    expect(correctShiftBody(initial, { ...initial, note: "  " }, WARSAW, format)).toEqual({ ok: true, body: { note: "" } })
  })

  it("test_it_ends_an_open_shift_or_leaves_it_open", () => {
    const initial = shiftFormFrom(shift({ endedAt: null, durationMinutes: null }), WARSAW)
    expect(correctShiftBody(initial, initial, WARSAW, format)).toEqual({ ok: true, body: {} })
    expect(correctShiftBody(initial, { ...initial, end: at("2026-11-11", "02:00") }, WARSAW, format)).toEqual({
      ok: true,
      body: { endedAt: "2026-11-11T02:00:00+01:00" },
    })
    // Half a clock-out is a mistake, not "leave it open".
    expect(correctShiftBody(initial, { ...initial, end: at("2026-11-11", "") }, WARSAW, format)).toEqual({
      ok: false,
      errors: { endedAt: "Enter the clock-out time." },
    })
  })

  it("test_it_keeps_the_clock_out_of_an_ended_shift", () => {
    const initial = shiftFormFrom(shift(), WARSAW)
    expect(correctShiftBody(initial, { ...initial, end: at("", "") }, WARSAW, format)).toEqual({
      ok: false,
      errors: { endedAt: "Enter the clock-out date." },
    })
  })
})


describe("requesting a missing shift", () => {
  it("test_it_sends_both_ends_in_the_organizations_zone_and_the_note", () => {
    const values = { userId: "", start: at("2026-11-10", "22:00"), end: at("2026-11-11", "03:30"), note: " Forgot to clock " }
    expect(missingShiftRequestBody(values, WARSAW, format)).toEqual({
      ok: true,
      body: {
        startedAt: "2026-11-10T22:00:00+01:00",
        endedAt: "2026-11-11T03:30:00+01:00",
        note: "Forgot to clock",
      },
    })
  })

  it("test_it_requires_a_note_and_a_clock_out", () => {
    const values = { userId: "", start: at("2026-11-10", "22:00"), end: at("", ""), note: "  " }
    expect(missingShiftRequestBody(values, WARSAW, format)).toEqual({
      ok: false,
      errors: { endedAt: "Enter the clock-out date.", note: "Say why, for whoever decides the request." },
    })
  })

  it("test_it_refuses_a_clock_out_before_the_clock_in", () => {
    const values = { userId: "", start: at("2026-11-10", "22:00"), end: at("2026-11-10", "21:00"), note: "Why" }
    expect(missingShiftRequestBody(values, WARSAW, format)).toEqual({
      ok: false,
      errors: { endedAt: "The clock-out must be after the clock-in." },
    })
  })

  it("test_it_explains_what_is_missing_in_polish", async () => {
    await i18n.changeLanguage("pl")
    try {
      const values = { userId: "", start: at("2026-11-10", "22:00"), end: at("", ""), note: "  " }
      expect(missingShiftRequestBody(values, WARSAW, format)).toEqual({
        ok: false,
        errors: { endedAt: "Podaj datę zakończenia.", note: "Napisz dlaczego, dla osoby, która rozpatrzy prośbę." },
      })
    } finally {
      await i18n.changeLanguage("en")
    }
  })
})

describe("requesting new times for a shift", () => {
  it("test_it_sends_a_changed_time_and_keeps_the_untouched_one_to_the_second", () => {
    const initial = shiftFormFrom(shift(), WARSAW)
    const values = { ...initial, end: at("2026-11-11", "04:00"), note: "Closed late" }
    expect(newTimesRequestBody(shift(), values, WARSAW, format)).toEqual({
      ok: true,
      body: {
        shiftId: SHIFT_ID,
        startedAt: "2026-11-10T21:00:37+00:00",
        endedAt: "2026-11-11T04:00:00+01:00",
        note: "Closed late",
      },
    })
  })

  it("test_it_keeps_an_open_shift_open_when_no_clock_out_is_entered", () => {
    const open = shift({ endedAt: null, durationMinutes: null })
    const initial = shiftFormFrom(open, WARSAW)
    const values = { ...initial, start: at("2026-11-10", "21:30"), note: "Came in earlier" }
    expect(newTimesRequestBody(open, values, WARSAW, format)).toEqual({
      ok: true,
      body: { shiftId: SHIFT_ID, startedAt: "2026-11-10T21:30:00+01:00", endedAt: null, note: "Came in earlier" },
    })
  })

  it("test_it_asks_for_the_clock_out_of_an_open_shift_that_was_forgotten", () => {
    const open = shift({ endedAt: null, durationMinutes: null })
    const values = { ...shiftFormFrom(open, WARSAW), end: at("2026-11-11", "03:00"), note: "Forgot to clock out" }
    expect(newTimesRequestBody(open, values, WARSAW, format)).toMatchObject({
      ok: true,
      body: { startedAt: "2026-11-10T21:00:37+00:00", endedAt: "2026-11-11T03:00:00+01:00" },
    })
  })

  it("test_it_refuses_the_times_the_shift_already_has", () => {
    const values = { ...shiftFormFrom(shift(), WARSAW), note: "Nothing" }
    expect(newTimesRequestBody(shift(), values, WARSAW, format)).toEqual({
      ok: false,
      errors: { shiftId: "Change the clock-in or the clock-out: these are the shift's times now." },
    })
  })

  it("test_it_requires_a_note_and_the_clock_out_of_an_ended_shift", () => {
    const values = { ...shiftFormFrom(shift(), WARSAW), end: at("", ""), note: "" }
    expect(newTimesRequestBody(shift(), values, WARSAW, format)).toEqual({
      ok: false,
      errors: { endedAt: "Enter the clock-out date.", note: "Say why, for whoever decides the request." },
    })
  })
})

describe("editing the form", () => {
  const night = { userId: ANNA, start: at("2026-11-10", "22:00"), end: at("2026-11-10", ""), note: "" }

  it("test_it_moves_the_clock_out_to_the_next_day_for_a_night_shift", () => {
    expect(editShiftForm(night, { end: at("2026-11-10", "03:30") }, { endDateSet: false, wasOpen: false }).end).toEqual(
      at("2026-11-11", "03:30"),
    )
  })

  it("test_it_keeps_a_clock_out_date_the_person_set", () => {
    expect(editShiftForm(night, { end: at("2026-11-12", "03:30") }, { endDateSet: true, wasOpen: false }).end).toEqual(
      at("2026-11-12", "03:30"),
    )
  })

  it("test_it_leaves_an_open_shift_without_a_clock_out_date", () => {
    const open = { ...night, end: at("2026-11-11", "03:30") }
    expect(editShiftForm(open, { end: at("2026-11-11", "") }, { endDateSet: false, wasOpen: true }).end).toEqual(at("", ""))
  })
})
