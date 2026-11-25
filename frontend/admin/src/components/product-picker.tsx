import { useId, useMemo, useState } from "react"
import { SearchIcon } from "lucide-react"
import { useTranslation } from "react-i18next"

import { Badge } from "@business-tool/ui/components/badge"
import { Input } from "@business-tool/ui/components/input"
import { cn } from "@business-tool/ui/lib/utils"

import { type ProductCategory, parentChoices } from "@/lib/product-categories"
import { type Product, matchProducts } from "@/lib/product-prices"

interface ProductPickerProps {
  products: readonly Product[]
  categories: readonly ProductCategory[]
  /** The product being edited, which cannot be its own target; null when any product can be picked. */
  exceptId: string | null
  value: string | null
  onChange: (productId: string) => void
}

/**
 * Picks one of the organization's products: a search box over a short list (name, and its category
 * path to tell equal names apart). The match ignores case and diacritics.
 */
export function ProductPicker({ products, categories, exceptId, value, onChange }: ProductPickerProps) {
  const { t } = useTranslation("admin")
  const [search, setSearch] = useState("")
  const listId = useId()
  const paths = useMemo(() => new Map(parentChoices(categories, null).map((c) => [c.category.id, c.path])), [categories])
  const matches = useMemo(() => matchProducts(products, search, exceptId), [products, search, exceptId])

  return (
    <div className="flex flex-col gap-2">
      <div className="relative">
        <SearchIcon className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
        <Input
          type="search"
          value={search}
          onChange={(event) => setSearch(event.target.value)}
          placeholder={t("product.picker.search")}
          aria-label={t("product.picker.search")}
          aria-controls={listId}
          autoComplete="off"
          className="pl-8"
        />
      </div>
      <ul id={listId} className="max-h-56 overflow-y-auto rounded-md border" aria-label={t("product.picker.list")}>
        {matches.length === 0 ? (
          <li className="px-3 py-4 text-center text-sm text-muted-foreground">{t("product.picker.none")}</li>
        ) : (
          matches.map((product) => (
            <li key={product.id} className="border-b last:border-b-0">
              <button
                type="button"
                aria-pressed={value === product.id}
                onClick={() => onChange(product.id)}
                className={cn(
                  "flex w-full flex-col items-start px-3 py-2 text-left text-sm transition-colors hover:bg-muted focus-visible:bg-muted focus-visible:outline-none",
                  value === product.id && "bg-muted font-medium",
                )}
              >
                <span className="flex flex-wrap items-center gap-x-2">
                  {product.name}
                  {product.excluded && <Badge variant="secondary">{t("product.excluded")}</Badge>}
                </span>
                <span className="text-xs font-normal text-muted-foreground">
                  {(product.categoryId && paths.get(product.categoryId)) || t("analysis.uncategorized")}
                </span>
              </button>
            </li>
          ))
        )}
      </ul>
    </div>
  )
}
