import type ui from "@business-tool/ui/locales/en/ui.json"

import type timeclock from "./locales/en/timeclock.json"

// Typed keys (ADR 0028 §3): an unknown key or a missing interpolation value is a type error. The
// default namespace stays `ui`, as packages/ui's components (compiled here too) expect; this app's
// components call `useTranslation("timeclock")`.
declare module "i18next" {
  interface CustomTypeOptions {
    defaultNS: "ui"
    resources: { ui: typeof ui; timeclock: typeof timeclock }
  }
}
