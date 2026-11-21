import type { ReactNode } from "react"
import {
  type Announcements,
  DndContext,
  type DragEndEvent,
  KeyboardSensor,
  MouseSensor,
  TouchSensor,
  closestCenter,
  useSensor,
  useSensors,
} from "@dnd-kit/core"
import {
  SortableContext,
  sortableKeyboardCoordinates,
  useSortable,
  verticalListSortingStrategy,
} from "@dnd-kit/sortable"
import { CSS } from "@dnd-kit/utilities"
import { GripVerticalIcon } from "lucide-react"
import { useTranslation } from "react-i18next"
import { cn } from "cn"

import { Button } from "./button"

interface SortableListProps<T> {
  items: readonly T[]
  getId: (item: T) => string
  /** The item's name in the drag handle's label and the screen-reader announcements. */
  getLabel: (item: T) => string
  /** Called once per drop that changed the order, with the old and new index. */
  onMove: (from: number, to: number) => void
  /** The row's content; `handle` is the drag handle, to place where the row wants it. */
  renderItem: (item: T, handle: ReactNode) => ReactNode
  disabled?: boolean
  className?: string
  "aria-label"?: string
}

/**
 * A vertical list the user reorders by drag and drop (ADR 0031): from a handle with the mouse or a
 * finger, so the rest of the row keeps its controls and the page still scrolls on touch, or with
 * the keyboard (Space or Enter picks up and drops, the arrows move, Escape cancels). Moves are
 * announced to screen readers in the UI language.
 */
export function SortableList<T>({ items, getId, getLabel, onMove, renderItem, disabled = false, className, ...props }: SortableListProps<T>) {
  const { t } = useTranslation()
  const sensors = useSensors(
    useSensor(MouseSensor, { activationConstraint: { distance: 4 } }),
    useSensor(TouchSensor, { activationConstraint: { delay: 150, tolerance: 8 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  )
  const ids = items.map(getId)
  const labelOf = (id: string | number) => {
    const item = items.find((candidate) => getId(candidate) === id)
    return item === undefined ? "" : getLabel(item)
  }
  const positionOf = (id: string | number) => ids.indexOf(String(id)) + 1
  const count = items.length

  const announcements: Announcements = {
    onDragStart: ({ active }) => t("sortable.pickedUp", { name: labelOf(active.id), position: positionOf(active.id), count }),
    onDragOver: ({ active, over }) =>
      over ? t("sortable.movedOver", { name: labelOf(active.id), position: positionOf(over.id), count }) : undefined,
    onDragEnd: ({ active, over }) =>
      over
        ? t("sortable.dropped", { name: labelOf(active.id), position: positionOf(over.id), count })
        : t("sortable.cancelled", { name: labelOf(active.id) }),
    onDragCancel: ({ active }) => t("sortable.cancelled", { name: labelOf(active.id) }),
  }

  function onDragEnd({ active, over }: DragEndEvent) {
    if (!over || active.id === over.id) return
    const from = ids.indexOf(String(active.id))
    const to = ids.indexOf(String(over.id))
    if (from >= 0 && to >= 0) onMove(from, to)
  }

  return (
    <DndContext
      sensors={sensors}
      collisionDetection={closestCenter}
      onDragEnd={onDragEnd}
      accessibility={{ announcements, screenReaderInstructions: { draggable: t("sortable.instructions") } }}
    >
      <SortableContext items={ids} strategy={verticalListSortingStrategy} disabled={disabled}>
        <ul className={cn("flex flex-col", className)} aria-label={props["aria-label"]}>
          {items.map((item) => (
            <SortableItem key={getId(item)} id={getId(item)} label={getLabel(item)} disabled={disabled}>
              {(handle) => renderItem(item, handle)}
            </SortableItem>
          ))}
        </ul>
      </SortableContext>
    </DndContext>
  )
}

function SortableItem({
  id,
  label,
  disabled,
  children,
}: {
  id: string
  label: string
  disabled: boolean
  children: (handle: ReactNode) => ReactNode
}) {
  const { t } = useTranslation()
  const { attributes, listeners, setNodeRef, setActivatorNodeRef, transform, transition, isDragging } = useSortable({ id, disabled })

  const handle = (
    <Button
      ref={setActivatorNodeRef}
      type="button"
      variant="ghost"
      size="icon-sm"
      className="cursor-grab touch-none text-muted-foreground active:cursor-grabbing"
      disabled={disabled}
      {...attributes}
      {...listeners}
      aria-label={t("sortable.handle", { name: label })}
    >
      <GripVerticalIcon />
    </Button>
  )

  return (
    <li
      ref={setNodeRef}
      style={{ transform: CSS.Translate.toString(transform), transition }}
      className={cn("relative bg-card", isDragging && "z-10 shadow-md ring-1 ring-border")}
    >
      {children(handle)}
    </li>
  )
}
