import { useEffect, useState } from "react"
import { createFileRoute, redirect, useRouter } from "@tanstack/react-router"
import { LogOutIcon } from "lucide-react"

import { Button } from "@business-tool/ui/components/button"
import { Card, CardContent } from "@business-tool/ui/components/card"

import { meQuery } from "@/lib/queries"

export const Route = createFileRoute("/")({
  beforeLoad: async ({ context, location }) => {
    const { session, queryClient } = context
    if (!session.get().token) {
      throw redirect({ to: "/login", search: { redirect: location.href } })
    }

    const me = await queryClient.ensureQueryData(meQuery)
    const memberships = me.memberships
    const selected = session.get().organizationId
    const membership = memberships.find((m) => m.organization.id === selected) ?? memberships[0] ?? null
    if (membership && membership.organization.id !== selected) {
      session.selectOrganization(membership.organization.id)
    }

    return { me, membership }
  },
  component: ClockScreen,
})

function useNow() {
  const [now, setNow] = useState(() => new Date())
  useEffect(() => {
    const timer = setInterval(() => setNow(new Date()), 1000)
    return () => clearInterval(timer)
  }, [])
  return now
}

function ClockScreen() {
  const { me, membership, session, queryClient } = Route.useRouteContext()
  const router = useRouter()
  const now = useNow()

  async function signOut() {
    session.signOut()
    queryClient.clear()
    await router.navigate({ to: "/login" })
  }

  return (
    <div className="flex min-h-svh flex-col bg-muted pt-[env(safe-area-inset-top)] pb-[env(safe-area-inset-bottom)]">
      <header className="flex items-center justify-between px-4 py-3">
        <div className="text-sm">
          <div className="font-medium">{membership?.organization.name ?? "No organization"}</div>
          <div className="text-muted-foreground">
            {me.firstName} {me.lastName}
          </div>
        </div>
        <Button variant="ghost" size="icon" onClick={signOut} aria-label="Sign out">
          <LogOutIcon />
        </Button>
      </header>

      <main className="flex flex-1 flex-col items-center justify-center gap-8 px-4">
        <div className="text-center">
          <div className="text-6xl font-semibold tabular-nums tracking-tight">
            {now.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })}
          </div>
          <div className="mt-1 text-muted-foreground">
            {now.toLocaleDateString([], { weekday: "long", day: "numeric", month: "long" })}
          </div>
        </div>

        <Card className="w-full max-w-sm">
          <CardContent className="flex flex-col gap-3">
            <Button size="lg" className="h-16 text-lg" disabled>
              Clock in
            </Button>
            <p className="text-center text-sm text-muted-foreground">
              Time tracking isn't connected yet. Your shifts will be logged here once the module is built.
            </p>
          </CardContent>
        </Card>
      </main>
    </div>
  )
}
