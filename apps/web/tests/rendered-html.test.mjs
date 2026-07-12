import assert from "node:assert/strict";
import { access, readFile } from "node:fs/promises";
import test from "node:test";

// Keep render tests deterministic even when a developer has the Laravel API
// running locally; integration coverage starts both services separately.
process.env.API_INTERNAL_URL = "http://127.0.0.1:1";

async function render(path = "/") {
  const workerUrl = new URL("../dist/server/index.js", import.meta.url);
  workerUrl.searchParams.set("test", `${process.pid}-${Date.now()}`);
  const { default: handler } = await import(workerUrl.href);
  const request = new Request(`http://localhost${path}`, { headers: { accept: "text/html" } });
  const environment = { ASSETS: { fetch: async () => new Response("Not found", { status: 404 }) } };
  const context = { waitUntil() {}, passThroughOnException() {} };
  return typeof handler === "function"
    ? handler(request, environment, context)
    : handler.fetch(request, environment, context);
}

test("server-renders the public status surface without fabricated data", async () => {
  const response = await render();
  assert.equal(response.status, 200);
  assert.match(response.headers.get("content-type") ?? "", /^text\/html\b/i);
  const html = await response.text();
  assert.match(html, /System Status/i);
  assert.match(html, /System status/i);
  assert.match(html, /等待控制面数据|尚未发布组件/);
  assert.match(html, /不会使用虚构状态/);
  assert.doesNotMatch(html, /codex-preview|react-loading-skeleton|Your site is taking shape/i);
});

test("ships real status, subscription and admin routes", async () => {
  const [history, subscribe, admin] = await Promise.all([
    render("/history"),
    render("/subscribe"),
    render("/admin/login"),
  ]);
  assert.equal(history.status, 200);
  assert.equal(subscribe.status, 200);
  assert.equal(admin.status, 200);
  assert.match(await history.text(), /历史记录/);
  assert.match(await subscribe.text(), /订阅状态更新/);
  assert.match(await admin.text(), /进入管理后台/);
});

test("exposes a dynamic nonce readiness route instead of a cacheable homepage check", async () => {
  const source = await readFile(new URL("../app/api/readiness/route.ts", import.meta.url), "utf8");
  assert.match(source, /force-dynamic/);
  assert.match(source, /nonce_required/);
  assert.match(source, /no-store/);
  assert.match(source, /X-Status-Nonce/);

  const response = await render("/api/readiness?nonce=fresh-123");
  assert.equal(response.status, 200);
  assert.match(response.headers.get("cache-control") ?? "", /no-store/);
  assert.equal(response.headers.get("x-status-nonce"), "fresh-123");
  assert.equal((await response.json()).nonce, "fresh-123");

  const invalid = await render("/api/readiness");
  assert.equal(invalid.status, 422);
});

test("history bars expose click, hover and keyboard-accessible date details", async () => {
  const source = await readFile(new URL("../app/components/StatusDashboard.tsx", import.meta.url), "utf8");
  assert.match(source, /className="history-tooltip" role="tooltip"/);
  assert.match(source, /onClick=\{\(\) => setSelectedIndex/);
  assert.match(source, /onMouseEnter=\{\(\) => setPreviewIndex/);
  assert.match(source, /onFocus=\{\(\) => setPreviewIndex/);
  assert.match(source, /aria-label=\{detailLabel\}/);
});

test("mobile status layout keeps the hero compact and all 90 bars inside the card", async () => {
  const css = await readFile(new URL("../app/globals.css", import.meta.url), "utf8");
  const mobile = css.slice(css.indexOf("@media (max-width: 640px)"));
  assert.match(mobile, /\.overall-banner\s*\{[\s\S]*?grid-template-columns: auto minmax\(0, 1fr\)/);
  assert.match(mobile, /\.history-bars\s*\{[\s\S]*?min-width: 0/);
  assert.match(mobile, /grid-template-columns: repeat\(90, minmax\(0, 1fr\)\)/);
  assert.match(mobile, /\.status-card\s*\{[^}]*margin-top: 18px/);
  assert.match(mobile, /\.history-bar:nth-child\(-n \+ 25\) \.history-tooltip/);
  assert.match(mobile, /\.history-bar:nth-last-child\(-n \+ 25\) \.history-tooltip/);
});

test("removes all starter-only assets and metadata", async () => {
  const [page, layout, packageJson] = await Promise.all([
    readFile(new URL("../app/page.tsx", import.meta.url), "utf8"),
    readFile(new URL("../app/layout.tsx", import.meta.url), "utf8"),
    readFile(new URL("../package.json", import.meta.url), "utf8"),
  ]);
  assert.doesNotMatch(page, /SkeletonPreview|codex-preview/);
  assert.doesNotMatch(layout, /Starter Project|codex-preview/);
  assert.doesNotMatch(packageJson, /react-loading-skeleton/);
  await assert.rejects(access(new URL("../app/_sites-preview", import.meta.url)));
});
