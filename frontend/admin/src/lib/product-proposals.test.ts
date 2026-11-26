import { describe, expect, test } from "vitest"

import { type ProductProposal, acceptBody, proposalFormValues } from "./product-proposals"

const proposal: ProductProposal = {
  id: "jack-daniels",
  name: "Jack Daniel's",
  measure: "volume",
  categoryId: "c1",
  itemCount: 40,
  exampleNames: ["JACK DANIELS 0,7L"],
  existingProductId: null,
}

describe("product proposals", () => {
  test("test_it_starts_the_form_from_the_proposal", () => {
    expect(proposalFormValues(proposal)).toEqual({ name: "Jack Daniel's", categoryId: "c1", measure: "volume", referenceQuantity: "" })
    expect(proposalFormValues({ ...proposal, categoryId: null }).categoryId).toBe("")
  })

  test("test_it_sends_the_edited_product_with_a_point_decimal_and_null_for_nothing", () => {
    expect(acceptBody({ name: "  Jack  ", categoryId: "", measure: "volume", referenceQuantity: " 0,5 " })).toEqual({
      name: "Jack",
      measure: "volume",
      categoryId: null,
      referenceQuantity: "0.5",
    })
    expect(acceptBody({ name: "Tyskie", categoryId: "c2", measure: "volume", referenceQuantity: "" }).referenceQuantity).toBeNull()
  })

  test("test_it_sends_no_reference_quantity_for_a_measure_without_a_unit", () => {
    expect(acceptBody({ name: "Czynsz", categoryId: "", measure: "none", referenceQuantity: "1" }).referenceQuantity).toBeNull()
  })
})
