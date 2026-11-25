import { useState } from "react"
import { unwrap } from "@business-tool/api-client"
import { useMutation, useQueryClient, useSuspenseQuery } from "@tanstack/react-query"
import { createFileRoute } from "@tanstack/react-router"
import { ChevronRightIcon, MoreHorizontalIcon, PencilIcon, PlusIcon, Trash2Icon } from "lucide-react"
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
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@business-tool/ui/components/dropdown-menu"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"

import { ExpensesNav } from "@/components/expenses-nav"
import { ProductCategoryDialog } from "@/components/product-category-dialog"
import { api } from "@/lib/api"
import { requireCapability } from "@/lib/access"
import { type CategoryNode, type ProductCategory, buildTree, withoutCategory } from "@/lib/product-categories"
import { productCategoriesQuery } from "@/lib/queries"

/*
 * The product category tree of the Purchasing module (ADR 0041 §5), next to the invoices
 * (/expenses) in ExpensesNav: what the organization buys is grouped in categories of any depth.
 * A category is deleted while it has no subcategory and no product; there is no archive.
 */
export const Route = createFileRoute("/_app/expenses/categories")({
  beforeLoad: requireCapability("expenses"),
  loader: ({ context }) => context.queryClient.ensureQueryData(productCategoriesQuery(context.membership.organization.id)),
  component: ProductCategoriesPage,
})

/** What the dialog is open for: a new category under a parent (null: top level), or an existing one. */
type DialogTarget = { kind: "add"; parentId: string | null } | { kind: "edit"; category: ProductCategory }

function ProductCategoriesPage() {
  const { membership } = Route.useRouteContext()
  const organizationId = membership.organization.id
  const queryClient = useQueryClient()
  const { t } = useTranslation("admin")
  const listKey = productCategoriesQuery(organizationId).queryKey
  const { data: categories } = useSuspenseQuery(productCategoriesQuery(organizationId))
  const tree = buildTree(categories)

  // The ids of the opened categories; every category starts closed.
  const [expanded, setExpanded] = useState<ReadonlySet<string>>(new Set())
  const [dialogOpen, setDialogOpen] = useState(false)
  // Both kept while their dialog closes, so its title doesn't change during the animation.
  const [target, setTarget] = useState<DialogTarget>({ kind: "add", parentId: null })
  const [deleting, setDeleting] = useState<ProductCategory | null>(null)
  const [confirmOpen, setConfirmOpen] = useState(false)

  const parentsWithChildren = categories.flatMap((c) => (c.parentId === null ? [] : [c.parentId]))
  const allExpanded = parentsWithChildren.every((id) => expanded.has(id))

  function open(next: DialogTarget) {
    setTarget(next)
    setDialogOpen(true)
  }

  function toggle(id: string) {
    setExpanded((current) => {
      const next = new Set(current)
      if (!next.delete(id)) next.add(id)
      return next
    })
  }

  function reveal(parentId: string | null) {
    if (parentId !== null) setExpanded((current) => new Set(current).add(parentId))
  }

  const remove = useMutation({
    mutationFn: (category: ProductCategory) =>
      unwrap(api.DELETE("/api/product-categories/{id}", { params: { path: { id: category.id } } })),
    onSuccess: (_, category) => {
      queryClient.setQueryData(listKey, (list) => (list ? withoutCategory(list, category.id) : list))
      toast.success(t("categories.toast.deleted", { name: category.name }))
    },
    onError: async (error) => {
      // A 409 says why: it has subcategories or products. Others: it is gone already, or no access.
      toast.error(t("categories.error.delete"), { description: requestErrorMessage(error) })
      await queryClient.invalidateQueries({ queryKey: listKey })
    },
  })

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div className="flex flex-col gap-3">
          <ExpensesNav />
          <div>
            <h1 className="text-2xl font-semibold tracking-tight">{t("categories.title")}</h1>
            <p className="text-sm text-muted-foreground">{t("categories.description")}</p>
          </div>
        </div>
        <div className="flex flex-wrap gap-2">
          {parentsWithChildren.length > 0 && (
            <Button
              variant="outline"
              onClick={() => setExpanded(allExpanded ? new Set() : new Set(parentsWithChildren))}
            >
              {allExpanded ? t("categories.collapseAll") : t("categories.expandAll")}
            </Button>
          )}
          <Button onClick={() => open({ kind: "add", parentId: null })}>
            <PlusIcon />
            {t("categories.add")}
          </Button>
        </div>
      </div>

      <Card className="py-0">
        <CardContent className="px-0">
          {tree.length === 0 ? (
            <p className="px-4 py-8 text-center text-sm text-muted-foreground">{t("categories.none")}</p>
          ) : (
            <ul className="divide-y">
              {tree.map((node) => (
                <CategoryBranch
                  key={node.category.id}
                  node={node}
                  depth={0}
                  expanded={expanded}
                  busyId={remove.isPending ? remove.variables.id : null}
                  onToggle={toggle}
                  onAdd={(parent) => open({ kind: "add", parentId: parent.id })}
                  onEdit={(category) => open({ kind: "edit", category })}
                  onDelete={(category) => {
                    setDeleting(category)
                    setConfirmOpen(true)
                  }}
                />
              ))}
            </ul>
          )}
        </CardContent>
      </Card>

      <ProductCategoryDialog
        organizationId={organizationId}
        open={dialogOpen}
        onOpenChange={setDialogOpen}
        categories={categories}
        category={target.kind === "edit" ? target.category : null}
        parentId={target.kind === "add" ? target.parentId : null}
        onSaved={(saved) => reveal(saved.parentId)}
      />

      <AlertDialog open={confirmOpen} onOpenChange={setConfirmOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("categories.confirmDelete.title", { name: deleting?.name ?? "" })}</AlertDialogTitle>
            <AlertDialogDescription>{t("categories.confirmDelete.description")}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t("common.cancel")}</AlertDialogCancel>
            <AlertDialogAction
              variant="destructive"
              onClick={() => {
                if (deleting) remove.mutate(deleting)
              }}
            >
              {t("categories.delete")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}

