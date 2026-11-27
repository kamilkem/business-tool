import { useEffect, useMemo, useState, type FormEvent } from "react"
import { unwrap } from "@business-tool/api-client"
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { useTranslation } from "react-i18next"
import { toast } from "sonner"

import { Button } from "@business-tool/ui/components/button"
import { Checkbox } from "@business-tool/ui/components/checkbox"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@business-tool/ui/components/dialog"
import { Field, FieldContent, FieldDescription, FieldError, FieldGroup, FieldLabel, FieldLegend, FieldSet } from "@business-tool/ui/components/field"
import { Input } from "@business-tool/ui/components/input"
import { requestErrorMessage } from "@business-tool/ui/lib/request-error"

import { api } from "@/lib/api"
import {
  type PackageSizeGroup,
  type PackageSizeQuestion,
  type SizeAnswer,
  answerAfterCreate,
  countItems,
  groupId,
  isAsking,
  packageSizeQuestion,
  sizeToApply,
  targetsOf,
} from "@/lib/package-sizes"
import { invalidatePurchasing, packageSizesQuery } from "@/lib/queries"

/**
 * What to ask about: the product, and what the owner just did. `answer` null: a product was made from a proposal,
 * whose items got the size their names state, so the answer is read from the product's items.
 */
export interface PackageSizeFollowUpRequest {
  productId: string
  productName: string
  answer: SizeAnswer | null
}

interface PackageSizeFollowUpProps {
  organizationId: string
  request: PackageSizeFollowUpRequest | null
  onClose: () => void
}

/**
 * Asks, after a package size was set (an item edited, items assigned, a product made from a proposal), whether to
 * set it on the product's other items too (ADR 0048, #271): the ones with no size under other units, and, when an
 * existing size was changed, the ones with another size. The owner chooses the groups and may edit the size,
 * because a case is not the same size as a bottle; nothing is applied without that choice. It opens only when there is
 * something to ask, and says nothing otherwise.
 */
export function PackageSizeFollowUp({ organizationId, request, onClose }: PackageSizeFollowUpProps) {
  return request && <Loader key={request.productId} organizationId={organizationId} request={request} onClose={onClose} />
}

function Loader({ organizationId, request, onClose }: PackageSizeFollowUpProps & { request: PackageSizeFollowUpRequest }) {
  const groups = useQuery(packageSizesQuery(organizationId, request.productId))
  const found = useMemo(() => {
    if (!groups.data) return null
    const answer = request.answer ?? answerAfterCreate(groups.data.groups)
    return answer === null ? null : { answer, question: packageSizeQuestion(groups.data.groups, answer) }
  }, [groups.data, request.answer])

  // Nothing to ask, or the groups could not be read: the change stands and the owner is not bothered.
  const nothing = groups.isError || (groups.data !== undefined && (found === null || !isAsking(found.question)))
  useEffect(() => {
    if (nothing) onClose()
  }, [nothing, onClose])

  if (!found || nothing) return null
  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-h-[calc(100dvh-2rem)] overflow-y-auto sm:max-w-lg">
        <Form organizationId={organizationId} request={request} answer={found.answer} question={found.question} onClose={onClose} />
      </DialogContent>
    </Dialog>
  )
}

interface FormProps {
  organizationId: string
  request: PackageSizeFollowUpRequest
  answer: SizeAnswer
  question: PackageSizeQuestion
  onClose: () => void
}

