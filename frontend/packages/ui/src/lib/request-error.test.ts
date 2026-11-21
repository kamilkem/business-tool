/// <reference types="node" />
import { readFileSync } from "node:fs"
import { join } from "node:path"
import { afterEach, describe, expect, test } from "vitest"
import { ApiError } from "@business-tool/api-client"

import { i18n } from "../i18n/i18n"
import en from "../locales/en/ui.json"
import { createDateTimeFormat } from "./date-time"
import { requestErrorMessage, signInErrorMessage, tooManyAttemptsMessage } from "./request-error"

afterEach(async () => {
  await i18n.changeLanguage("en")
})

const ANNA = "0190a1b2-0000-7000-8000-000000000001"
const problem = (status: number, code: string | null, parameters: Record<string, unknown> = {}) =>
  new ApiError(status, { detail: "English detail for developers.", code, parameters })

describe("tooManyAttemptsMessage", () => {
  test("test_it_says_how_many_minutes_to_wait_in_english", () => {
    expect(tooManyAttemptsMessage(null)).toBe("Too many attempts. Wait a few minutes and try again.")
    expect(tooManyAttemptsMessage(30)).toBe("Too many attempts. Wait a minute and try again.")
    expect(tooManyAttemptsMessage(60)).toBe("Too many attempts. Wait 1 minute and try again.")
    expect(tooManyAttemptsMessage(150)).toBe("Too many attempts. Wait 3 minutes and try again.")
  })

  test("test_it_uses_the_polish_plural_forms", async () => {
    await i18n.changeLanguage("pl")
    expect(tooManyAttemptsMessage(60)).toBe("Zbyt wiele prób. Odczekaj 1 minutę i spróbuj ponownie.")
    expect(tooManyAttemptsMessage(180)).toBe("Zbyt wiele prób. Odczekaj 3 minuty i spróbuj ponownie.")
    expect(tooManyAttemptsMessage(300)).toBe("Zbyt wiele prób. Odczekaj 5 minut i spróbuj ponownie.")
    expect(tooManyAttemptsMessage(22 * 60)).toBe("Zbyt wiele prób. Odczekaj 22 minuty i spróbuj ponownie.")
  })
})

