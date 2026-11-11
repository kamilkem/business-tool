import { ApiError } from "@business-tool/api-client"

/** "Wait 3 minutes" from a 429's `Retry-After`, or a vague wait when the API sent none. */
export function tooManyAttemptsMessage(retryAfterSeconds: number | null): string {
  if (retryAfterSeconds === null) return "Too many attempts. Wait a few minutes and try again."
  if (retryAfterSeconds < 60) return "Too many attempts. Wait a minute and try again."
  const minutes = Math.ceil(retryAfterSeconds / 60)
  return `Too many attempts. Wait ${minutes} minutes and try again.`
}

/**
 * A message for a failed request that the form has no specific answer for:
 * the rate limit, a server error, or no connection at all.
 */
export function requestErrorMessage(error: unknown): string {
  if (error instanceof ApiError) {
    if (error.status === 429) return tooManyAttemptsMessage(error.retryAfter)
    if (error.status >= 500) return "Something went wrong on our side. Try again in a moment."
    return error.message
  }
  return "Could not reach the server. Check your connection and try again."
}
