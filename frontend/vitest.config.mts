import { defineConfig } from "vitest/config"

// Unit tests for pure TypeScript (formatting, date arithmetic) in every workspace package.
export default defineConfig({
  test: {
    include: ["{admin,timeclock,packages/*}/src/**/*.test.{ts,tsx}"],
    environment: "node",
    // The browser's zone is deliberately not the organizations' (Europe/Warsaw in most tests), so a
    // helper that falls back to the machine's zone fails here instead of passing by accident.
    env: { TZ: "America/New_York" },
  },
})
