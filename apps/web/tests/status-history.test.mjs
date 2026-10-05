import assert from "node:assert/strict";
import test from "node:test";

import { fillHistory, getHistoryPeriod } from "../app/lib/status-history.ts";

test("history period is 90 days in the requested timezone", () => {
  const anchor = "2026-03-31T23:30:00.000Z";

  assert.deepEqual(getHistoryPeriod(anchor, -1, "UTC"), {
    from: "2025-10-03",
    to: "2025-12-31",
    label: "2025年10月 – 2025年12月",
  });
  assert.deepEqual(getHistoryPeriod(anchor, -1, "Asia/Shanghai"), {
    from: "2025-10-04",
    to: "2026-01-01",
    label: "2025年10月 – 2026年1月",
  });
  assert.deepEqual(getHistoryPeriod(anchor, 0, "UTC"), {
    from: "2026-01-01",
    to: "2026-03-31",
    label: "2026年1月 – 2026年3月",
  });
});

test("month-end period navigation stays on 90 local days", () => {
  assert.deepEqual(getHistoryPeriod("2026-05-31T23:30:00.000Z", -1, "UTC"), {
    from: "2025-12-03",
    to: "2026-03-02",
    label: "2025年12月 – 2026年3月",
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
