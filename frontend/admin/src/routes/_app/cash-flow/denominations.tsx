import { useState } from "react"
import { unwrap } from "@business-tool/api-client"
import { useMutation, useQueryClient, useSuspenseQuery } from "@tanstack/react-query"
import { createFileRoute } from "@tanstack/react-router"
import { ArchiveIcon, ArchiveRestoreIcon, ChevronRightIcon, MoreHorizontalIcon, PlusIcon, Trash2Icon } from "lucide-react"
import { useTranslation } from "react-i18next"
import { toast } from "sonner"

import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@business-tool/ui/components/alert-dialog"
import { Button } from "@business-tool/ui/components/button"
import { Card, CardContent } from "@business-tool/ui/components/card"
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@business-tool/ui/components/collapsible"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@business-tool/ui/components/dropdown-menu"
import { useMoneyFormat, useOrganizationCurrency } from "@business-tool/ui/hooks/use-organization-currency"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"

import { CashFlowNav } from "@/components/cash-flow-nav"
import { DenominationDialog } from "@/components/denomination-dialog"
import { api } from "@/lib/api"
import { requireCapability } from "@/lib/access"
import {
  type Denomination,
  denominationsByKind,
  splitDenominations,
  withDenomination,
  withoutDenomination,
} from "@/lib/denominations"
import { denominationsQuery, invalidateDayCloseForm } from "@/lib/queries"

/*
 * Denominations of the Cash flow module (ADR 0033 §2): the coins and notes of the organization's
 * currency that a close counts. One no close or movement uses can be deleted; a used one is
 * deactivated, and can be reactivated.
 */
export const Route = createFileRoute("/_app/cash-flow/denominations")({
  beforeLoad: requireCapability("cashFlow"),
  loader: ({ context }) => context.queryClient.ensureQueryData(denominationsQuery(context.membership.organization.id)),
  component: DenominationsPage,
})

