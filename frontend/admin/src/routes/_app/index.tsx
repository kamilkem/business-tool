import { Link, createFileRoute } from "@tanstack/react-router"

import { Badge } from "@business-tool/ui/components/badge"
import { Button } from "@business-tool/ui/components/button"
import { Card, CardAction, CardDescription, CardHeader, CardTitle } from "@business-tool/ui/components/card"

import { modulesFor } from "@/lib/modules"

export const Route = createFileRoute("/_app/")({
  component: Dashboard,
})

function Dashboard() {
  const { me, membership } = Route.useRouteContext()

  return (
    <div className="mx-auto flex max-w-5xl flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Hello, {me.firstName || me.email}</h1>
        <p className="text-sm text-muted-foreground">Modules will light up here as they are built.</p>
      </div>
      <div className="grid gap-4 sm:grid-cols-2">
        {modulesFor(membership?.role).map((module) => (
          <Card key={module.key}>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <module.icon className="size-4 text-muted-foreground" />
                {module.title}
              </CardTitle>
              <CardDescription>{module.description}</CardDescription>
              <CardAction>
                {module.to ? (
                  <Button variant="outline" size="sm" asChild>
                    <Link to={module.to}>Open</Link>
                  </Button>
                ) : (
                  <Badge variant="secondary">Coming soon</Badge>
                )}
              </CardAction>
            </CardHeader>
          </Card>
        ))}
      </div>
    </div>
  )
}
