import { ApiError } from "@business-tool/api-client"

import { i18n } from "../i18n/i18n"

/** "Wait 3 minutes" from a 429's `Retry-After`, or a vague wait when the API sent none. */
export function tooManyAttemptsMessage(retryAfterSeconds: number | null): string {
  if (retryAfterSeconds === null) return i18n.t("requestError.tooManyAttempts")
  if (retryAfterSeconds < 60) return i18n.t("requestError.tooManyAttemptsMinute")
  return i18n.t("requestError.tooManyAttemptsMinutes", { count: Math.ceil(retryAfterSeconds / 60) })
}

/**
 * A message for a failed request that the form has no specific answer for:
 * the rate limit, a server error, or no connection at all.
 */
export function requestErrorMessage(error: unknown): string {
  if (error instanceof ApiError) {
    if (error.status === 429) return tooManyAttemptsMessage(error.retryAfter)
    if (error.status >= 500) return i18n.t("requestError.serverError")
    // The API's `detail` is English; error codes translated here replace it (ADR 0028 §5).
    return error.message
  }
  return i18n.t("requestError.noConnection")
}
