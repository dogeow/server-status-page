import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { SiteHeader } from "../../components/SiteHeader";
import { getIncident, getPublicStatus } from "../../lib/api";

export const metadata: Metadata = { title: "事件详情" };

function format(value: string) {
  try {
    return new Intl.DateTimeFormat("zh-CN", {
      dateStyle: "medium",
      timeStyle: "short",
      timeZone: "Asia/Shanghai",
    }).format(new Date(value));
  } catch {
    return value;
  }
}

export default async function IncidentPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const [status, incident] = await Promise.all([getPublicStatus(), getIncident(slug)]);
  if (!incident) notFound();

  return (
    <main className="site-shell">
      <SiteHeader siteName={status.statusPage.name} />
      <header className="page-heading incident-detail-head">
        <div>
          <p className="eyebrow">Incident</p>
          <h1>{incident.title}</h1>
          <p>{incident.componentNames.length ? `受影响组件：${incident.componentNames.join("、")}` : "事件详情与进展记录"}</p>
        </div>
        <span className="status-pill">{incident.phase}</span>
      </header>
      <article className="timeline-card">
        <strong>{incident.resolvedAt ? "事件已恢复" : "事件处理中"}</strong>
        <p>{incident.message || "运维团队正在持续跟进此事件。"}</p>
        <ol className="timeline-list">
          {(incident.updates.length ? incident.updates : [{
            id: `${incident.id}-start`,
            status: incident.phase,
            message: incident.message || "已检测到异常并开始调查。",
            createdAt: incident.startedAt,
          }]).map((update) => (
            <li className="timeline-item" key={update.id}>
              <time>{format(update.createdAt)}</time>
              <h3>{update.status}</h3>
              <p>{update.message}</p>
            </li>
          ))}
        </ol>
      </article>
      <div className="history-action"><Link className="secondary-button" href="/">返回状态页</Link></div>
    </main>
  );
}
