import { type ReactNode, useState } from "react"
import { unwrap } from "@business-tool/api-client"
import { useMutation, useQueryClient, useSuspenseQuery } from "@tanstack/react-query"
import { createFileRoute } from "@tanstack/react-router"
import {
  ArchiveIcon,
  ArchiveRestoreIcon,
  ChevronRightIcon,
  MoreHorizontalIcon,
  PencilIcon,
  PlusIcon,
  Trash2Icon,
} from "lucide-react"
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
import { SortableList } from "@business-tool/ui/components/sortable-list"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"

import { IncomeNav } from "@/components/income-nav"
import { PaymentMethodDialog } from "@/components/payment-method-dialog"
import { api } from "@/lib/api"
import { requireCapability } from "@/lib/access"
import {
  type PaymentMethod,
  moveItem,
  reorderedPaymentMethods,
  splitPaymentMethods,
  withPaymentMethod,
  withoutPaymentMethod,
} from "@/lib/payment-methods"
import { paymentMethodsQuery } from "@/lib/queries"

/*
 * Payment methods of the Income module (ADR 0030): the organization's own list, in the order the
 * entry form and the summaries show it. Reordered by drag and drop (ADR 0031). A method no entry
 * has used can be deleted; a used one is deactivated, and can be reactivated.
 */
export const Route = createFileRoute("/_app/income/payment-methods")({
  beforeLoad: requireCapability("income"),
  loader: ({ context }) => context.queryClient.ensureQueryData(paymentMethodsQuery(context.membership.organization.id)),
  component: PaymentMethodsPage,
})

function PaymentMethodsPage() {
  const { membership } = Route.useRouteContext()
  const organizationId = membership.organization.id
  const queryClient = useQueryClient()
  const { t } = useTranslation("admin")
  const listKey = paymentMethodsQuery(organizationId).queryKey
  const { data: methods } = useSuspenseQuery(paymentMethodsQuery(organizationId))
  const { active, deactivated } = splitPaymentMethods(methods)

  const [dialogOpen, setDialogOpen] = useState(false)
  // Both kept while their dialog closes, so its title doesn't change during the animation.
  const [renaming, setRenaming] = useState<PaymentMethod | null>(null)
  const [deleting, setDeleting] = useState<PaymentMethod | null>(null)
  const [confirmOpen, setConfirmOpen] = useState(false)

  function openAdd() {
    setRenaming(null)
    setDialogOpen(true)
  }

  function openRename(method: PaymentMethod) {
    setRenaming(method)
    setDialogOpen(true)
  }

  function askToDelete(method: PaymentMethod) {
    setDeleting(method)
    setConfirmOpen(true)
  }

  async function refetch() {
    await queryClient.invalidateQueries({ queryKey: listKey })
  }

  const toggle = useMutation({
    mutationFn: ({ method, to }: { method: PaymentMethod; to: "deactivate" | "reactivate" }) =>
      to === "deactivate"
        ? unwrap(api.POST("/api/payment-methods/{id}/deactivate", { params: { path: { id: method.id } } }))
        : unwrap(api.POST("/api/payment-methods/{id}/reactivate", { params: { path: { id: method.id } } })),
    onSuccess: (saved) => {
      queryClient.setQueryData(listKey, (list) => (list ? withPaymentMethod(list, saved) : list))
      toast.success(saved.active ? t("paymentMethods.toast.reactivated", { name: saved.name }) : t("paymentMethods.toast.deactivated", { name: saved.name }))
    },
    onError: async (error, { to }) => {
      toast.error(to === "deactivate" ? t("paymentMethods.error.deactivate") : t("paymentMethods.error.reactivate"), {
        description: requestErrorMessage(error),
      })
      await refetch()
    },
  })

  const remove = useMutation({
    mutationFn: (method: PaymentMethod) =>
      unwrap(api.DELETE("/api/payment-methods/{id}", { params: { path: { id: method.id } } })),
    onSuccess: (_, method) => {
      queryClient.setQueryData(listKey, (list) => (list ? withoutPaymentMethod(list, method.id) : list))
      toast.success(t("paymentMethods.toast.deleted", { name: method.name }))
    },
    onError: async (error) => {
      // A 409: an entry got a line for it in the meantime; it can still be deactivated.
      toast.error(t("paymentMethods.error.delete"), { description: requestErrorMessage(error) })
      await refetch()
    },
  })

  const reorder = useMutation({
    mutationFn: (ids: string[]) => unwrap(api.PUT("/api/payment-methods/order", { body: { paymentMethodIds: ids } })),
    onMutate: async (ids) => {
      await queryClient.cancelQueries({ queryKey: listKey })
      queryClient.setQueryData(listKey, (list) => (list ? reorderedPaymentMethods(list, ids) : list))
    },
    onError: async (error) => {
      // A 409 means the list changed meanwhile: show the server's.
      toast.error(t("paymentMethods.error.reorder"), { description: requestErrorMessage(error) })
      await refetch()
    },
  })

  const busyId = toggle.isPending ? toggle.variables.method.id : remove.isPending ? remove.variables.id : null

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div className="flex min-w-0 flex-col gap-3">
          <IncomeNav />
          <div>
            <h1 className="text-2xl font-semibold tracking-tight">{t("paymentMethods.title")}</h1>
            <p className="text-sm text-muted-foreground">{t("paymentMethods.description")}</p>
          </div>
        </div>
        <Button onClick={openAdd}>
          <PlusIcon />
          {t("paymentMethods.add")}
        </Button>
      </div>

      <Card className="py-0">
        <CardContent className="px-0">
          {active.length === 0 ? (
            <p className="px-4 py-8 text-center text-sm text-muted-foreground">{t("paymentMethods.noneActive")}</p>
          ) : (
            <SortableList
              aria-label={t("paymentMethods.activeList")}
              className="divide-y"
              items={active}
              getId={(method) => method.id}
              getLabel={(method) => method.name}
              disabled={reorder.isPending}
              onMove={(from, to) => reorder.mutate(moveItem(active, from, to).map((method) => method.id))}
              renderItem={(method, handle) => (
                <MethodRow
                  method={method}
                  handle={handle}
                  busy={busyId === method.id}
                  onRename={openRename}
                  onToggle={(m) => toggle.mutate({ method: m, to: "deactivate" })}
                  onDelete={askToDelete}
                />
              )}
            />
          )}
        </CardContent>
      </Card>
      {active.length > 1 && <p className="-mt-3 px-1 text-sm text-muted-foreground">{t("paymentMethods.reorderHint")}</p>}

      {deactivated.length > 0 && (
        <Collapsible className="flex flex-col gap-3">
          <CollapsibleTrigger asChild>
            <Button variant="ghost" className="group self-start px-2 text-muted-foreground">
              <ChevronRightIcon className="transition-transform group-data-[state=open]:rotate-90" />
              {t("paymentMethods.deactivatedCount", { count: deactivated.length })}
            </Button>
          </CollapsibleTrigger>
          <CollapsibleContent className="flex flex-col gap-3">
            <p className="px-2 text-sm text-muted-foreground">{t("paymentMethods.deactivatedDescription")}</p>
            <Card className="py-0">
              <CardContent className="px-0">
                <ul className="divide-y" aria-label={t("paymentMethods.deactivatedList")}>
                  {deactivated.map((method) => (
                    <li key={method.id}>
                      <MethodRow
                        method={method}
                        busy={busyId === method.id}
                        onRename={openRename}
                        onToggle={(m) => toggle.mutate({ method: m, to: "reactivate" })}
                        onDelete={askToDelete}
                      />
                    </li>
                  ))}
                </ul>
              </CardContent>
            </Card>
          </CollapsibleContent>
        </Collapsible>
      )}

      <PaymentMethodDialog organizationId={organizationId} open={dialogOpen} onOpenChange={setDialogOpen} method={renaming} />

      <AlertDialog open={confirmOpen} onOpenChange={setConfirmOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("paymentMethods.confirmDelete.title", { name: deleting?.name ?? "" })}</AlertDialogTitle>
            <AlertDialogDescription>{t("paymentMethods.confirmDelete.description")}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t("common.cancel")}</AlertDialogCancel>
            <AlertDialogAction
              variant="destructive"
              onClick={() => {
                if (deleting) remove.mutate(deleting)
              }}
            >
              {t("paymentMethods.delete")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}

