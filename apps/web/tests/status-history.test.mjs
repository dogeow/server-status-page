import assert from "node:assert/strict";
import test from "node:test";

import { fillHistory, getHistoryPeriod } from "../app/lib/status-history.ts";

function periodInTimezone(timezone, anchor, offset) {
  const previous = process.env.TZ;
  process.env.TZ = timezone;
  try {
    return getHistoryPeriod(anchor, offset);
  } finally {
    if (previous === undefined) delete process.env.TZ;
    else process.env.TZ = previous;
  }
}

test("history period is identical in UTC and Asia/Shanghai", () => {
  const anchor = "2026-03-31T23:30:00.000Z";
  const utc = periodInTimezone("UTC", anchor, -1);
  const shanghai = periodInTimezone("Asia/Shanghai", anchor, -1);

  assert.deepEqual(shanghai, utc);
  assert.deepEqual(utc, {
    from: "2025-09-30",
    to: "2025-12-31",
    label: "2025年9月 – 2025年12月",
  });
});

test("month-end period navigation clamps dates consistently", () => {
  assert.deepEqual(getHistoryPeriod("2026-05-31T23:30:00.000Z", -1), {
    from: "2025-11-30",
    to: "2026-02-28",
    label: "2025年11月 – 2026年2月",
  });
});

test("short history is prepended oldest-to-newest without changing existing rows", () => {
  const existing = [
    { date: "2026-07-13", status: "operational", uptimePercent: 100, maintenance: false },
    { date: "2026-07-14", status: "degraded", uptimePercent: 99.5, maintenance: false },
  ];
  const filled = fillHistory(existing, "2026-07-14T16:31:00.000Z");

  assert.equal(filled.length, 90);
  assert.equal(filled[0].date, "2026-04-16");
  assert.equal(filled[87].date, "2026-07-12");
  assert.deepEqual(filled.slice(-2), existing);
  assert.equal(
    filled.every((row, index) => index === 0 || row.date > filled[index - 1].date),
    true,
  );
});

test("a complete 90-day history is preserved", () => {
  const history = fillHistory([], "2026-07-14T16:31:00.000Z").map((row, index) => ({
    ...row,
    status: index % 2 === 0 ? "operational" : "degraded",
    uptimePercent: 99 + index / 100,
  }));

  assert.deepEqual(fillHistory(history, "2030-01-01T00:00:00.000Z"), history);
});