describe("requestErrorMessage", () => {
  test("test_it_follows_the_current_language", async () => {
    expect(requestErrorMessage(new ApiError(500, null))).toBe("Something went wrong on our side. Try again in a moment.")
    await i18n.changeLanguage("pl")
    expect(requestErrorMessage(new ApiError(500, null))).toBe("Coś poszło nie tak po naszej stronie. Spróbuj ponownie za chwilę.")
    expect(requestErrorMessage(new TypeError("fetch failed"))).toBe(
      "Nie udało się połączyć z serwerem. Sprawdź połączenie i spróbuj ponownie.",
    )
  })

  test("test_it_translates_a_known_code_with_its_parameters", async () => {
    const error = problem(409, "income.register_name_taken", { name: "Bar" })
    expect(requestErrorMessage(error)).toBe("A register named “Bar” already exists.")
    await i18n.changeLanguage("pl")
    expect(requestErrorMessage(error)).toBe("Kasa o nazwie „Bar” już istnieje.")
  })

  test("test_it_puts_in_the_names_it_is_given_for_user_ids", async () => {
    const error = problem(409, "payroll.pay_rate_date_taken.hourly_rate", { userId: ANNA, date: "2026-11-01" })
    expect(requestErrorMessage(error, { names: { [ANNA]: "Anna Nowak" } })).toBe(
      "Anna Nowak already has an hourly rate from 1 Nov 2026.",
    )
    expect(requestErrorMessage(error)).toBe("This person already has an hourly rate from 1 Nov 2026.")
    await i18n.changeLanguage("pl")
    expect(requestErrorMessage(error, { names: { [ANNA]: "Anna Nowak" } })).toBe(
      "Anna Nowak ma już stawkę godzinową od 1 lis 2026.",
    )
  })

  test("test_it_explains_why_a_shift_cannot_be_deleted", async () => {
    const SHIFT = "0193a1b2-1111-7000-8000-000000000001"
    const open = problem(409, "time_tracking.open_shift_cannot_be_deleted", { shiftId: SHIFT })
    const locked = problem(409, "time_tracking.shift_period_locked", { date: "2026-10-14" })
    const pending = problem(409, "time_tracking.shift_has_pending_change_request", {
      shiftId: SHIFT,
      requestId: "0193a1b2-2222-7000-8000-000000000001",
    })
    expect(requestErrorMessage(open)).toBe("This shift is still open: it can be deleted once it has ended.")
    expect(requestErrorMessage(locked)).toBe("The shifts of 14 Oct 2026 are locked: that period has been settled.")
    expect(requestErrorMessage(pending)).toBe(
      "This shift has a pending change request. Approve or reject it before deleting the shift.",
    )
    await i18n.changeLanguage("pl")
    expect(requestErrorMessage(open)).toBe("Ta zmiana wciąż trwa: można ją usunąć dopiero po jej zakończeniu.")
    expect(requestErrorMessage(locked)).toBe("Zmiany z dnia 14 paź 2026 są zablokowane: ten okres został już rozliczony.")
    expect(requestErrorMessage(pending)).toBe(
      "Do tej zmiany jest oczekujący wniosek o zmianę. Zatwierdź go lub odrzuć przed usunięciem zmiany.",
    )
  })

  test("test_it_says_you_for_the_signed_in_user", () => {
    const error = problem(409, "time_tracking.shift_already_open", { userId: ANNA })
    expect(requestErrorMessage(error, { selfId: ANNA })).toBe("You are already clocked in. Clock out first.")
    expect(requestErrorMessage(error, { names: { [ANNA]: "Anna" } })).toBe("Anna is already clocked in. Clock out first.")
  })

  test("test_it_uses_the_polish_plural_forms_of_a_count", async () => {
    await i18n.changeLanguage("pl")
    const openShifts = (count: number) => requestErrorMessage(problem(409, "payroll.run_not_approvable.open_shifts", { count }))
    expect(openShifts(1)).toBe("1 zmiana z tego miesiąca jest wciąż otwarta: najpierw ją zakończ.")
    expect(openShifts(3)).toBe("3 zmiany z tego miesiąca są wciąż otwarte: najpierw je zakończ.")
    expect(openShifts(5)).toBe("5 zmian z tego miesiąca jest wciąż otwartych: najpierw je zakończ.")
    expect(requestErrorMessage(problem(429, "ksef.import_too_soon", { minutes: 2, retryAt: "2026-11-21T12:00:00+00:00" }))).toBe(
      "Import można uruchomić ponownie za 2 minuty.",
    )
  })

  test("test_it_formats_months_and_instants_in_the_organizations_time_zone", async () => {
    const format = createDateTimeFormat("Europe/Warsaw", "en-GB")
    expect(requestErrorMessage(problem(409, "payroll.run_month_taken", { month: "2026-11" }), { format })).toBe(
      "There is already a payroll run for November 2026.",
    )
    const overlap = problem(409, "time_tracking.shift_overlap", {
      otherShiftId: "0190a1b2-0000-7000-8000-000000000002",
      otherStartedAt: "2026-11-10T21:00:00+00:00",
      otherEndedAt: null,
    })
    expect(requestErrorMessage(overlap, { format })).toBe(
      "The shift would overlap another shift, which started at 10 Nov 2026, 22:00 and is still open.",
    )
    await i18n.changeLanguage("pl")
    expect(requestErrorMessage(problem(409, "payroll.run_month_taken", { month: "2026-11" }), { format: createDateTimeFormat("Europe/Warsaw", "pl-PL") })).toBe(
      "Lista płac za listopad 2026 już istnieje.",
    )
  })

  test("test_it_falls_back_to_a_message_for_the_status_for_an_unknown_code", async () => {
    expect(requestErrorMessage(problem(409, "income.something_new"))).toBe(
      "It changed in the meantime. Reload the page and try again.",
    )
    expect(requestErrorMessage(problem(403, null))).toBe("You do not have access to this.")
    expect(requestErrorMessage(problem(418, null))).toBe("The request was refused. Reload the page and try again.")
    await i18n.changeLanguage("pl")
    expect(requestErrorMessage(problem(404, "income.something_new"))).toBe(
      "To już nie istnieje albo nie masz do tego dostępu. Odśwież stronę.",
    )
  })

  test("test_it_never_shows_the_english_detail", () => {
    expect(requestErrorMessage(new ApiError(409, { detail: "Shift already open." }))).not.toContain("Shift already open")
  })

  test("test_it_shows_the_first_violation_of_a_422_without_a_code", () => {
    const error = new ApiError(422, { violations: [{ propertyPath: "name", message: "Ta wartość nie powinna być pusta." }] })
    expect(requestErrorMessage(error)).toBe("Ta wartość nie powinna być pusta.")
  })
})

describe("signInErrorMessage", () => {
  test("test_it_says_the_credentials_are_wrong_on_a_401", async () => {
    expect(signInErrorMessage(new ApiError(401, { detail: "Invalid credentials." }))).toBe("Wrong e-mail or password.")
    await i18n.changeLanguage("pl")
    expect(signInErrorMessage(new ApiError(401, { detail: "Invalid credentials." }))).toBe("Nieprawidłowy e-mail lub hasło.")
  })
})

describe("the apiError catalog", () => {
  // The codes the API defines: the `ErrorCode` union of the generated OpenAPI types (`make types`).
  const schema = readFileSync(join(import.meta.dirname, "../../../api-client/src/schema.d.ts"), "utf8")
  const union = /\bErrorCode: ([^;]+);/.exec(schema)?.[1] ?? ""
  const codes = [...union.matchAll(/"([^"]+)"/g)].map((match) => match[1]!).sort()

  // Catalog keys without plural suffixes and without the contexts the helper picks.
  const keys = new Set<string>()
  const walk = (node: object, prefix: string) => {
    for (const [key, value] of Object.entries(node)) {
      const path = prefix ? `${prefix}.${key}` : key
      if (typeof value !== "string") walk(value as object, path)
      else {
        const base = path.replace(/_(zero|one|two|few|many|other)$/, "")
        keys.add(codes.includes(base) ? base : base.replace(/_(self|open|onwards)$/, ""))
      }
    }
  }
  walk(en.apiError, "")

  test("test_it_reads_the_error_codes_of_the_api", () => {
    expect(codes.length).toBeGreaterThan(100)
  })

  test("test_it_has_english_text_for_every_error_code", () => {
    expect(codes.filter((code) => !keys.has(code))).toEqual([])
  })

  test("test_it_has_no_text_for_a_code_the_api_does_not_define", () => {
    expect([...keys].filter((key) => !codes.includes(key))).toEqual([])
  })
})
