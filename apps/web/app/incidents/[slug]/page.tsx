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

const phaseLabel: Record<string, string> = {
  investigating: "调查中",
  identified: "已定位",
  monitoring: "监控恢复中",
  resolved: "已恢复",
};

function phaseText(value: string) {
  return phaseLabel[value] ?? "处理中";
}

export default async function IncidentPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const [status, lookup] = await Promise.all([getPublicStatus(), getIncident(slug)]);
  if (lookup.state === "missing") notFound();
  if (lookup.state === "unavailable") {
    return (
      <main className="site-shell">
        <SiteHeader siteName={status.statusPage.name} />
        <article className="timeline-card">
          <strong>事件暂时无法读取</strong>
          <p>控制面暂时没有返回这条事件，请稍后再试。</p>
        </article>
        <div className="history-action"><Link className="secondary-button" href="/">返回状态页</Link></div>
      </main>
    );
  }
  const incident = lookup.incident;

  return (
    <main className="site-shell">
      <SiteHeader siteName={status.statusPage.name} />
      <header className="page-heading incident-detail-head">
        <div>
          <p className="eyebrow">事件</p>
          <h1>{incident.title}</h1>
          <p>{incident.componentNames.length ? `受影响组件：${incident.componentNames.join("、")}` : "事件详情与进展记录"}</p>
        </div>
        <span className="status-pill">{phaseText(incident.phase)}</span>
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
              <h3>{phaseText(update.status)}</h3>
              <p>{update.message}</p>
            </li>
          ))}
        </ol>
      </article>
      <div className="history-action"><Link className="secondary-button" href="/">返回状态页</Link></div>
    </main>
  );
}
