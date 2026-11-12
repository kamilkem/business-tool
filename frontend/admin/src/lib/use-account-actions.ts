import { logout } from "@business-tool/api-client"
import { useRouteContext, useRouter } from "@tanstack/react-router"

/** Switching organization and signing out, shared by the sidebar and the employee screen. */
export function useAccountActions() {
  const { session, queryClient } = useRouteContext({ from: "/_app" })
  const router = useRouter()

  async function switchOrganization(id: string) {
    session.selectOrganization(id)
    // Drop cached tenant data before reloading routes for the new organization. The routes'
    // guards run again, so a page the new role may not use sends it to the start page.
    queryClient.removeQueries({ predicate: (q) => q.queryKey[0] !== "me" })
    await router.invalidate()
  }

  async function signOut() {
    await logout(session)
    queryClient.clear()
    await router.navigate({ to: "/login" })
  }

  return { switchOrganization, signOut }
}
