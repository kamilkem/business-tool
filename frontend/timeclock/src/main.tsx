import { StrictMode } from "react"
import { createRoot } from "react-dom/client"
import { QueryClientProvider } from "@tanstack/react-query"
import { RouterProvider, createRouter } from "@tanstack/react-router"

import "@business-tool/ui/globals.css"
import { syncSystemTheme } from "@business-tool/ui/lib/theme"

import { queryClient, session } from "./lib/api"
import { routeTree } from "./routeTree.gen"

const router = createRouter({ routeTree, context: { queryClient, session } })

declare module "@tanstack/react-router" {
  interface Register {
    router: typeof router
  }
}

syncSystemTheme()

createRoot(document.getElementById("root")!).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
    </QueryClientProvider>
  </StrictMode>,
)
