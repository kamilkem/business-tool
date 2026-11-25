import { useMemo } from "react"
import { CartesianGrid, Scatter, ScatterChart, Tooltip, XAxis, YAxis } from "recharts"
import { useTranslation } from "react-i18next"

import { ChartContainer, type ChartConfig } from "@business-tool/ui/components/chart"
import { useIsMobile } from "@business-tool/ui/hooks/use-mobile"
import { useMoneyFormat } from "@business-tool/ui/hooks/use-organization-currency"
import { useDateTimeFormat } from "@business-tool/ui/hooks/use-organization-time-zone"

import { PriceChangeLabel } from "@/components/price-change"
import {
  type ChartPoint,
  type PriceChart,
  type SellerSlot,
  dateOfDay,
  dateTicks,
  dayDomain,
  priceDomain,
  priceTicks,
} from "@/lib/product-prices"

/*
 * The price history chart (ADR 0041 §9, §10): the price per reference quantity over the issue date,
 * one point per purchase, joined by a thin neutral line (the price of the product, whoever sold it,
 * which is also what the price change is measured against). A point is coloured by its seller: the
 * three sellers the product is bought from most get a colour each, the rest share a muted one, and
 * each has its own marker shape too, so nothing is told by colour alone. The same purchases are in
 * the table under the chart.
 */

const SLOT_COLOUR: Record<SellerSlot, string> = {
  1: "var(--chart-1)",
  2: "var(--chart-2)",
  3: "var(--chart-3)",
  other: "var(--chart-4)",
}

const SHAPE: Record<SellerSlot, "circle" | "square" | "diamond" | "triangle"> = {
  1: "circle",
  2: "square",
  3: "diamond",
  other: "triangle",
}

/** A marker in the slot's colour and shape, centred on (0, 0) and 9 px across; the surface ring keeps neighbours apart. */
function Marker({ slot, ring = true }: { slot: SellerSlot; ring?: boolean }) {
  const common = {
    fill: SLOT_COLOUR[slot],
    stroke: ring ? "var(--card)" : "none",
    strokeWidth: 2,
  }
  switch (SHAPE[slot]) {
    case "circle":
      return <circle r={5} {...common} />
    case "square":
      return <rect x={-4.5} y={-4.5} width={9} height={9} rx={1} {...common} />
    case "diamond":
      return <rect x={-4.5} y={-4.5} width={9} height={9} rx={1} transform="rotate(45)" {...common} />
    case "triangle":
      return <path d="M0 -5.5 L5.5 4.5 L-5.5 4.5 Z" strokeLinejoin="round" {...common} />
  }
}

/** The marker as an icon in the legend. */
function LegendMarker({ slot }: { slot: SellerSlot }) {
  return (
    <svg width={14} height={14} viewBox="-7 -7 14 14" aria-hidden className="shrink-0">
      <Marker slot={slot} ring={false} />
    </svg>
  )
}

interface ShapeProps {
  cx?: number
  cy?: number
  payload?: ChartPoint
}

/** Draws one purchase: its marker, with a larger invisible circle around it to hover or touch. */
function PointShape({ cx, cy, payload }: ShapeProps) {
  if (cx === undefined || cy === undefined || !payload) return null
  return (
    <g transform={`translate(${cx} ${cy})`} className="cursor-default">
      <circle r={14} fill="transparent" />
      <Marker slot={payload.slot} />
    </g>
  )
}

interface PointTooltipProps {
  active?: boolean
  payload?: { payload?: ChartPoint }[]
  /** What the price is per, "0.5 L". */
  reference: string | null
}

