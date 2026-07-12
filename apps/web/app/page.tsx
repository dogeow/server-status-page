import { StatusDashboard } from "./components/StatusDashboard";
import { SiteHeader } from "./components/SiteHeader";
import { getPublicStatus } from "./lib/api";

export default async function Home() {
  const status = await getPublicStatus();

  return (
    <main className="site-shell">
      <SiteHeader siteName={status.statusPage.name} />
      <StatusDashboard initialStatus={status} />
      <footer className="site-footer">
        <span>状态数据由独立探针持续验证</span>
        <span aria-hidden="true">·</span>
        <a href="/admin">管理后台</a>
      </footer>
    </main>
  );
}
