import type { components } from "@business-tool/api-client"

export type MatchingStatus = components["schemas"]["MatchingStatus"]
export type MatchingRun = components["schemas"]["MatchingStatusRun"]

/** How often the status is asked for while a run is going or has been asked for and has not started. */
export const MATCHING_POLL_MS = 5_000

/** How long after a click the screen waits for the run to show up (the jobs worker takes it when it is free). */
export const MATCHING_WATCH_MS = 2 * 60_000

/** The run the screen is waiting for after "Match again": the one that was the last when the button was pressed. */
export interface MatchingWatch {
  previousRunId: string | null
}

/** A run was asked for and none newer than the one before the click has shown up. */
export function isWaitingForRun(status: MatchingStatus, watch: MatchingWatch | null): boolean {
  if (watch === null) return false
  return status.lastRun === null || status.lastRun.id === watch.previousRunId
}

/** A run is going, or one was asked for and has not started: the status is worth asking for again soon. */
export function isBusy(status: MatchingStatus, watch: MatchingWatch | null): boolean {
  return status.lastRun?.state === "running" || isWaitingForRun(status, watch)
}

export type MatchAgain = "ready" | "model-off" | "nothing-waiting" | "busy"

/** Whether "Match again" can be pressed, and if not, why. The model being off wins: nothing else matters then. */
export function matchAgainState(status: MatchingStatus, watch: MatchingWatch | null): MatchAgain {
  if (!status.modelEnabled) return "model-off"
  if (isBusy(status, watch)) return "busy"
  if (status.waitingCount === 0) return "nothing-waiting"
  return "ready"
}

/** A run changed under the screen (started, ended): the lists the run touched are out of date. */
export function runKey(status: MatchingStatus): string | null {
  return status.lastRun === null ? null : `${status.lastRun.id}:${status.lastRun.state}`
}
