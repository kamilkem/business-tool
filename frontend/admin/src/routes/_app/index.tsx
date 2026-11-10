import { createFileRoute } from "@tanstack/react-router"

import { Badge } from "@business-tool/ui/components/badge"
import { Card, CardAction, CardDescription, CardHeader, CardTitle } from "@business-tool/ui/components/card"

import { modules } from "@/lib/modules"

export const Route = createFileRoute("/_app/")({
  component: Dashboard,
})

function Dashboard() {
  const { me } = Route.useRouteContext()

  return (
    <div className="mx-auto flex max-w-5xl flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Hello, {me.firstName || me.email}</h1>
        <p className="text-sm text-muted-foreground">Modules will light up here as they are built.</p>
      </div>
      <div className="grid gap-4 sm:grid-cols-2">
        {modules.map((module) => (
          <Card key={module.key}>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <module.icon className="size-4 text-muted-foreground" />
                {module.title}
              </CardTitle>
              <CardDescription>{module.description}</CardDescription>
              <CardAction>
                <Badge variant="secondary">Coming soon</Badge>
              </CardAction>
            </CardHeader>
          </Card>
        ))}
      </div>
    </div>
  )
}
