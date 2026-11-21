import type ui from "@business-tool/ui/locales/en/ui.json"

import type admin from "./locales/en/admin.json"

// Typed keys (ADR 0028 §3): an unknown key or a missing interpolation value is a type error. The
// default namespace stays `ui`, as packages/ui's components (compiled here too) expect; this app's
// components call `useTranslation("admin")`.
declare module "i18next" {
  interface CustomTypeOptions {
    defaultNS: "ui"
    resources: { ui: typeof ui; admin: typeof admin }
  }
}
