import type { Session } from "@business-tool/api-client"
import type { QueryClient } from "@tanstack/react-query"
import { Outlet, createRootRouteWithContext } from "@tanstack/react-router"

export interface RouterContext {
  queryClient: QueryClient
  session: Session
}

export const Route = createRootRouteWithContext<RouterContext>()({
  component: () => <Outlet />,
})
