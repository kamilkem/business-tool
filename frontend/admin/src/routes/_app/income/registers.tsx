import { useState } from "react"
import { unwrap } from "@business-tool/api-client"
import { useMutation, useQueryClient, useSuspenseQuery } from "@tanstack/react-query"
import { createFileRoute } from "@tanstack/react-router"
import { ArchiveIcon, ChevronRightIcon, MoreHorizontalIcon, PencilIcon, PlusIcon } from "lucide-react"
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

import { IncomeNav } from "@/components/income-nav"
import { RegisterDialog } from "@/components/register-dialog"
import { api } from "@/lib/api"
import { requireCapability } from "@/lib/access"
import { registersQuery } from "@/lib/queries"
import { type Register, splitRegisters, withRegister } from "@/lib/registers"

/*
 * Registers of the Income module (ADR 0022 §2). The path /income/registers is stable: the Income
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
  const listKey = registersQuery(organizationId, true).queryKey
  const { data: registers } = useSuspenseQuery(registersQuery(organizationId, true))
  const { active, deactivated } = splitRegisters(registers)

  const [dialogOpen, setDialogOpen] = useState(false)
  // Both kept while their dialog closes, so its title doesn't change during the animation.
  const [renaming, setRenaming] = useState<Register | null>(null)
  const [deactivating, setDeactivating] = useState<Register | null>(null)
  const [confirmOpen, setConfirmOpen] = useState(false)

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
      toast.success(`${saved.name} deactivated`)
    },
    onError: async (error) => {
      toast.error("Could not deactivate the register", { description: error.message })
      await queryClient.invalidateQueries({ queryKey: ["registers", organizationId] })
    },
  })

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div className="flex flex-col gap-3">
          <IncomeNav />
          <div>
            <h1 className="text-2xl font-semibold tracking-tight">Registers</h1>
            <p className="text-sm text-muted-foreground">
              Tills with their own fiscal printer. Income is entered per register, one daily report each.
            </p>
          </div>
        </div>
        <Button onClick={openAdd}>
          <PlusIcon />
          Add register
        </Button>
      </div>

      <Card className="py-0">
        <CardContent className="px-0">
          {active.length === 0 ? (
            <p className="px-4 py-8 text-center text-sm text-muted-foreground">
              No active registers. Add one before entering income.
            </p>
          ) : (
            <RegistersTable
              registers={active}
              busyId={deactivate.isPending ? deactivate.variables.id : null}
              onRename={openRename}
              onDeactivate={askToDeactivate}
            />
          )}
        </CardContent>
      </Card>

      {deactivated.length > 0 && (
        <Collapsible className="flex flex-col gap-3">
          <CollapsibleTrigger asChild>
            <Button variant="ghost" className="group self-start px-2 text-muted-foreground">
              <ChevronRightIcon className="transition-transform group-data-[state=open]:rotate-90" />
              Deactivated registers ({deactivated.length})
            </Button>
          </CollapsibleTrigger>
          <CollapsibleContent className="flex flex-col gap-3">
            <p className="px-2 text-sm text-muted-foreground">
              They take no new entries. Their entries stay on the Income page, under their names.
            </p>
            <Card className="py-0">
              <CardContent className="px-0">
                <RegistersTable registers={deactivated} busyId={null} onRename={openRename} />
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
            <AlertDialogTitle>Deactivate {deactivating?.name}?</AlertDialogTitle>
            <AlertDialogDescription>
              It takes no new entries from now on. Its existing entries stay on the Income page, and you can still
              correct them. Registers can't be reactivated, and the name stays taken.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              variant="destructive"
              onClick={() => {
                if (deactivating) deactivate.mutate(deactivating)
              }}
            >
              Deactivate
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
}

function RegistersTable({ registers, busyId, onRename, onDeactivate }: RegistersTableProps) {
  const format = useDateTimeFormat()

  return (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead className="w-1/2 pl-4">Name</TableHead>
          <TableHead>{onDeactivate ? "Added" : "Deactivated"}</TableHead>
          <TableHead className="pr-4">
            <span className="sr-only">Actions</span>
          </TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {registers.map((register) => (
          <TableRow key={register.id} className={register.active ? undefined : "text-muted-foreground"}>
            <TableCell className="pl-4 font-medium">{register.name}</TableCell>
            <TableCell>{format.date(register.deactivatedAt ?? register.createdAt)}</TableCell>
            <TableCell className="w-12 pr-4 text-right">
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button
                    variant="ghost"
                    size="icon-sm"
                    disabled={busyId === register.id}
                    aria-label={`Actions for ${register.name}`}
                  >
                    <MoreHorizontalIcon />
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                  <DropdownMenuItem onSelect={() => onRename(register)}>
                    <PencilIcon />
                    Rename
                  </DropdownMenuItem>
                  {onDeactivate && (
                    <DropdownMenuItem variant="destructive" onSelect={() => onDeactivate(register)}>
                      <ArchiveIcon />
                      Deactivate
                    </DropdownMenuItem>
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
