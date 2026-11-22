import { ApiError } from "@business-tool/api-client"
import { afterEach, beforeEach, describe, expect, it } from "vitest"

import { i18n } from "../i18n"
import {
  type CashStorage,
  cashStorageFormErrors,
  cashStoragePatch,
  splitCashStorages,
  withCashStorage,
  withoutCashStorage,
} from "./cash-storages"

function storage(name: string, mode: CashStorage["mode"] = "counted", active = true): CashStorage {
  return {
    id: `storage-${name}`,
    name,
    mode,
    active,
    used: false,
    createdAt: "2026-11-01T10:00:00+00:00",
    deactivatedAt: active ? null : "2026-11-10T10:00:00+00:00",
  }
}

const cashBox = storage("Cash box")
const safe = storage("Safe", "deposit")
const oldTill = storage("Old till", "counted", false)

describe("splitCashStorages", () => {
  it("test_it_splits_active_and_deactivated_storages_keeping_their_order", () => {
    expect(splitCashStorages([cashBox, safe, oldTill])).toEqual({ active: [cashBox, safe], deactivated: [oldTill] })
  })
})

describe("withCashStorage", () => {
  it("test_it_puts_an_added_storage_in_name_order_before_the_deactivated_ones", () => {
    const drawer = storage("Drawer")

    expect(withCashStorage([cashBox, safe, oldTill], drawer)).toEqual([cashBox, drawer, safe, oldTill])
  })

  it("test_it_moves_a_renamed_storage_to_its_new_place", () => {
    const renamed = { ...safe, name: "Back office" }

    expect(withCashStorage([cashBox, safe, oldTill], renamed)).toEqual([renamed, cashBox, oldTill])
  })

  it("test_it_moves_a_deactivated_storage_among_the_deactivated_ones_and_back", () => {
    const deactivated = { ...cashBox, active: false, deactivatedAt: "2026-11-12T10:00:00+00:00" }
    const reactivated = { ...oldTill, active: true, deactivatedAt: null }

    expect(withCashStorage([cashBox, safe, oldTill], deactivated)).toEqual([safe, deactivated, oldTill])
    expect(withCashStorage([cashBox, safe, oldTill], reactivated)).toEqual([cashBox, reactivated, safe])
  })

  it("test_it_does_not_change_the_list_it_was_given", () => {
    const list = [cashBox, safe]
    withCashStorage(list, storage("Drawer"))

    expect(list).toEqual([cashBox, safe])
  })
})

describe("withoutCashStorage", () => {
  it("test_it_drops_a_deleted_storage", () => {
    expect(withoutCashStorage([cashBox, safe, oldTill], safe.id)).toEqual([cashBox, oldTill])
  })
})

describe("cashStoragePatch", () => {
  it("test_it_sends_only_what_changed", () => {
    expect(cashStoragePatch(safe, { name: "Safe", mode: "deposit" })).toEqual({})
    expect(cashStoragePatch(safe, { name: " Safe ", mode: "counted" })).toEqual({ mode: "counted" })
    expect(cashStoragePatch(safe, { name: "Vault", mode: "deposit" })).toEqual({ name: "Vault" })
    expect(cashStoragePatch(safe, { name: "Vault", mode: "counted" })).toEqual({ name: "Vault", mode: "counted" })
  })
})

describe("cashStorageFormErrors", () => {
  const storages = [cashBox, safe, oldTill]
  const taken = (name: string) =>
    new ApiError(409, { detail: "Taken", code: "cash_flow.storage_name_taken", parameters: { name } })

  it("test_it_shows_a_duplicate_name_on_the_name_field", () => {
    expect(cashStorageFormErrors(taken("Safe"), " Safe ", storages)).toEqual({ name: "A storage named “Safe” already exists." })
  })

  it("test_it_says_when_the_name_belongs_to_a_deactivated_storage", () => {
    expect(cashStorageFormErrors(taken("Old till"), "Old till", storages).name).toContain("deactivated storage")
  })

  it("test_it_shows_the_domain_refusal_of_a_name_on_the_name_field", () => {
    const invalid = new ApiError(422, {
      detail: "Too long",
      code: "cash_flow.invalid_storage_name",
      parameters: { name: "x", maxLength: 64 },
    })

    expect(cashStorageFormErrors(invalid, "x", storages)).toEqual({ name: "A cash storage name must have 1 to 64 characters." })
  })

  it("test_it_shows_violations_on_their_fields_and_others_under_the_form", () => {
    const invalid = new ApiError(422, {
      detail: "Invalid",
      violations: [
        { propertyPath: "name", message: "This value should not be blank." },
        { propertyPath: "mode", message: "The value you selected is not a valid choice." },
        { propertyPath: "id", message: "Unexpected." },
      ],
    })

    expect(cashStorageFormErrors(invalid, "", storages)).toEqual({
      name: "This value should not be blank.",
      mode: "The value you selected is not a valid choice.",
      form: "Unexpected.",
    })
  })

  it("test_it_shows_any_other_failure_under_the_form_translated_from_its_code", () => {
    const gone = new ApiError(404, { detail: "Not found", code: "cash_flow.storage_not_found", parameters: { storageId: "x" } })

    expect(cashStorageFormErrors(gone, "Safe", storages)).toEqual({ form: "This cash storage no longer exists. Reload the page." })
    expect(cashStorageFormErrors(new TypeError("Failed to fetch"), "Safe", storages)).toEqual({
      form: "Could not reach the server. Check your connection and try again.",
    })
  })

  describe("in Polish", () => {
    beforeEach(async () => {
      await i18n.changeLanguage("pl")
    })
    afterEach(async () => {
      await i18n.changeLanguage("en")
    })

    it("test_it_explains_a_taken_name_in_polish", () => {
      expect(cashStorageFormErrors(taken("Safe"), "Safe", storages)).toEqual({ name: "Miejsce o nazwie „Safe” już istnieje." })
    })
  })
})
