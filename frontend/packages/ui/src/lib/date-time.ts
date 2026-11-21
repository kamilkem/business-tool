/**
 * Dates and times in the organization's time zone, never the browser's (ADR 0002 §4, ADR 0017).
 *
 * The API sends instants as ISO 8601 strings in UTC and business dates as "YYYY-MM-DD". An instant
 * is shown in the organization's time zone; a business date is a calendar day with no zone (the
 * 25th of October is the 25th everywhere), so it is formatted without one. "Today" and "this month"
 * are the business date and month that contain the current instant in the organization's zone.
 *
 * Every function that needs a zone takes it as a parameter. React code gets it from
 * `useOrganizationTimeZone()` / `useDateTimeFormat()` (`hooks/use-organization-time-zone`).
 */

import { i18n } from "../i18n/i18n"

/** An instant from the API (ISO 8601, e.g. "2026-11-11T13:02:00+00:00") or a `Date`. */
export type Instant = string | Date

/** A calendar day in the organization's time zone, "YYYY-MM-DD", as the API sends and accepts it. */
export type BusinessDate = string

/** An inclusive range of business dates, as `?from=&to=` in the API. */
export interface BusinessPeriod {
  from: BusinessDate
  to: BusinessDate
}

const BUSINESS_DATE = /^(\d{4})-(\d{2})-(\d{2})$/
const DAY_MS = 86_400_000

const formatters = new Map<string, Intl.DateTimeFormat>()

/** Creating an `Intl.DateTimeFormat` is slow, and tables format hundreds of cells: reuse them. */
function formatter(locale: string | undefined, options: Intl.DateTimeFormatOptions): Intl.DateTimeFormat {
  const key = JSON.stringify([locale ?? null, options])
  let cached = formatters.get(key)
  if (!cached) {
    cached = new Intl.DateTimeFormat(locale, options)
    formatters.set(key, cached)
  }
  return cached
}

function toDate(instant: Instant): Date {
  const date = instant instanceof Date ? instant : new Date(instant)
  if (Number.isNaN(date.getTime())) throw new RangeError(`Not a valid instant: "${String(instant)}"`)
  return date
}

function pad(value: number, length = 2): string {
  return String(value).padStart(length, "0")
}

/** UTC midnight of a calendar day; overflowing days roll over (day 0 = last day of the previous month). */
function utcDay(year: number, monthIndex: number, day: number): Date {
  const date = new Date(0)
  // Unlike Date.UTC(), setUTCFullYear() does not map years 0-99 to 1900-1999.
  date.setUTCFullYear(year, monthIndex, day)
  return date
}

// --- Business dates ---------------------------------------------------------------------------

/** True for a strict "YYYY-MM-DD" that exists in the calendar (no 2026-02-30). */
export function isBusinessDate(value: string): boolean {
  const match = BUSINESS_DATE.exec(value)
  if (!match) return false
  const [year, month, day] = [Number(match[1]), Number(match[2]), Number(match[3])]
  const date = utcDay(year, month - 1, day)
  return date.getUTCFullYear() === year && date.getUTCMonth() === month - 1 && date.getUTCDate() === day
}

/** The business date as a UTC midnight `Date`, for calendar arithmetic and zone-free formatting. */
function calendarDay(date: BusinessDate): Date {
  if (!isBusinessDate(date)) throw new RangeError(`Not a business date (YYYY-MM-DD): "${date}"`)
  const [year, month, day] = date.split("-").map(Number) as [number, number, number]
  return utcDay(year, month - 1, day)
}

function fromCalendarDay(day: Date): BusinessDate {
  return `${pad(day.getUTCFullYear(), 4)}-${pad(day.getUTCMonth() + 1)}-${pad(day.getUTCDate())}`
}

