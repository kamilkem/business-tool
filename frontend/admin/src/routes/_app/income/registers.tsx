import { useState } from "react"
import { unwrap } from "@business-tool/api-client"
import { useMutation, useQueryClient, useSuspenseQuery } from "@tanstack/react-query"
import { createFileRoute } from "@tanstack/react-router"
import { ArchiveIcon, ChevronRightIcon, MoreHorizontalIcon, PencilIcon, PlusIcon, Trash2Icon } from "lucide-react"
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
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@business-tool/ui/components/table"
import { useDateTimeFormat } from "@business-tool/ui/hooks/use-organization-time-zone"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"

import { IncomeNav } from "@/components/income-nav"
import { RegisterDialog } from "@/components/register-dialog"
import { api } from "@/lib/api"
import { requireCapability } from "@/lib/access"
import { registersQuery } from "@/lib/queries"
import { type Register, splitRegisters, withoutRegister, withRegister } from "@/lib/registers"

/*
 * Registers of the Income module (ADR 0022 §2). One without revenue entries can be deleted; one
 * with entries is deactivated instead (ADR 0032). The path /income/registers is stable: the Income
 * page links here when the organization has no active register.
 */
export const Route = createFileRoute("/_app/income/registers")({
  beforeLoad: requireCapability("income"),
  loader: ({ context }) => context.queryClient.ensureQueryData(registersQuery(context.membership.organization.id, true)),
  component: RegistersPage,
})

