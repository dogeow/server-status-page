import type { Metadata } from "next";
import { SiteHeader } from "../components/SiteHeader";
import { SubscribeForm } from "./subscribe-form";
import { getPublicStatus } from "../lib/api";

export const metadata: Metadata = {
  title: "订阅更新",
  description: "按组件订阅故障、恢复与计划维护邮件。",
};

export default async function SubscribePage() {
  const status = await getPublicStatus();
  const components = status.groups.flatMap((group) => group.components.map((component) => ({
    id: component.id,
    name: `${group.name} · ${component.name}`,
  })));
  return (
    <main className="site-shell">
      <SiteHeader siteName={status.statusPage.name} />
      <div className="form-page">
        <header className="page-heading">
          <p className="eyebrow">Subscribe</p>
          <h1>订阅状态更新</h1>
          <p>选择关心的组件。确认邮箱后，只会收到已发布的事件、恢复和计划维护通知。</p>
        </header>
        <SubscribeForm components={components} />
      </div>
    </main>
  );
}