/** The business date an instant falls on in the time zone (`BusinessDate::containing()` in the API). */
export function businessDateOf(instant: Instant, timeZone: string): BusinessDate {
  const parts = formatter("en-US", {
    timeZone,
    calendar: "gregory",
    numberingSystem: "latn",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).formatToParts(toDate(instant))
  const part = (type: Intl.DateTimeFormatPartTypes) => Number(parts.find((p) => p.type === type)?.value)
  return `${pad(part("year"), 4)}-${pad(part("month"))}-${pad(part("day"))}`
}

/** Today in the time zone. */
export function today(timeZone: string, now: Date = new Date()): BusinessDate {
  return businessDateOf(now, timeZone)
}

/** The date `days` calendar days later (earlier when negative). Depends on no time zone. */
export function addDays(date: BusinessDate, days: number): BusinessDate {
  return fromCalendarDay(new Date(calendarDay(date).getTime() + days * DAY_MS))
}

/** Negative, zero or positive, like a sort comparator. "YYYY-MM-DD" also sorts as a string. */
export function compareBusinessDates(a: BusinessDate, b: BusinessDate): number {
  return calendarDay(a).getTime() - calendarDay(b).getTime()
}

// --- Business periods -------------------------------------------------------------------------

/** One calendar month; `month` is 1-12. */
export function monthPeriod(year: number, month: number): BusinessPeriod {
  if (!Number.isInteger(year) || !Number.isInteger(month) || month < 1 || month > 12) {
    throw new RangeError(`Not a month: ${year}-${month}`)
  }
  return { from: fromCalendarDay(utcDay(year, month - 1, 1)), to: fromCalendarDay(utcDay(year, month, 0)) }
}

/** The month that contains the date. */
export function monthOf(date: BusinessDate): BusinessPeriod {
  const day = calendarDay(date)
  return monthPeriod(day.getUTCFullYear(), day.getUTCMonth() + 1)
}

/** The current month in the time zone: on 31 Oct 23:30 UTC it is already November in Warsaw. */
export function thisMonth(timeZone: string, now: Date = new Date()): BusinessPeriod {
  return monthOf(today(timeZone, now))
}

/** Number of days in the period, both ends included. */
export function dayCount(period: BusinessPeriod): number {
  return Math.round((calendarDay(period.to).getTime() - calendarDay(period.from).getTime()) / DAY_MS) + 1
}

/** Why a period typed by a person can't be used (as a sentence), or null when it can. */
export function periodError(period: BusinessPeriod, maxDays?: number): string | null {
  if (!isBusinessDate(period.from) || !isBusinessDate(period.to)) return i18n.t("period.error.bothDates")
  if (period.to < period.from) return i18n.t("period.error.endBeforeStart")
  if (maxDays !== undefined && dayCount(period) > maxDays) return i18n.t("period.error.tooLong", { count: maxDays })
  return null
}

/** True when the period is exactly one calendar month. */
export function isWholeMonth(period: BusinessPeriod): boolean {
  const month = monthOf(period.from)
  return month.from === period.from && month.to === period.to
}

/**
 * The period before (-1) or after (+1): the previous or next month for a whole month, otherwise
 * a period of the same length right before or after it.
 */
export function shiftPeriod(period: BusinessPeriod, direction: -1 | 1): BusinessPeriod {
  if (isWholeMonth(period)) {
    return monthOf(direction === 1 ? addDays(period.to, 1) : addDays(period.from, -1))
  }
  const days = dayCount(period) * direction
  return { from: addDays(period.from, days), to: addDays(period.to, days) }
}

/** True when the period contains the date. */
export function periodContains(period: BusinessPeriod, date: BusinessDate): boolean {
  return period.from <= date && date <= period.to
}

// --- Durations --------------------------------------------------------------------------------

/**
 * "7 h 30 min", "45 min", "8 h", "0 min" for whole minutes (e.g. a shift's `durationMinutes`), in the
 * language shown ("7 godz. 30 min" in Polish).
 */
export function formatDuration(minutes: number): string {
  const total = Math.max(0, Math.floor(minutes))
  const hours = Math.floor(total / 60)
  const rest = total % 60
  if (hours === 0) return i18n.t("duration.minutes", { minutes: rest })
  return rest === 0 ? i18n.t("duration.hours", { hours }) : i18n.t("duration.hoursMinutes", { hours, minutes: rest })
}

/** Whole minutes between two instants, never negative. */
export function minutesBetween(from: Instant, to: Instant): number {
  return Math.max(0, Math.floor((toDate(to).getTime() - toDate(from).getTime()) / 60_000))
}

// --- Wall-clock times in a time zone ----------------------------------------------------------

/** A time of day on a wall clock, "HH:MM" (24-hour), as `<input type="time">` gives it. */
export type WallTime = string

/** A date and time on the wall clock of the organization's time zone, e.g. in a form. */
export interface LocalDateTime {
  date: BusinessDate
  time: WallTime
}

const WALL_TIME = /^([01]\d|2[0-3]):([0-5]\d)$/
const MINUTE_MS = 60_000

/** True for a strict "HH:MM" from 00:00 to 23:59. */
export function isWallTime(value: string): boolean {
  return WALL_TIME.test(value)
}

/** The wall clock in the time zone at an instant, to the second, as if it were UTC (epoch ms). */
function wallClockMs(instant: Date, timeZone: string): number {
  const parts = formatter("en-US", {
    timeZone,
    calendar: "gregory",
    numberingSystem: "latn",
    hourCycle: "h23",
    year: "numeric",
    month: "numeric",
    day: "numeric",
    hour: "numeric",
    minute: "numeric",
    second: "numeric",
  }).formatToParts(instant)
  const part = (type: Intl.DateTimeFormatPartTypes) => Number(parts.find((p) => p.type === type)?.value)
  const wall = utcDay(part("year"), part("month") - 1, part("day"))
  wall.setUTCHours(part("hour"), part("minute"), part("second"))
  return wall.getTime()
}

/** The zone's offset from UTC at an instant, in whole minutes (+60 for CET, -300 for EST). */
export function utcOffsetMinutes(instant: Instant, timeZone: string): number {
  const date = toDate(instant)
  const exact = new Date(Math.floor(date.getTime() / 1000) * 1000)
  return Math.round((wallClockMs(exact, timeZone) - exact.getTime()) / MINUTE_MS)
}

/** "+01:00", "-05:00", "+05:45", or "+00:00" for UTC. */
export function formatUtcOffset(minutes: number): string {
  const sign = minutes < 0 ? "-" : "+"
  const abs = Math.abs(minutes)
  return `${sign}${pad(Math.floor(abs / 60))}:${pad(abs % 60)}`
}

/**
 * Every instant at which the time zone's wall clock shows this date and time, earliest first:
 * - one on most days;
 * - none in the gap when clocks move forward (02:30 on 29 Mar 2026 in Warsaw does not exist);
 * - two in the repeated hour when clocks move back (02:30 on 25 Oct 2026 in Warsaw happens at
 *   00:30 UTC in summer time, then again at 01:30 UTC in winter time).
 * Throws on a malformed date or time. Assumes at most one offset change within a day of it,
 * which holds for every zone in use.
 */
export function instantsAt(local: LocalDateTime, timeZone: string): Date[] {
  if (!isWallTime(local.time)) throw new RangeError(`Not a time of day (HH:MM): "${local.time}"`)
  const [hours, minutes] = local.time.split(":").map(Number) as [number, number]
  const wall = calendarDay(local.date).getTime() + (hours * 60 + minutes) * MINUTE_MS

  // The offsets in force a day before and a day after: the wall time belongs to either.
  const offsets = new Set([wall - DAY_MS, wall + DAY_MS].map((ms) => utcOffsetMinutes(new Date(ms), timeZone)))
  return [...offsets]
    .map((offset) => new Date(wall - offset * MINUTE_MS))
    .filter((candidate) => wallClockMs(candidate, timeZone) === wall)
    .sort((a, b) => a.getTime() - b.getTime())
}

/** The date and time (to the minute) the time zone's wall clock shows at an instant. */
export function localDateTimeOf(instant: Instant, timeZone: string): LocalDateTime {
  const wall = new Date(wallClockMs(toDate(instant), timeZone))
  return { date: fromCalendarDay(wall), time: `${pad(wall.getUTCHours())}:${pad(wall.getUTCMinutes())}` }
}

/**
 * The instant as ISO 8601 with seconds and the time zone's offset at that moment, as the API takes
 * it: "2026-10-25T02:30:00+02:00". Fractions of a second are dropped.
 */
export function toZonedIsoString(instant: Instant, timeZone: string): string {
  const date = toDate(instant)
  const exact = new Date(Math.floor(date.getTime() / 1000) * 1000)
  const wall = new Date(wallClockMs(exact, timeZone))
  const offset = Math.round((wall.getTime() - exact.getTime()) / MINUTE_MS)
  return (
    `${fromCalendarDay(wall)}T${pad(wall.getUTCHours())}:${pad(wall.getUTCMinutes())}:${pad(wall.getUTCSeconds())}` +
    formatUtcOffset(offset)
  )
}

// --- Formatting -------------------------------------------------------------------------------

export interface DateTimeFormat {
  readonly timeZone: string
  /** "11 Nov 2026" (`dateStyle: "medium"`), or the given fields, in the time zone. */
  date(instant: Instant, options?: Intl.DateTimeFormatOptions): string
  /** "14:02" or "2:02 PM" (the locale decides), in the time zone. */
  time(instant: Instant): string
  /** "11 Nov 2026, 14:02", in the time zone. */
  dateTime(instant: Instant): string
  /** A business date ("YYYY-MM-DD") as "11 Nov 2026", or the given fields. No time zone applies. */
  businessDate(date: BusinessDate, options?: Intl.DateTimeFormatOptions): string
  /** "November 2026" for a whole month, "1–15 Nov 2026" or "11 Nov 2026" otherwise. */
  period(period: BusinessPeriod): string
  /** The business date the instant falls on, in the time zone. */
  dateOf(instant: Instant): BusinessDate
  /** Today in the time zone. */
  today(now?: Date): BusinessDate
  /** The current month in the time zone. */
  thisMonth(now?: Date): BusinessPeriod
}

const MEDIUM_DATE: Intl.DateTimeFormatOptions = { dateStyle: "medium" }

/**
 * Formatting bound to one time zone and locale. `locale` defaults to the browser's; tests pass one
 * so the output does not depend on the machine.
 */
export function createDateTimeFormat(timeZone: string, locale?: string): DateTimeFormat {
  // Fails early (RangeError) on a zone the browser does not know, rather than on the first format.
  formatter(locale, { timeZone })

  const inZone = (options: Intl.DateTimeFormatOptions) => formatter(locale, { ...options, timeZone })
  const calendar = (options: Intl.DateTimeFormatOptions) => formatter(locale, { ...options, timeZone: "UTC" })

  return {
    timeZone,
    date: (instant, options = MEDIUM_DATE) => inZone(options).format(toDate(instant)),
    time: (instant) => inZone({ hour: "2-digit", minute: "2-digit" }).format(toDate(instant)),
    dateTime: (instant) => inZone({ dateStyle: "medium", timeStyle: "short" }).format(toDate(instant)),
    businessDate: (date, options = MEDIUM_DATE) => calendar(options).format(calendarDay(date)),
    period: (period) => {
      if (isWholeMonth(period)) return calendar({ month: "long", year: "numeric" }).format(calendarDay(period.from))
      return calendar(MEDIUM_DATE).formatRange(calendarDay(period.from), calendarDay(period.to))
    },
    dateOf: (instant) => businessDateOf(instant, timeZone),
    today: (now) => today(timeZone, now),
    thisMonth: (now) => thisMonth(timeZone, now),
  }
}

/** The browser's own time zone, e.g. to say "times are in Europe/Warsaw" when it differs. */
export function browserTimeZone(): string {
  return new Intl.DateTimeFormat().resolvedOptions().timeZone
}
