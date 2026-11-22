import { useState } from "react"
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
import { Badge } from "@business-tool/ui/components/badge"
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
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"

import { CashFlowNav } from "@/components/cash-flow-nav"
import { CashStorageDialog } from "@/components/cash-storage-dialog"
import { api } from "@/lib/api"
import { requireCapability } from "@/lib/access"
import { type CashStorage, splitCashStorages, withCashStorage, withoutCashStorage } from "@/lib/cash-storages"
import { cashStoragesQuery, invalidateDayCloseForm } from "@/lib/queries"

/*
 * Cash storages of the Cash flow module (ADR 0033 §3): where the cash is kept, each counted at the
 * close or taking deposits. A storage no close or movement refers to can be deleted; a used one is
 * deactivated, and can be reactivated.
 */
export const Route = createFileRoute("/_app/cash-flow/storages")({
  beforeLoad: requireCapability("cashFlow"),
  loader: ({ context }) => context.queryClient.ensureQueryData(cashStoragesQuery(context.membership.organization.id)),
  component: CashStoragesPage,
})

function CashStoragesPage() {
  const { membership } = Route.useRouteContext()
  const organizationId = membership.organization.id
  const queryClient = useQueryClient()
  const { t } = useTranslation("admin")
  const listKey = cashStoragesQuery(organizationId).queryKey
  const { data: storages } = useSuspenseQuery(cashStoragesQuery(organizationId))
  const { active, deactivated } = splitCashStorages(storages)

  const [dialogOpen, setDialogOpen] = useState(false)
  // Both kept while their dialog closes, so its title doesn't change during the animation.
  const [editing, setEditing] = useState<CashStorage | null>(null)
  const [deleting, setDeleting] = useState<CashStorage | null>(null)
  const [confirmOpen, setConfirmOpen] = useState(false)

  function openAdd() {
    setEditing(null)
    setDialogOpen(true)
  }

  function openEdit(storage: CashStorage) {
    setEditing(storage)
    setDialogOpen(true)
  }

  function askToDelete(storage: CashStorage) {
    setDeleting(storage)
    setConfirmOpen(true)
  }

  async function refetch() {
    await queryClient.invalidateQueries({ queryKey: listKey })
  }

  const toggle = useMutation({
    mutationFn: ({ storage, to }: { storage: CashStorage; to: "deactivate" | "reactivate" }) =>
      to === "deactivate"
        ? unwrap(api.POST("/api/cash-storages/{id}/deactivate", { params: { path: { id: storage.id } } }))
        : unwrap(api.POST("/api/cash-storages/{id}/reactivate", { params: { path: { id: storage.id } } })),
    onSuccess: async (saved) => {
      queryClient.setQueryData(listKey, (list) => (list ? withCashStorage(list, saved) : list))
      toast.success(
        saved.active
          ? t("cashStorages.toast.reactivated", { name: saved.name })
          : t("cashStorages.toast.deactivated", { name: saved.name }),
      )
      await invalidateDayCloseForm(queryClient, organizationId)
    },
    onError: async (error, { to }) => {
      toast.error(to === "deactivate" ? t("cashStorages.error.deactivate") : t("cashStorages.error.reactivate"), {
        description: requestErrorMessage(error),
      })
      await refetch()
    },
  })

  const remove = useMutation({
    mutationFn: (storage: CashStorage) => unwrap(api.DELETE("/api/cash-storages/{id}", { params: { path: { id: storage.id } } })),
    onSuccess: async (_, storage) => {
      queryClient.setQueryData(listKey, (list) => (list ? withoutCashStorage(list, storage.id) : list))
      toast.success(t("cashStorages.toast.deleted", { name: storage.name }))
      await invalidateDayCloseForm(queryClient, organizationId)
    },
    onError: async (error) => {
      // A 409: a close or movement used it in the meantime; it can still be deactivated.
      toast.error(t("cashStorages.error.delete"), { description: requestErrorMessage(error) })
      await refetch()
    },
  })

  const busyId = toggle.isPending ? toggle.variables.storage.id : remove.isPending ? remove.variables.id : null

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div className="flex min-w-0 flex-col gap-3">
          <CashFlowNav />
          <div>
            <h1 className="text-2xl font-semibold tracking-tight">{t("cashStorages.title")}</h1>
            <p className="text-sm text-muted-foreground">{t("cashStorages.description")}</p>
          </div>
        </div>
        <Button onClick={openAdd}>
          <PlusIcon />
          {t("cashStorages.add")}
        </Button>
      </div>

      <Card className="py-0">
        <CardContent className="px-0">
          {active.length === 0 ? (
            <p className="px-4 py-8 text-center text-sm text-muted-foreground">{t("cashStorages.noneActive")}</p>
          ) : (
            <ul className="divide-y" aria-label={t("cashStorages.activeList")}>
              {active.map((storage) => (
                <li key={storage.id}>
                  <StorageRow
                    storage={storage}
                    busy={busyId === storage.id}
                    onEdit={openEdit}
                    onToggle={(s) => toggle.mutate({ storage: s, to: "deactivate" })}
                    onDelete={askToDelete}
                  />
                </li>
              ))}
            </ul>
          )}
        </CardContent>
      </Card>

      {deactivated.length > 0 && (
        <Collapsible className="flex flex-col gap-3">
          <CollapsibleTrigger asChild>
            <Button variant="ghost" className="group self-start px-2 text-muted-foreground">
              <ChevronRightIcon className="transition-transform group-data-[state=open]:rotate-90" />
              {t("cashStorages.deactivatedCount", { count: deactivated.length })}
            </Button>
          </CollapsibleTrigger>
          <CollapsibleContent className="flex flex-col gap-3">
            <p className="px-2 text-sm text-muted-foreground">{t("cashStorages.deactivatedDescription")}</p>
            <Card className="py-0">
              <CardContent className="px-0">
                <ul className="divide-y" aria-label={t("cashStorages.deactivatedList")}>
                  {deactivated.map((storage) => (
                    <li key={storage.id}>
                      <StorageRow
                        storage={storage}
                        busy={busyId === storage.id}
                        onEdit={openEdit}
                        onToggle={(s) => toggle.mutate({ storage: s, to: "reactivate" })}
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

      <CashStorageDialog organizationId={organizationId} open={dialogOpen} onOpenChange={setDialogOpen} storage={editing} />

      <AlertDialog open={confirmOpen} onOpenChange={setConfirmOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("cashStorages.confirmDelete.title", { name: deleting?.name ?? "" })}</AlertDialogTitle>
            <AlertDialogDescription>{t("cashStorages.confirmDelete.description")}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t("common.cancel")}</AlertDialogCancel>
            <AlertDialogAction
              variant="destructive"
              onClick={() => {
                if (deleting) remove.mutate(deleting)
              }}
            >
              {t("cashStorages.delete")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}

interface StorageRowProps {
  storage: CashStorage
  busy: boolean
  onEdit: (storage: CashStorage) => void
  /** Deactivates an active storage, reactivates a deactivated one. */
  onToggle: (storage: CashStorage) => void
  /** Offered only for storages no close or movement refers to. */
  onDelete: (storage: CashStorage) => void
}

function StorageRow({ storage, busy, onEdit, onToggle, onDelete }: StorageRowProps) {
  const { t } = useTranslation("admin")

  return (
    <div className="flex min-h-12 items-center gap-2 py-2 pr-4 pl-4">
      <div className="flex min-w-0 flex-1 flex-col gap-0.5">
        <div className="flex min-w-0 flex-wrap items-baseline gap-x-2">
          <span className={`truncate font-medium ${storage.active ? "" : "text-muted-foreground"}`}>{storage.name}</span>
          <Badge variant={storage.mode === "counted" ? "secondary" : "outline"} className="self-center">
            {t(`cashStorages.mode.${storage.mode}`)}
          </Badge>
          <span className="text-xs text-muted-foreground">{storage.used ? t("cashStorages.used") : t("cashStorages.unused")}</span>
        </div>
        <p className="text-xs text-muted-foreground">{t(`cashStorages.modeDescription.${storage.mode}`)}</p>
      </div>
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button variant="ghost" size="icon-sm" disabled={busy} aria-label={t("team.actionsFor", { name: storage.name })}>
            <MoreHorizontalIcon />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          <DropdownMenuItem onSelect={() => onEdit(storage)}>
            <PencilIcon />
            {t("cashStorages.edit")}
          </DropdownMenuItem>
          <DropdownMenuItem onSelect={() => onToggle(storage)}>
            {storage.active ? <ArchiveIcon /> : <ArchiveRestoreIcon />}
            {storage.active ? t("cashStorages.deactivate") : t("cashStorages.reactivate")}
          </DropdownMenuItem>
          {!storage.used && (
            <>
              <DropdownMenuSeparator />
              <DropdownMenuItem variant="destructive" onSelect={() => onDelete(storage)}>
                <Trash2Icon />
                {t("cashStorages.delete")}
              </DropdownMenuItem>
            </>
          )}
        </DropdownMenuContent>
      </DropdownMenu>
    </div>
  )
}