function DenominationsPage() {
  const { membership } = Route.useRouteContext()
  const organizationId = membership.organization.id
  const queryClient = useQueryClient()
  const { t } = useTranslation("admin")
  const money = useMoneyFormat()
  const currency = useOrganizationCurrency()
  const listKey = denominationsQuery(organizationId).queryKey
  const { data: denominations } = useSuspenseQuery(denominationsQuery(organizationId))
  const { active, deactivated } = splitDenominations(denominations)

  const [dialogOpen, setDialogOpen] = useState(false)
  // Kept while the dialog closes, so its title doesn't change during the animation.
  const [deleting, setDeleting] = useState<Denomination | null>(null)
  const [confirmOpen, setConfirmOpen] = useState(false)

  function askToDelete(denomination: Denomination) {
    setDeleting(denomination)
    setConfirmOpen(true)
  }

  async function refetch() {
    await queryClient.invalidateQueries({ queryKey: listKey })
  }

  const toggle = useMutation({
    mutationFn: ({ denomination, to }: { denomination: Denomination; to: "deactivate" | "reactivate" }) =>
      to === "deactivate"
        ? unwrap(api.POST("/api/denominations/{id}/deactivate", { params: { path: { id: denomination.id } } }))
        : unwrap(api.POST("/api/denominations/{id}/reactivate", { params: { path: { id: denomination.id } } })),
    onSuccess: async (saved) => {
      queryClient.setQueryData(listKey, (list) => (list ? withDenomination(list, saved) : list))
      toast.success(
        saved.active
          ? t("denominations.toast.reactivated", { value: money(saved.value) })
          : t("denominations.toast.deactivated", { value: money(saved.value) }),
      )
      await invalidateDayCloseForm(queryClient, organizationId)
    },
    onError: async (error, { to }) => {
      toast.error(to === "deactivate" ? t("denominations.error.deactivate") : t("denominations.error.reactivate"), {
        description: requestErrorMessage(error),
      })
      await refetch()
    },
  })

  const remove = useMutation({
    mutationFn: (denomination: Denomination) =>
      unwrap(api.DELETE("/api/denominations/{id}", { params: { path: { id: denomination.id } } })),
    onSuccess: async (_, denomination) => {
      queryClient.setQueryData(listKey, (list) => (list ? withoutDenomination(list, denomination.id) : list))
      toast.success(t("denominations.toast.deleted", { value: money(denomination.value) }))
      await invalidateDayCloseForm(queryClient, organizationId)
    },
    onError: async (error) => {
      // A 409: a close or movement used it in the meantime; it can still be deactivated.
      toast.error(t("denominations.error.delete"), { description: requestErrorMessage(error) })
      await refetch()
    },
  })

  const busyId = toggle.isPending ? toggle.variables.denomination.id : remove.isPending ? remove.variables.id : null

  function rows(list: Denomination[], to: "deactivate" | "reactivate") {
    return list.map((denomination) => (
      <li key={denomination.id}>
        <DenominationRow
          denomination={denomination}
          busy={busyId === denomination.id}
          onToggle={(d) => toggle.mutate({ denomination: d, to })}
          onDelete={askToDelete}
        />
      </li>
    ))
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div className="flex min-w-0 flex-col gap-3">
          <CashFlowNav />
          <div>
            <h1 className="text-2xl font-semibold tracking-tight">{t("denominations.title")}</h1>
            <p className="text-sm text-muted-foreground">{t("denominations.description", { currency })}</p>
          </div>
        </div>
        <Button onClick={() => setDialogOpen(true)}>
          <PlusIcon />
          {t("denominations.add")}
        </Button>
      </div>

      {active.length === 0 ? (
        <Card className="py-0">
          <CardContent className="px-0">
            <p className="px-4 py-8 text-center text-sm text-muted-foreground">{t("denominations.noneActive", { currency })}</p>
          </CardContent>
        </Card>
      ) : (
        <div className="grid gap-6 md:grid-cols-2">
          {denominationsByKind(active).map((group) => (
            <section key={group.kind} className="flex flex-col gap-2">
              <h2 className="px-1 text-sm font-medium">
                {t(`denominations.group.${group.kind}`, { count: group.denominations.length })}
              </h2>
              <Card className="py-0">
                <CardContent className="px-0">
                  <ul className="divide-y" aria-label={t(`denominations.group.${group.kind}`, { count: group.denominations.length })}>
                    {rows(group.denominations, "deactivate")}
                  </ul>
                </CardContent>
              </Card>
            </section>
          ))}
        </div>
      )}

      {deactivated.length > 0 && (
        <Collapsible className="flex flex-col gap-3">
          <CollapsibleTrigger asChild>
            <Button variant="ghost" className="group self-start px-2 text-muted-foreground">
              <ChevronRightIcon className="transition-transform group-data-[state=open]:rotate-90" />
              {t("denominations.deactivatedCount", { count: deactivated.length })}
            </Button>
          </CollapsibleTrigger>
          <CollapsibleContent className="flex flex-col gap-3">
            <p className="px-2 text-sm text-muted-foreground">{t("denominations.deactivatedDescription")}</p>
            <Card className="py-0">
              <CardContent className="px-0">
                <ul className="divide-y" aria-label={t("denominations.deactivatedList")}>
                  {rows(deactivated, "reactivate")}
                </ul>
              </CardContent>
            </Card>
          </CollapsibleContent>
        </Collapsible>
      )}

      <DenominationDialog organizationId={organizationId} open={dialogOpen} onOpenChange={setDialogOpen} />

      <AlertDialog open={confirmOpen} onOpenChange={setConfirmOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              {t("denominations.confirmDelete.title", { value: deleting ? money(deleting.value) : "" })}
            </AlertDialogTitle>
            <AlertDialogDescription>{t("denominations.confirmDelete.description")}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t("common.cancel")}</AlertDialogCancel>
            <AlertDialogAction
              variant="destructive"
              onClick={() => {
                if (deleting) remove.mutate(deleting)
              }}
            >
              {t("denominations.delete")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}

interface DenominationRowProps {
  denomination: Denomination
  busy: boolean
  /** Deactivates an active denomination, reactivates a deactivated one. */
  onToggle: (denomination: Denomination) => void
  /** Offered only for denominations no close or movement uses. */
  onDelete: (denomination: Denomination) => void
}

function DenominationRow({ denomination, busy, onToggle, onDelete }: DenominationRowProps) {
  const { t } = useTranslation("admin")
  const money = useMoneyFormat()
  const label = money(denomination.value)

  return (
    <div className="flex min-h-12 items-center gap-2 py-1.5 pr-4 pl-4">
      <div className="flex min-w-0 flex-1 flex-wrap items-baseline gap-x-2">
        <span className={`font-medium tabular-nums ${denomination.active ? "" : "text-muted-foreground"}`}>{label}</span>
        <span className="text-xs text-muted-foreground">
          {[
            denomination.active ? null : t(`denominations.kind.${denomination.kind}`),
            denomination.used ? t("denominations.used") : t("denominations.unused"),
          ]
            .filter(Boolean)
            .join(" · ")}
        </span>
      </div>
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button variant="ghost" size="icon-sm" disabled={busy} aria-label={t("team.actionsFor", { name: label })}>
            <MoreHorizontalIcon />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          <DropdownMenuItem onSelect={() => onToggle(denomination)}>
            {denomination.active ? <ArchiveIcon /> : <ArchiveRestoreIcon />}
            {denomination.active ? t("denominations.deactivate") : t("denominations.reactivate")}
          </DropdownMenuItem>
          {!denomination.used && (
            <>
              <DropdownMenuSeparator />
              <DropdownMenuItem variant="destructive" onSelect={() => onDelete(denomination)}>
                <Trash2Icon />
                {t("denominations.delete")}
              </DropdownMenuItem>
            </>
          )}
        </DropdownMenuContent>
      </DropdownMenu>
    </div>
  )
}