function PointTooltip({ active, payload, reference }: PointTooltipProps) {
  const { t } = useTranslation("admin")
  const format = useDateTimeFormat()
  const money = useMoneyFormat()
  const point = payload?.[0]?.payload
  if (!active || !point) return null
  return (
    <div className="grid min-w-40 gap-1 rounded-lg border bg-popover px-3 py-2 text-xs text-popover-foreground shadow-xl">
      <div className="font-medium">{format.businessDate(point.date)}</div>
      <div className="flex items-center gap-1.5 text-muted-foreground">
        <LegendMarker slot={point.slot} />
        {point.sellerName}
      </div>
      <div className="text-sm font-semibold tabular-nums">
        {money(point.price)}
        {reference && <span className="text-xs font-normal text-muted-foreground"> / {reference}</span>}
      </div>
      {point.change ? (
        <PriceChangeLabel change={point.change} />
      ) : (
        <span className="text-muted-foreground">{t("product.chart.firstPurchase")}</span>
      )}
    </div>
  )
}

interface ProductPriceChartProps {
  chart: PriceChart
  /** What the price is per: the reference quantity with its unit, "0.5 L"; null for none. */
  reference: string | null
}

export function ProductPriceChart({ chart, reference }: ProductPriceChartProps) {
  const { t } = useTranslation("admin")
  const format = useDateTimeFormat()
  const money = useMoneyFormat()
  const xDomain = useMemo(() => dayDomain(chart.points), [chart.points])
  const yTicks = useMemo(() => priceTicks(priceDomain(chart.points)), [chart.points])
  const isMobile = useIsMobile()
  const { ticks, withDay } = useMemo(() => dateTicks(xDomain, isMobile ? 4 : 6), [xDomain, isMobile])
  // The colours come from CSS variables, so the chart config only names the series.
  const config = useMemo<ChartConfig>(() => ({ price: { label: t("product.chart.price") } }), [t])

  return (
    <div className="flex flex-col gap-3">
      <ChartContainer
        config={config}
        className="aspect-auto h-72 w-full justify-start sm:h-80"
        role="img"
        aria-label={t("product.chart.label")}
      >
        <ScatterChart margin={{ top: 12, right: 12, bottom: 0, left: 0 }}>
          <CartesianGrid vertical={false} stroke="var(--border)" />
          <XAxis
            type="number"
            dataKey="x"
            name={t("product.chart.date")}
            domain={xDomain}
            ticks={ticks}
            allowDataOverflow
            tickLine={false}
            axisLine={{ stroke: "var(--border)" }}
            tickMargin={8}
            tickFormatter={(day: number) =>
              format.businessDate(dateOfDay(day), withDay ? { day: "numeric", month: "short" } : { month: "short", year: "2-digit" })
            }
          />
          <YAxis
            type="number"
            dataKey="price"
            name={t("product.chart.price")}
            domain={[yTicks[0] ?? 0, yTicks.at(-1) ?? 1]}
            ticks={yTicks}
            allowDataOverflow
            width={72}
            tickLine={false}
            axisLine={false}
            tickMargin={4}
            tickFormatter={(price: number) => money(price)}
          />
          <Tooltip
            cursor={{ stroke: "var(--muted-foreground)", strokeDasharray: "3 3" }}
            content={<PointTooltip reference={reference} />}
            isAnimationActive={false}
          />
          <Scatter
            data={chart.points}
            shape={<PointShape />}
            line={{ stroke: "var(--muted-foreground)", strokeWidth: 1.5, strokeOpacity: 0.6 }}
            lineType="joint"
            isAnimationActive={false}
          />
        </ScatterChart>
      </ChartContainer>

      <ul className="flex flex-wrap gap-x-4 gap-y-1 text-sm" aria-label={t("product.chart.sellers")}>
        {chart.series.map((series) => (
          <li key={series.key} className="flex min-w-0 items-center gap-1.5">
            <LegendMarker slot={series.slot} />
            <span className="truncate">{series.name ?? t("product.chart.otherSellers", { count: series.sellers })}</span>
            <span className="shrink-0 text-xs text-muted-foreground">{series.count}</span>
          </li>
        ))}
      </ul>
    </div>
  )
}