interface MethodRowProps {
  method: PaymentMethod
  /** The drag handle, for active methods. */
  handle?: ReactNode
  busy: boolean
  onRename: (method: PaymentMethod) => void
  /** Deactivates an active method, reactivates a deactivated one. */
  onToggle: (method: PaymentMethod) => void
  onDelete: (method: PaymentMethod) => void
}

function MethodRow({ method, handle, busy, onRename, onToggle, onDelete }: MethodRowProps) {
  const { t } = useTranslation("admin")

  return (
    <div className={`flex min-h-12 items-center gap-2 py-1.5 pr-4 ${handle ? "pl-2" : "pl-4"}`}>
      {handle}
      <div className="flex min-w-0 flex-1 flex-wrap items-baseline gap-x-2">
        <span className={`truncate font-medium ${method.active ? "" : "text-muted-foreground"}`}>{method.name}</span>
        <span className="text-xs text-muted-foreground">{method.used ? t("paymentMethods.used") : t("paymentMethods.unused")}</span>
      </div>
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button variant="ghost" size="icon-sm" disabled={busy} aria-label={t("team.actionsFor", { name: method.name })}>
            <MoreHorizontalIcon />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          <DropdownMenuItem onSelect={() => onRename(method)}>
            <PencilIcon />
            {t("paymentMethods.rename")}
          </DropdownMenuItem>
          <DropdownMenuItem onSelect={() => onToggle(method)}>
            {method.active ? <ArchiveIcon /> : <ArchiveRestoreIcon />}
            {method.active ? t("paymentMethods.deactivate") : t("paymentMethods.reactivate")}
          </DropdownMenuItem>
          {!method.used && (
            <>
              <DropdownMenuSeparator />
              <DropdownMenuItem variant="destructive" onSelect={() => onDelete(method)}>
                <Trash2Icon />
                {t("paymentMethods.delete")}
              </DropdownMenuItem>
            </>
          )}
        </DropdownMenuContent>
      </DropdownMenu>
    </div>
  )
}