function RegistersPage() {
  const { membership } = Route.useRouteContext()
  const organizationId = membership.organization.id
  const queryClient = useQueryClient()
  const { t } = useTranslation("admin")
  const listKey = registersQuery(organizationId, true).queryKey
  const { data: registers } = useSuspenseQuery(registersQuery(organizationId, true))
  const { active, deactivated } = splitRegisters(registers)

  const [dialogOpen, setDialogOpen] = useState(false)
  // Both kept while their dialog closes, so its title doesn't change during the animation.
  const [renaming, setRenaming] = useState<Register | null>(null)
  const [deactivating, setDeactivating] = useState<Register | null>(null)
  const [confirmOpen, setConfirmOpen] = useState(false)
  const [deleting, setDeleting] = useState<Register | null>(null)
  const [confirmDeleteOpen, setConfirmDeleteOpen] = useState(false)

  function askToDelete(register: Register) {
    setDeleting(register)
    setConfirmDeleteOpen(true)
  }

  function askToDeactivate(register: Register) {
    setDeactivating(register)
    setConfirmOpen(true)
  }

  function openAdd() {
    setRenaming(null)
    setDialogOpen(true)
  }

  function openRename(register: Register) {
    setRenaming(register)
    setDialogOpen(true)
  }

  const deactivate = useMutation({
    mutationFn: (register: Register) =>
      unwrap(api.POST("/api/registers/{id}/deactivate", { params: { path: { id: register.id } } })),
    onSuccess: async (saved) => {
      queryClient.setQueryData(listKey, (list) => (list ? withRegister(list, saved) : list))
      await queryClient.invalidateQueries({ queryKey: registersQuery(organizationId, false).queryKey })
      toast.success(t("registers.toast.deactivated", { name: saved.name }))
    },
    onError: async (error) => {
      toast.error(t("registers.error.deactivate"), { description: requestErrorMessage(error) })
      await queryClient.invalidateQueries({ queryKey: ["registers", organizationId] })
    },
  })

  const remove = useMutation({
    mutationFn: (register: Register) =>
      unwrap(api.DELETE("/api/registers/{id}", { params: { path: { id: register.id } } })),
    onSuccess: async (_, register) => {
      queryClient.setQueryData(listKey, (list) => (list ? withoutRegister(list, register.id) : list))
      await queryClient.invalidateQueries({ queryKey: registersQuery(organizationId, false).queryKey })
      toast.success(t("registers.toast.deleted", { name: register.name }))
    },
    onError: async (error) => {
      // A 409: an entry was recorded for it in the meantime; it can still be deactivated.
      toast.error(t("registers.error.delete"), { description: requestErrorMessage(error) })
      await queryClient.invalidateQueries({ queryKey: ["registers", organizationId] })
    },
  })

  function busyId(): string | null {
    if (deactivate.isPending) return deactivate.variables.id
    if (remove.isPending) return remove.variables.id
    return null
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div className="flex flex-col gap-3">
          <IncomeNav />
          <div>
            <h1 className="text-2xl font-semibold tracking-tight">{t("registers.title")}</h1>
            <p className="text-sm text-muted-foreground">{t("registers.description")}</p>
          </div>
        </div>
        <Button onClick={openAdd}>
          <PlusIcon />
          {t("registers.add")}
        </Button>
      </div>

      <Card className="py-0">
        <CardContent className="px-0">
          {active.length === 0 ? (
            <p className="px-4 py-8 text-center text-sm text-muted-foreground">
              {t("registers.noneActive")}
            </p>
          ) : (
            <RegistersTable
              registers={active}
              busyId={busyId()}
              onRename={openRename}
              onDeactivate={askToDeactivate}
              onDelete={askToDelete}
            />
          )}
        </CardContent>
      </Card>

      {deactivated.length > 0 && (
        <Collapsible className="flex flex-col gap-3">
          <CollapsibleTrigger asChild>
            <Button variant="ghost" className="group self-start px-2 text-muted-foreground">
              <ChevronRightIcon className="transition-transform group-data-[state=open]:rotate-90" />
              {t("registers.deactivatedCount", { count: deactivated.length })}
            </Button>
          </CollapsibleTrigger>
          <CollapsibleContent className="flex flex-col gap-3">
            <p className="px-2 text-sm text-muted-foreground">{t("registers.deactivatedDescription")}</p>
            <Card className="py-0">
              <CardContent className="px-0">
                <RegistersTable registers={deactivated} busyId={busyId()} onRename={openRename} onDelete={askToDelete} />
              </CardContent>
            </Card>
          </CollapsibleContent>
        </Collapsible>
      )}

      <RegisterDialog
        organizationId={organizationId}
        open={dialogOpen}
        onOpenChange={setDialogOpen}
        register={renaming}
      />

      <AlertDialog open={confirmOpen} onOpenChange={setConfirmOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("registers.confirm.title", { name: deactivating?.name ?? "" })}</AlertDialogTitle>
            <AlertDialogDescription>{t("registers.confirm.description")}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t("common.cancel")}</AlertDialogCancel>
            <AlertDialogAction
              variant="destructive"
              onClick={() => {
                if (deactivating) deactivate.mutate(deactivating)
              }}
            >
              {t("registers.deactivate")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      <AlertDialog open={confirmDeleteOpen} onOpenChange={setConfirmDeleteOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("registers.confirmDelete.title", { name: deleting?.name ?? "" })}</AlertDialogTitle>
            <AlertDialogDescription>{t("registers.confirmDelete.description")}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t("common.cancel")}</AlertDialogCancel>
            <AlertDialogAction
              variant="destructive"
              onClick={() => {
                if (deleting) remove.mutate(deleting)
              }}
            >
              {t("registers.delete")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}

interface RegistersTableProps {
  registers: Register[]
  busyId: string | null
  onRename: (register: Register) => void
  /** Only for active registers. */
  onDeactivate?: (register: Register) => void
  /** Offered only for registers without revenue entries. */
  onDelete: (register: Register) => void
}

function RegistersTable({ registers, busyId, onRename, onDeactivate, onDelete }: RegistersTableProps) {
  const format = useDateTimeFormat()
  const { t } = useTranslation("admin")

  return (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead className="w-1/2 pl-4">{t("registers.dialog.name")}</TableHead>
          <TableHead>{onDeactivate ? t("registers.added") : t("registers.deactivatedOn")}</TableHead>
          <TableHead className="pr-4">
            <span className="sr-only">{t("common.actions")}</span>
          </TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {registers.map((register) => (
          <TableRow key={register.id} className={register.active ? undefined : "text-muted-foreground"}>
            <TableCell className="pl-4">
              <div className="flex flex-wrap items-baseline gap-x-2">
                <span className="font-medium">{register.name}</span>
                {!register.used && <span className="text-xs text-muted-foreground">{t("registers.unused")}</span>}
              </div>
            </TableCell>
            <TableCell>{format.date(register.deactivatedAt ?? register.createdAt)}</TableCell>
            <TableCell className="w-12 pr-4 text-right">
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button
                    variant="ghost"
                    size="icon-sm"
                    disabled={busyId === register.id}
                    aria-label={t("team.actionsFor", { name: register.name })}
                  >
                    <MoreHorizontalIcon />
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                  <DropdownMenuItem onSelect={() => onRename(register)}>
                    <PencilIcon />
                    {t("registers.rename")}
                  </DropdownMenuItem>
                  {onDeactivate && (
                    <DropdownMenuItem variant="destructive" onSelect={() => onDeactivate(register)}>
                      <ArchiveIcon />
                      {t("registers.deactivate")}
                    </DropdownMenuItem>
                  )}
                  {!register.used && (
                    <>
                      <DropdownMenuSeparator />
                      <DropdownMenuItem variant="destructive" onSelect={() => onDelete(register)}>
                        <Trash2Icon />
                        {t("registers.delete")}
                      </DropdownMenuItem>
                    </>
                  )}
                </DropdownMenuContent>
              </DropdownMenu>
            </TableCell>
          </TableRow>
        ))}
      </TableBody>
    </Table>
  )
}
