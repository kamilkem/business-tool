import type { ReactNode } from "react"

/**
 * The one content column of every admin page (#171): the same maximum width on every page, so
 * tables, cards and headings line up wherever you go. Pages fill it and never set their own width;
 * a table wider than the column scrolls inside its own container (`Table`), not the page.
 * max-w-6xl (72rem) fits the widest table (shift change requests, in Polish) and is about what a
 * laptop screen leaves next to the sidebar, so on a laptop every page already fills the screen.
 */
export function PageContainer({ children }: { children: ReactNode }) {
  return <div className="mx-auto w-full max-w-6xl min-w-0">{children}</div>
}

/**
 * A form inside a full-width card (settings, account): the card keeps the page's width like every
 * other page, but its fields stop at a readable line length instead of stretching across it.
 */
export const FORM_WIDTH = "max-w-2xl"
