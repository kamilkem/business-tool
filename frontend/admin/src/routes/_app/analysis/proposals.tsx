import { createFileRoute, useRouter, type ErrorComponentProps } from "@tanstack/react-router"
import { TriangleAlertIcon } from "lucide-react"
import { useTranslation } from "react-i18next"

import { Alert, AlertDescription, AlertTitle } from "@business-tool/ui/components/alert"
import { Button } from "@business-tool/ui/components/button"
import { Card, CardContent } from "@business-tool/ui/components/card"
import { Skeleton } from "@business-tool/ui/components/skeleton"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"

import { AnalysisNav } from "@/components/analysis-nav"
import { ProductProposals } from "@/components/product-proposals"
import { requireCapability } from "@/lib/access"
import { productCategoriesQuery, productProposalsQuery } from "@/lib/queries"

/*
 * The proposed products (#269, ADR 0044): what the model suggests for the purchased items that matched
 * nothing in the catalog, one decision each for the owner: create the product, add the items to a
 * product that exists, or dismiss the proposal. It was a card above the review queue and is a page of
 * its own; the tab's badge counts the open proposals.
 */
export const Route = createFileRoute("/_app/analysis/proposals")({
  beforeLoad: requireCapability("analysis"),
  loader: async ({ context }) => {
    const { organization } = context.membership
    await Promise.all([
      context.queryClient.ensureQueryData(productProposalsQuery(organization.id)),
      // The create and add-to dialogs' category picker.
      context.queryClient.ensureQueryData(productCategoriesQuery(organization.id)),
    ])
  },
  pendingComponent: ProposalsPending,
  errorComponent: ProposalsError,
  component: ProposalsPage,
})

/** The navigation and heading every state of the page starts with. */
function PageHeading() {
  const { t } = useTranslation("admin")
  return (
    <div className="flex flex-col gap-3">
      <AnalysisNav />
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">{t("review.proposals.title")}</h1>
        <p className="text-sm text-muted-foreground">{t("review.proposals.description")}</p>
      </div>
    </div>
  )
}

function ProposalsPending() {
  return (
    <div className="flex flex-col gap-6" aria-busy="true">
      <PageHeading />
      <Card className="py-0">
        <CardContent className="flex flex-col gap-3 px-4 py-4">
          {Array.from({ length: 4 }, (_, index) => (
            <Skeleton key={index} className="h-14 w-full" />
          ))}
        </CardContent>
      </Card>
    </div>
  )
}

function ProposalsError({ error, reset }: ErrorComponentProps) {
  const { t } = useTranslation("admin")
  const router = useRouter()
  return (
    <div className="flex flex-col gap-6">
      <PageHeading />
      <Alert variant="destructive">
        <TriangleAlertIcon />
        <AlertTitle>{t("review.proposals.error.title")}</AlertTitle>
        <AlertDescription className="flex flex-col items-start gap-3">
          <span>{requestErrorMessage(error)}</span>
          <Button
            variant="outline"
            size="sm"
            onClick={() => {
              reset()
              void router.invalidate()
            }}
          >
            {t("review.proposals.error.retry")}
          </Button>
        </AlertDescription>
      </Alert>
    </div>
  )
}

function ProposalsPage() {
  const { membership } = Route.useRouteContext()

  return (
    <div className="flex flex-col gap-6">
      <PageHeading />
      <ProductProposals organizationId={membership.organization.id} />
    </div>
  )
}
