import { formatDuration, type DateTimeFormat } from "@business-tool/ui/lib/date-time"

/** Milliseconds between two moments, never negative (the phone's clock may be behind the server's). */
export function elapsedMs(from: string, to: Date | string): number {
  return Math.max(0, new Date(to).getTime() - new Date(from).getTime())
}

/** "2:05:09" for a running shift. */
export function formatElapsed(ms: number): string {
  const totalSeconds = Math.floor(ms / 1000)
  const hours = Math.floor(totalSeconds / 3600)
  const minutes = Math.floor((totalSeconds % 3600) / 60)
  const seconds = totalSeconds % 60
  return `${hours}:${String(minutes).padStart(2, "0")}:${String(seconds).padStart(2, "0")}`
}

/** "7 h 32 min" for a finished shift. */
export function formatWorked(ms: number): string {
  return ms < 60_000 ? "less than a minute" : formatDuration(ms / 60_000)
}

/**
 * "14:02" today, "Mon 22:10" for a shift that started on an earlier day. Both the time and "today"
 * are in the organization's time zone, so a phone set to another zone shows the bar's clock.
 */
export function formatClockTime(format: DateTimeFormat, moment: string, now: Date): string {
  const time = format.time(moment)
  if (format.dateOf(moment) === format.dateOf(now)) return time
  return `${format.date(moment, { weekday: "short" })} ${time}`
}
