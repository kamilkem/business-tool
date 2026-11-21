import { i18n } from "@business-tool/ui/i18n/i18n"

import en from "./locales/en/admin.json"
import pl from "./locales/pl/admin.json"

// The app's own namespace next to packages/ui's `ui` (ADR 0028 §3). Imported by main.tsx before
// anything renders, so `useTranslation("admin")` always finds it.
i18n.addResourceBundle("en", "admin", en)
i18n.addResourceBundle("pl", "admin", pl)
