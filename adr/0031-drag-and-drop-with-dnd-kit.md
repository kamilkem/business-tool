# 0031. Drag-and-drop reordering in the frontend uses @dnd-kit, with keyboard and touch support

Status: Accepted

Date: 2026-11-22

## Context

Organizations order their payment methods (ADR [0030](0030-payment-methods-per-organization.md)
§3). The product owner asked for drag and drop to reorder them, in place of up and down buttons. It
must work with the keyboard, on touch screens and at phone width, because owners use the admin on
their phones too. The frontend so far has no drag-and-drop code, and React 19 with shadcn/ui gives
none (ADR [0008](0008-two-frontend-apps-in-one-pnpm-workspace.md)). The HTML5 drag-and-drop API
has no touch support and no keyboard interaction, and it is awkward to make accessible.

## Decision

1. **Sortable lists use `@dnd-kit/core`, `@dnd-kit/sortable` and `@dnd-kit/utilities`**, in
   `packages/ui`. It is a maintained, widely used React toolkit with keyboard and touch sensors and
   screen-reader announcements built in. Placing it in `packages/ui` lets any app reuse it, next to
   the other UI building blocks.
2. **Every sortable list enables the pointer, touch and keyboard sensors**:
   - Dragging starts from a visible handle, so a row's other controls and the page's scrolling
     still work on touch.
   - The keyboard uses `sortableKeyboardCoordinates`: Space or Enter picks up, the arrows move,
     Space or Enter drops, and Escape cancels.
   - The screen-reader announcements are translated, in the `ui` catalog (ADR
     [0028](0028-ui-language-per-user-with-i18next.md)).
3. **The new order is sent once, on drop, and the list is updated optimistically.** On an error,
   the server's list is read again.

## Alternatives considered

- **Up and down buttons.** They need no dependency and are accessible by default, but a long move
  takes many clicks. The product owner chose drag and drop.
- **The native HTML5 drag-and-drop API.** It has no touch support and no keyboard model, so both
  would have to be built by hand.
- **`react-beautiful-dnd` / `@hello-pangea/dnd`.** The first is deprecated. The second is
  list-oriented and accessible, but it is heavier and less flexible for later uses, such as grids.

## Consequences

- Three small packages join `packages/ui`. Updates follow the usual dependency upgrades.
- Each sortable list provides its own drag handle and announcement texts in English and Polish.
- Later lists that a user orders reuse `SortableList` from `@business-tool/ui`.
