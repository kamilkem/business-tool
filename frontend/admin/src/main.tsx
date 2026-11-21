import { StrictMode } from "react"
import { createRoot } from "react-dom/client"
import { QueryClientProvider } from "@tanstack/react-query"
import { RouterProvider, createRouter } from "@tanstack/react-router"

import "@business-tool/ui/globals.css"
import { LanguageProvider } from "@business-tool/ui/components/language-provider"
import { languageForSignedOut, showLanguage } from "@business-tool/ui/i18n/current-language"
import { syncSystemTheme } from "@business-tool/ui/lib/theme"
import { Toaster } from "@business-tool/ui/components/sonner"
import { TooltipProvider } from "@business-tool/ui/components/tooltip"

import "./i18n"
import { queryClient, session } from "./lib/api"
import { routeTree } from "./routeTree.gen"

const router = createRouter({
  routeTree,
  context: { queryClient, session },
  defaultPreload: "intent",
  defaultPreloadStaleTime: 0,
})

declare module "@tanstack/react-router" {
  interface Register {
    router: typeof router
  }
}

syncSystemTheme()
// Until /me says otherwise (the _app layout): the language this device last showed, else the browser's.
showLanguage(languageForSignedOut())

createRoot(document.getElementById("root")!).render(
  <StrictMode>
    <LanguageProvider>
      <QueryClientProvider client={queryClient}>
        <TooltipProvider>
          <RouterProvider router={router} />
          <Toaster />
        </TooltipProvider>
      </QueryClientProvider>
    </LanguageProvider>
  </StrictMode>,
)
