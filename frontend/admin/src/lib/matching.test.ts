import { describe, expect, it } from "vitest"

import { type MatchingRun, type MatchingStatus, isBusy, isWaitingForRun, matchAgainState, runKey } from "./matching"

function run(overrides: Partial<MatchingRun> = {}): MatchingRun {
  return {
    id: "run-1",
    state: "done",
    startedAt: "2026-11-26T10:00:00+00:00",
    finishedAt: "2026-11-26T10:05:00+00:00",
    interpreted: 40,
    assigned: 25,
    suggested: 9,
    foundNothing: 6,
    ...overrides,
  }
}

function status(overrides: Partial<MatchingStatus> = {}): MatchingStatus {
  return { waitingCount: 12, modelEnabled: true, lastRun: run(), ...overrides }
}

describe("matchAgainState", () => {
  it("test_it_lets_the_owner_match_again_when_items_wait_and_nothing_runs", () => {
    expect(matchAgainState(status(), null)).toBe("ready")
  })

  it("test_it_refuses_while_the_model_is_off_whatever_else_is_true", () => {
    expect(matchAgainState(status({ modelEnabled: false, waitingCount: 0 }), null)).toBe("model-off")
    expect(matchAgainState(status({ modelEnabled: false, lastRun: run({ state: "running", finishedAt: null }) }), null)).toBe("model-off")
  })

  it("test_it_refuses_when_nothing_waits", () => {
    expect(matchAgainState(status({ waitingCount: 0 }), null)).toBe("nothing-waiting")
  })

  it("test_it_is_busy_while_a_run_is_going", () => {
    expect(matchAgainState(status({ lastRun: run({ state: "running", finishedAt: null }) }), null)).toBe("busy")
  })

  it("test_it_is_not_busy_for_a_run_that_stopped_or_never_ended", () => {
    expect(matchAgainState(status({ lastRun: run({ state: "model_unavailable" }) }), null)).toBe("ready")
    expect(matchAgainState(status({ lastRun: run({ state: "interrupted", finishedAt: null }) }), null)).toBe("ready")
  })
})

describe("waiting for a run that was asked for", () => {
  it("test_it_waits_until_a_run_newer_than_the_one_before_the_click_shows_up", () => {
    const watch = { previousRunId: "run-1" }

    expect(isWaitingForRun(status(), watch)).toBe(true)
    expect(isWaitingForRun(status({ lastRun: run({ id: "run-2", state: "running", finishedAt: null }) }), watch)).toBe(false)
    expect(isBusy(status({ lastRun: run({ id: "run-2", state: "running", finishedAt: null }) }), watch)).toBe(true)
    expect(isBusy(status({ lastRun: run({ id: "run-2" }) }), watch)).toBe(false)
  })

  it("test_it_waits_for_the_first_run_when_there_was_none", () => {
    expect(isWaitingForRun(status({ lastRun: null }), { previousRunId: null })).toBe(true)
    expect(isWaitingForRun(status({ lastRun: null }), null)).toBe(false)
  })
})

describe("runKey", () => {
  it("test_it_changes_when_a_run_starts_and_when_it_ends", () => {
    const running = runKey(status({ lastRun: run({ state: "running", finishedAt: null }) }))
    const done = runKey(status())

    expect(running).not.toBe(done)
    expect(runKey(status({ lastRun: null }))).toBeNull()
  })
})
