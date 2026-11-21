import { useState } from "react"
import { unwrap } from "@business-tool/api-client"
import { useMutation, useQueryClient, useSuspenseQuery } from "@tanstack/react-query"
import { createFileRoute } from "@tanstack/react-router"
import { ArchiveIcon, ArchiveRestoreIcon, ChevronRightIcon, MoreHorizontalIcon, PencilIcon, PlusIcon } from "lucide-react"
import { useTranslation } from "react-i18next"
import { toast } from "sonner"

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
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"

import { ExpenseCategoryDialog } from "@/components/expense-category-dialog"
import { ExpensesNav } from "@/components/expenses-nav"
import { api } from "@/lib/api"
import { requireCapability } from "@/lib/access"
import { type ExpenseCategory, splitCategories, withCategory } from "@/lib/expense-categories"
import { expenseCategoriesQuery } from "@/lib/queries"

/*
 * Expense categories of the Expenses module, next to the invoices (/expenses) in ExpensesNav.
 */
export const Route = createFileRoute("/_app/expenses/categories")({
  beforeLoad: requireCapability("expenses"),
  loader: ({ context }) =>
    context.queryClient.ensureQueryData(expenseCategoriesQuery(context.membership.organization.id, true)),
  component: ExpenseCategoriesPage,
})

type Change = "archive" | "restore"

function ExpenseCategoriesPage() {
  const { membership } = Route.useRouteContext()
  const organizationId = membership.organization.id
  const queryClient = useQueryClient()
  const { t } = useTranslation("admin")
  const listKey = expenseCategoriesQuery(organizationId, true).queryKey
  const { data: categories } = useSuspenseQuery(expenseCategoriesQuery(organizationId, true))
  const { active, archived } = splitCategories(categories)

  const [dialogOpen, setDialogOpen] = useState(false)
  // Kept while the dialog closes, so its title doesn't change during the animation.
  const [renaming, setRenaming] = useState<ExpenseCategory | null>(null)

  function openAdd() {
    setRenaming(null)
    setDialogOpen(true)
  }

  function openRename(category: ExpenseCategory) {
    setRenaming(category)
    setDialogOpen(true)
  }

  // Archiving is undone by restoring, so neither asks for confirmation; the toast offers the way back.
  const change = useMutation({
    mutationFn: ({ category, to }: { category: ExpenseCategory; to: Change }) =>
      to === "archive"
        ? unwrap(api.POST("/api/expense-categories/{id}/archive", { params: { path: { id: category.id } } }))
        : unwrap(api.POST("/api/expense-categories/{id}/restore", { params: { path: { id: category.id } } })),
    onSuccess: async (saved, { to }) => {
      queryClient.setQueryData(listKey, (list) => (list ? withCategory(list, saved) : list))
      await queryClient.invalidateQueries({ queryKey: expenseCategoriesQuery(organizationId, false).queryKey })
      if (to === "archive") {
        toast.success(t("categories.toast.archived", { name: saved.name }), {
          description: t("categories.toast.archivedDescription"),
          action: { label: t("categories.toast.undo"), onClick: () => change.mutate({ category: saved, to: "restore" }) },
        })
      } else {
        toast.success(t("categories.toast.restored", { name: saved.name }))
      }
    },
    onError: async (error, { to }) => {
      toast.error(t(`categories.error.${to}`), { description: requestErrorMessage(error) })
      await queryClient.invalidateQueries({ queryKey: ["expense-categories", organizationId] })
    },
  })
  const busyId = change.isPending ? change.variables.category.id : null

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div className="flex flex-col gap-3">
          <ExpensesNav />
          <div>
            <h1 className="text-2xl font-semibold tracking-tight">{t("categories.title")}</h1>
            <p className="text-sm text-muted-foreground">{t("categories.description")}</p>
          </div>
        </div>
        <Button onClick={openAdd}>
          <PlusIcon />
          {t("categories.add")}
        </Button>
      </div>

      <Card className="py-0">
        <CardContent className="px-0">
          {active.length === 0 ? (
            <p className="px-4 py-8 text-center text-sm text-muted-foreground">
              {t("categories.noneActive")}
            </p>
          ) : (
            <CategoriesTable
              categories={active}
              busyId={busyId}
              onRename={openRename}
              onChange={(category) => change.mutate({ category, to: "archive" })}
            />
          )}
        </CardContent>
      </Card>

      {archived.length > 0 && (
        <Collapsible className="flex flex-col gap-3">
          <CollapsibleTrigger asChild>
            <Button variant="ghost" className="group self-start px-2 text-muted-foreground">
              <ChevronRightIcon className="transition-transform group-data-[state=open]:rotate-90" />
              {t("categories.archivedCount", { count: archived.length })}
            </Button>
          </CollapsibleTrigger>
          <CollapsibleContent className="flex flex-col gap-3">
            <p className="px-2 text-sm text-muted-foreground">{t("categories.archivedDescription")}</p>
            <Card className="py-0">
              <CardContent className="px-0">
                <CategoriesTable
                  categories={archived}
                  busyId={busyId}
                  onRename={openRename}
                  onChange={(category) => change.mutate({ category, to: "restore" })}
                />
              </CardContent>
            </Card>
          </CollapsibleContent>
        </Collapsible>
      )}

      <ExpenseCategoryDialog
        organizationId={organizationId}
        open={dialogOpen}
        onOpenChange={setDialogOpen}
        category={renaming}
      />
    </div>
  )
}

interface CategoriesTableProps {
  /** All active or all archived. */
  categories: ExpenseCategory[]
  busyId: string | null
  onRename: (category: ExpenseCategory) => void
  /** Archives an active category, restores an archived one. */
  onChange: (category: ExpenseCategory) => void
}

function CategoriesTable({ categories, busyId, onRename, onChange }: CategoriesTableProps) {
  const format = useDateTimeFormat()
  const { t } = useTranslation("admin")

  return (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead className="w-1/2 pl-4">{t("registers.dialog.name")}</TableHead>
          <TableHead>{categories[0]?.archived ? t("categories.archivedOn") : t("categories.added")}</TableHead>
          <TableHead className="pr-4">
            <span className="sr-only">{t("common.actions")}</span>
          </TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {categories.map((category) => (
          <TableRow key={category.id} className={category.archived ? "text-muted-foreground" : undefined}>
            <TableCell className="pl-4 font-medium">{category.name}</TableCell>
            <TableCell>{format.date(category.archivedAt ?? category.createdAt)}</TableCell>
            <TableCell className="w-12 pr-4 text-right">
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button
                    variant="ghost"
                    size="icon-sm"
                    disabled={busyId === category.id}
                    aria-label={t("team.actionsFor", { name: category.name })}
                  >
                    <MoreHorizontalIcon />
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                  <DropdownMenuItem onSelect={() => onRename(category)}>
                    <PencilIcon />
                    {t("registers.rename")}
                  </DropdownMenuItem>
                  {category.archived ? (
                    <DropdownMenuItem onSelect={() => onChange(category)}>
                      <ArchiveRestoreIcon />
                      {t("categories.restore")}
                    </DropdownMenuItem>
                  ) : (
                    <DropdownMenuItem onSelect={() => onChange(category)}>
                      <ArchiveIcon />
                      {t("categories.archive")}
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
