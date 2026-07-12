import type { Metadata } from "next";
import { SiteHeader } from "../components/SiteHeader";
import { StatusDashboard } from "../components/StatusDashboard";
import { getPublicStatus } from "../lib/api";

export const metadata: Metadata = {
  title: "历史记录",
  description: "查看最近 90 天可用率、故障和计划维护。",
};

export default async function HistoryPage() {
  const status = await getPublicStatus();
  return (
    <main className="site-shell">
      <SiteHeader siteName={status.statusPage.name} />
      <header className="page-heading">
        <p className="eyebrow">History</p>
        <h1>历史记录</h1>
        <p>按真实探测结果聚合最近 90 天的服务状态。计划维护继续采样，但不会计入可用率。</p>
      </header>
      <StatusDashboard initialStatus={status} />
    </main>
  );
}