/** The room each level of the tree takes, in px: the tree indents without growing sideways. */
const INDENT = 16

interface CategoryBranchProps {
  node: CategoryNode
  depth: number
  expanded: ReadonlySet<string>
  busyId: string | null
  onToggle: (id: string) => void
  onAdd: (parent: ProductCategory) => void
  onEdit: (category: ProductCategory) => void
  onDelete: (category: ProductCategory) => void
}

/** A category's row, and its subcategories below it while it is open. */
function CategoryBranch({ node, depth, expanded, busyId, onToggle, onAdd, onEdit, onDelete }: CategoryBranchProps) {
  const { t } = useTranslation("admin")
  const { category, children } = node
  const hasChildren = children.length > 0
  const open = hasChildren && expanded.has(category.id)

  return (
    <li>
      <div className="flex min-h-12 items-center gap-1 py-1 pr-3" style={{ paddingLeft: 8 + depth * INDENT }}>
        {hasChildren ? (
          <Button
            variant="ghost"
            size="icon-sm"
            aria-expanded={open}
            aria-label={open ? t("categories.collapse", { name: category.name }) : t("categories.expand", { name: category.name })}
            onClick={() => onToggle(category.id)}
          >
            <ChevronRightIcon className={`transition-transform ${open ? "rotate-90" : ""}`} />
          </Button>
        ) : (
          <span className="size-8 shrink-0" aria-hidden />
        )}
        <div className="flex min-w-0 flex-1 items-baseline gap-2">
          <span className="truncate font-medium">{category.name}</span>
          {hasChildren && (
            <span className="shrink-0 text-xs text-muted-foreground">
              {t("categories.subcategories", { count: children.length })}
            </span>
          )}
        </div>
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
          <DropdownMenuContent align="end" className="min-w-52">
            <DropdownMenuItem onSelect={() => onAdd(category)}>
              <PlusIcon />
              {t("categories.addSub")}
            </DropdownMenuItem>
            <DropdownMenuItem onSelect={() => onEdit(category)}>
              <PencilIcon />
              {t("categories.edit")}
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            {/* Known to be refused while it has subcategories; products are the API's to say (409). */}
            <DropdownMenuItem variant="destructive" disabled={hasChildren} onSelect={() => onDelete(category)}>
              <Trash2Icon />
              {t("categories.delete")}
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
      {open && (
        <ul className="divide-y border-t">
          {children.map((child) => (
            <CategoryBranch
              key={child.category.id}
              node={child}
              depth={depth + 1}
              expanded={expanded}
              busyId={busyId}
              onToggle={onToggle}
              onAdd={onAdd}
              onEdit={onEdit}
              onDelete={onDelete}
            />
          ))}
        </ul>
      )}
    </li>
  )
}