function Form({ organizationId, request, answer, question, onClose }: FormProps) {
  const { t } = useTranslation("admin")
  const queryClient = useQueryClient()
  const [size, setSize] = useState(answer.size)
  const [chosen, setChosen] = useState<ReadonlySet<string>>(new Set())
  const [error, setError] = useState<string | null>(null)
  const all = [...question.fill, ...question.sameUnit, ...question.otherUnits]
  const targets = targetsOf(all, chosen)
  const packageSize = sizeToApply(size)
  const count = countItems(all.filter((group) => chosen.has(groupId(group))))

  const apply = useMutation({
    mutationFn: (body: { packageSize: string; groups: typeof targets }) =>
      unwrap(api.POST("/api/products/{id}/package-sizes", { params: { path: { id: request.productId } }, body })),
    onSuccess: async () => {
      toast.success(t("product.packageSizes.toast", { count }))
      await invalidatePurchasing(queryClient, organizationId)
      onClose()
    },
    onError: (failure) => setError(requestErrorMessage(failure)),
  })

  function toggle(group: PackageSizeGroup, on: boolean) {
    const next = new Set(chosen)
    if (on) next.add(groupId(group))
    else next.delete(groupId(group))
    setChosen(next)
  }

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setError(null)
    if (packageSize === null || targets.length === 0) return
    apply.mutate({ packageSize, groups: targets })
  }

  const sections = [
    { key: "fill", groups: question.fill },
    { key: "sameUnit", groups: question.sameUnit },
    { key: "otherUnits", groups: question.otherUnits },
  ] as const

  return (
    <form onSubmit={onSubmit} noValidate>
      <DialogHeader>
        <DialogTitle>{t("product.packageSizes.title")}</DialogTitle>
        <DialogDescription className="break-words">
          {t("product.packageSizes.description", { name: request.productName, size: answer.size })}
        </DialogDescription>
      </DialogHeader>
      <fieldset disabled={apply.isPending} className="py-4">
        <FieldGroup>
          <Field data-invalid={error ? true : undefined}>
            <FieldLabel htmlFor="follow-up-package-size">{t("product.packageSizes.size")}</FieldLabel>
            <Input
              id="follow-up-package-size"
              value={size}
              onChange={(event) => setSize(event.target.value)}
              inputMode="decimal"
              autoComplete="off"
              aria-invalid={error ? true : undefined}
            />
            {error ? <FieldError>{error}</FieldError> : <FieldDescription>{t("product.packageSizes.sizeDescription")}</FieldDescription>}
          </Field>

          {sections.map(
            ({ key, groups }) =>
              groups.length > 0 && (
                <FieldSet key={key}>
                  <FieldLegend variant="label">{t(`product.packageSizes.${key}.title`)}</FieldLegend>
                  <FieldDescription>{t(`product.packageSizes.${key}.description`)}</FieldDescription>
                  {groups.map((group) => {
                    const id = `follow-up-${groupId(group)}`
                    return (
                      <Field key={id} orientation="horizontal">
                        <Checkbox id={id} checked={chosen.has(groupId(group))} onCheckedChange={(checked) => toggle(group, checked === true)} />
                        <FieldContent>
                          <FieldLabel htmlFor={id}>
                            {group.unit === null
                              ? t("product.packageSizes.group.noUnit", { count: group.count })
                              : t("product.packageSizes.group.items", { count: group.count, unit: group.unit })}
                          </FieldLabel>
                          {(group.packageSize !== null || group.confirmedCount > 0) && (
                            <FieldDescription>
                              {[
                                group.packageSize !== null ? t("product.packageSizes.group.now", { size: group.packageSize }) : null,
                                group.confirmedCount > 0 ? t("product.packageSizes.group.confirmed", { count: group.confirmedCount }) : null,
                              ]
                                .filter((part) => part !== null)
                                .join(" · ")}
                            </FieldDescription>
                          )}
                        </FieldContent>
                      </Field>
                    )
                  })}
                </FieldSet>
              ),
          )}
        </FieldGroup>
      </fieldset>
      <DialogFooter>
        <Button type="button" variant="outline" disabled={apply.isPending} onClick={onClose}>
          {t("product.packageSizes.skip")}
        </Button>
        <Button type="submit" disabled={apply.isPending || packageSize === null || targets.length === 0}>
          {apply.isPending ? t("common.saving", { ns: "ui" }) : t("product.packageSizes.apply", { count })}
        </Button>
      </DialogFooter>
    </form>
  )
}
