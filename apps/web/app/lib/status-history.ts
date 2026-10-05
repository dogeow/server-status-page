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

function dateKeyInTimeZone(value: Date, timeZone: string): string {
  const parts = new Intl.DateTimeFormat("en-CA", {
    timeZone,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).formatToParts(value);
  const part = (type: string) => parts.find((item) => item.type === type)?.value ?? "";
  return `${part("year")}-${part("month")}-${part("day")}`;
}

function addDaysToKey(key: string, days: number): string {
  const date = parseDateKey(key) ?? new Date(FALLBACK_ANCHOR.getTime());
  return utcDateKey(addUtcDays(date, days));
}

function formatMonthLabel(key: string): string {
  const [year, month] = key.split("-");
  return `${year}年${Number(month)}月`;
}

export function getHistoryPeriod(anchor: string | Date, periodOffset: number, timeZone = "UTC") {
  const anchorDate = parseAnchor(anchor);
  let zone = timeZone || "UTC";
  let endKey: string;
  try {
    endKey = dateKeyInTimeZone(anchorDate, zone);
  } catch {
    zone = "UTC";
    endKey = dateKeyInTimeZone(anchorDate, "UTC");
  }
  endKey = addDaysToKey(endKey, periodOffset * HISTORY_DAYS);
  const startKey = addDaysToKey(endKey, -(HISTORY_DAYS - 1));

  return {
    from: startKey,
    to: endKey,
    label: `${formatMonthLabel(startKey)} – ${formatMonthLabel(endKey)}`,
  };
}
