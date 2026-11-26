import { type components } from "@business-tool/api-client"

import { type Measure, normalizeDecimal } from "./product-prices"

/*
 * The products the model proposes for items no product of the catalog fits (ADR 0044). Nothing is
 * created until the owner decides: create the product (with the name, measure and category as edited
 * here), add the items to a product that exists, or dismiss the proposal.
 */

export type ProductProposal = components["schemas"]["ProductProposal"]

/** What the create dialog edits, starting from the proposal. */
export interface ProposalFormValues {
  name: string
  /** A category's id, or "" for none. */
  categoryId: string
  measure: Measure
  /** What the owner typed; ignored for `none`. */
  referenceQuantity: string
}

export function proposalFormValues(proposal: ProductProposal): ProposalFormValues {
  return { name: proposal.name, categoryId: proposal.categoryId ?? "", measure: proposal.measure, referenceQuantity: "" }
}

/**
 * The body of `POST /product-proposals/{id}/accept`: the trimmed name, the measure, the category (null
 * for none) and the reference quantity (null: the API reads it as 1, and `none` has none). A decimal
 * typed with a comma is sent with a point; it stays a string.
 */
export function acceptBody(values: ProposalFormValues): components["schemas"]["ProductProposal.AcceptProductProposalInput"] {
  return {
    name: values.name.trim(),
    measure: values.measure,
    categoryId: values.categoryId === "" ? null : values.categoryId,
    referenceQuantity: values.measure === "none" ? null : normalizeDecimal(values.referenceQuantity),
  }
}
