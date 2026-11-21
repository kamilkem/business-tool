import type en from "../locales/en/ui.json"

// Typed keys for `packages/ui` on its own. Each app declares the same interface with `ui` and its
// own namespace (ADR 0028), so an unknown key is a type error everywhere.
declare module "i18next" {
  interface CustomTypeOptions {
    defaultNS: "ui"
    resources: { ui: typeof en }
  }
}
