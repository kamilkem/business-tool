interface ImportMetaEnv {
  /** Where the employees' timeclock app runs; the admin links there for employees (ADR 0021). */
  readonly VITE_TIMECLOCK_URL: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
