import { afterEach, describe, expect, test } from "vitest"
import { ApiError } from "@business-tool/api-client"

import { i18n } from "../i18n/i18n"
import { requestErrorMessage, tooManyAttemptsMessage } from "./request-error"

afterEach(async () => {
  await i18n.changeLanguage("en")
})

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

  test("test_it_keeps_the_api_detail_for_other_errors", () => {
    expect(requestErrorMessage(new ApiError(409, { detail: "Shift already open." }))).toBe("Shift already open.")
  })
})
