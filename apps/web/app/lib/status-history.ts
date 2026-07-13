import type { DailyStatus } from "../types";

const HISTORY_DAYS = 90;
const FALLBACK_ANCHOR = new Date(0);

function parseAnchor(value: string | Date): Date {
  const date = value instanceof Date ? new Date(value.getTime()) : new Date(value);
  return Number.isNaN(date.getTime()) ? new Date(FALLBACK_ANCHOR.getTime()) : date;
}

function parseDateKey(value: string | undefined): Date | null {
  if (!value || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return null;
  const date = new Date(`${value}T00:00:00.000Z`);
  return Number.isNaN(date.getTime()) || utcDateKey(date) !== value ? null : date;
}

function addUtcDays(value: Date, days: number): Date {
  const date = new Date(value.getTime());
  date.setUTCDate(date.getUTCDate() + days);
  return date;
}

function addUtcMonths(value: Date, months: number): Date {
  const target = new Date(Date.UTC(
    value.getUTCFullYear(),
    value.getUTCMonth() + months,
    1,
    value.getUTCHours(),
    value.getUTCMinutes(),
    value.getUTCSeconds(),
    value.getUTCMilliseconds(),
  ));
  const lastDay = new Date(Date.UTC(
    target.getUTCFullYear(),
    target.getUTCMonth() + 1,
    0,
  )).getUTCDate();
  target.setUTCDate(Math.min(value.getUTCDate(), lastDay));
  return target;
}

export function utcDateKey(value: string | Date): string {
  const date = parseAnchor(value);
  const year = String(date.getUTCFullYear()).padStart(4, "0");
  const month = String(date.getUTCMonth() + 1).padStart(2, "0");
  const day = String(date.getUTCDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
}

export function fillHistory(history: DailyStatus[], anchor: string | Date): DailyStatus[] {
  const rows = history.slice(-HISTORY_DAYS);
  const missing = HISTORY_DAYS - rows.length;
  if (missing === 0) return rows;

  const anchorDay = parseDateKey(utcDateKey(anchor)) ?? new Date(FALLBACK_ANCHOR.getTime());
  const firstHistoryDay = parseDateKey(rows[0]?.date);
  const firstMissingDay = firstHistoryDay
    ? addUtcDays(firstHistoryDay, -missing)
    : addUtcDays(anchorDay, -(HISTORY_DAYS - 1));
  const padding = Array.from({ length: missing }, (_, index): DailyStatus => ({
    date: utcDateKey(addUtcDays(firstMissingDay, index)),
    status: "unknown",
    uptimePercent: null,
    maintenance: false,
  }));

  return [...padding, ...rows];
}

export function getHistoryPeriod(anchor: string | Date, periodOffset: number) {
  const anchorDate = parseAnchor(anchor);
  const end = addUtcMonths(anchorDate, periodOffset * 3);
  const start = addUtcMonths(anchorDate, periodOffset * 3 - 3);
  const formatter = new Intl.DateTimeFormat("zh-CN", {
    timeZone: "UTC",
    year: "numeric",
    month: "short",
  });

  return {
    from: utcDateKey(start),
    to: utcDateKey(end),
    label: `${formatter.format(start)} – ${formatter.format(end)}`,
  };
}
