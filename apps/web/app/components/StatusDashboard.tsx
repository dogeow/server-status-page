"use client";

import { useEffect, useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import type {
  DailyStatus,
  Incident,
  PublicStatusPayload,
  ServiceStatus,
  StatusComponent,
  StatusGroup,
} from "../types";
import { fillHistory, getHistoryPeriod } from "../lib/status-history";

const statusCopy: Record<ServiceStatus, { label: string; symbol: string }> = {
  operational: { label: "运行正常", symbol: "✓" },
  degraded: { label: "性能下降", symbol: "!" },
  partial_outage: { label: "部分中断", symbol: "!" },
  major_outage: { label: "严重中断", symbol: "×" },
  maintenance: { label: "维护中", symbol: "•" },
  unknown: { label: "状态未知", symbol: "?" },
};

function clientStatus(value: unknown): ServiceStatus {
  const status = String(value ?? "unknown");
  if (status === "degraded_performance") return "degraded";
  if (status === "under_maintenance") return "maintenance";
  if (["up", "healthy", "ok"].includes(status)) return "operational";
  if (["down", "outage", "critical"].includes(status)) return "major_outage";
  return status in statusCopy ? status as ServiceStatus : "unknown";
}

function formatDateTime(value: string, timezone: string) {
  try {
    return new Intl.DateTimeFormat("zh-CN", {
      timeZone: timezone,
      month: "short",
      day: "numeric",
      hour: "2-digit",
      minute: "2-digit",
    }).format(new Date(value));
  } catch {
    return value;
  }
}

function formatUptime(value: number | null) {
  return value === null ? "—" : `${value.toFixed(value >= 99 ? 2 : 1)}%`;
}

function historyStatus(day: DailyStatus): ServiceStatus {
  return day.maintenance && day.status === "operational" ? "maintenance" : day.status;
}

function StatusIcon({ status, small = false }: { status: ServiceStatus; small?: boolean }) {
  const copy = statusCopy[status];
  return (
    <span
      className={`status-icon status-${status}${small ? " status-icon-small" : ""}`}
      aria-label={copy.label}
    >
      {copy.symbol}
    </span>
  );
}

function HistoryBars({ history, label, anchor }: { history: DailyStatus[]; label: string; anchor: string }) {
  const bars = fillHistory(history, anchor);
  const [selectedIndex, setSelectedIndex] = useState<number | null>(null);
  const [previewIndex, setPreviewIndex] = useState<number | null>(null);
  const visibleIndex = previewIndex ?? selectedIndex;

  return (
    <div className="history-bars" aria-label={`${label} 最近 90 天状态`}>
      {bars.map((day, index) => {
        const status = historyStatus(day);
        const statusLabel = status === "unknown" && day.uptimePercent == null
          ? "暂无监控数据"
          : statusCopy[status].label;
        const uptimeLabel = day.uptimePercent == null ? null : `${day.uptimePercent.toFixed(2)}% uptime`;
        const detailLabel = `${day.date} · ${statusLabel}${uptimeLabel ? ` · ${uptimeLabel}` : ""}`;
        const tooltipAlignment = index < 5 ? "start" : index > bars.length - 6 ? "end" : "center";

        return (
          <button
            type="button"
            className={`history-bar status-${status}`}
            data-status={status}
            data-tooltip-align={tooltipAlignment}
            key={`${day.date}-${index}`}
            aria-label={detailLabel}
            aria-expanded={visibleIndex === index}
            onClick={() => setSelectedIndex((current) => current === index ? null : index)}
            onMouseEnter={() => setPreviewIndex(index)}
            onMouseLeave={() => setPreviewIndex(null)}
            onFocus={() => setPreviewIndex(index)}
            onBlur={() => setPreviewIndex(null)}
          >
            {visibleIndex === index ? (
              <span className="history-tooltip" role="tooltip">
                <strong>{day.date}</strong>
                <span>{statusLabel}{uptimeLabel ? ` · ${uptimeLabel}` : ""}</span>
              </span>
            ) : null}
          </button>
        );
      })}
    </div>
  );
}

function ComponentRow({ component, historyAnchor }: { component: StatusComponent; historyAnchor: string }) {
  return (
    <div className="component-row">
      <div className="component-title">
        <StatusIcon status={component.status} small />
        <div>
          <strong>{component.name}</strong>
          {component.description ? <p>{component.description}</p> : null}
        </div>
      </div>
      <div className="component-metrics">
        {component.latencyMs != null ? <span>{Math.round(component.latencyMs)} ms</span> : null}
        <strong>{formatUptime(component.uptimePercent)}</strong>
      </div>
      <HistoryBars history={component.dailyHistory} label={component.name} anchor={historyAnchor} />
    </div>
  );
}

function GroupRow({ group, historyAnchor }: { group: StatusGroup; historyAnchor: string }) {
  const [open, setOpen] = useState(false);
  return (
    <section className={`group-row${open ? " group-open" : ""}`}>
      <button
        type="button"
        className="group-toggle"
        aria-expanded={open}
        onClick={() => setOpen((value) => !value)}
      >
        <span className="group-heading">
          <StatusIcon status={group.status} />
          <span className="group-name">{group.name}</span>
          <span className="component-count">
            {group.components.length} 个组件
            <span className="chevron" aria-hidden="true">⌄</span>
          </span>
        </span>
        <span className="group-uptime">
          {formatUptime(group.uptimePercent)} <small>uptime</small>
        </span>
      </button>
      <HistoryBars history={group.dailyHistory} label={group.name} anchor={historyAnchor} />
      {open ? (
        <div className="component-list">
          {group.components.map((component) => (
            <ComponentRow key={component.id} component={component} historyAnchor={historyAnchor} />
          ))}
        </div>
      ) : null}
    </section>
  );
}

function IncidentCard({ incident, timezone }: { incident: Incident; timezone: string }) {
  return (
    <article className={`incident-card status-${incident.severity}`}>
      <div className="incident-card-topline">
        <span className="incident-phase">{statusCopy[incident.severity].label}</span>
        <time>{formatDateTime(incident.startedAt, timezone)}</time>
      </div>
      <h2><Link href={`/incidents/${incident.slug}`}>{incident.title}</Link></h2>
      {incident.message ? <p>{incident.message}</p> : null}
      {incident.componentNames.length ? (
        <div className="incident-components">影响：{incident.componentNames.join("、")}</div>
      ) : null}
    </article>
  );
}

export function StatusDashboard({ initialStatus }: { initialStatus: PublicStatusPayload }) {
  const router = useRouter();
  const [periodOffset, setPeriodOffset] = useState(0);
  const [displayGroups, setDisplayGroups] = useState(initialStatus.groups);
  const [historyLoading, setHistoryLoading] = useState(false);
  const copy = statusCopy[initialStatus.overallStatus];
  const visibleGroups = periodOffset === 0 ? initialStatus.groups : displayGroups;
  const period = useMemo(
    () => getHistoryPeriod(initialStatus.updatedAt, periodOffset),
    [initialStatus.updatedAt, periodOffset],
  );

  async function changePeriod(nextOffset: number) {
    setPeriodOffset(nextOffset);
    if (nextOffset === 0) {
      return;
    }
    const nextPeriod = getHistoryPeriod(initialStatus.updatedAt, nextOffset);
    setHistoryLoading(true);
    try {
      const response = await fetch(`/api/public/v1/history?from=${nextPeriod.from}&to=${nextPeriod.to}`, {
        headers: { Accept: "application/json" },
      });
      if (!response.ok) throw new Error("history unavailable");
      const payload = await response.json() as Record<string, unknown>;
        const raw = (payload.data ?? payload) as Record<string, unknown>;
        if (!Array.isArray(raw.groups)) throw new Error("history unavailable");
        const groups = raw.groups.map((item, groupIndex) => {
          const group = (item ?? {}) as Record<string, unknown>;
          const daily = Array.isArray(group.daily_history ?? group.dailyHistory)
            ? (group.daily_history ?? group.dailyHistory) as Array<Record<string, unknown>>
            : [];
          return {
            id: String(group.id ?? `history-group-${groupIndex}`),
            name: String(group.name ?? `Group ${groupIndex + 1}`),
            status: clientStatus(group.status),
            uptimePercent: group.uptime_percent == null ? null : Number(group.uptime_percent),
            dailyHistory: daily.map((day) => ({
              date: String(day.date ?? day.day),
              status: clientStatus(day.status),
              uptimePercent: day.uptime_percent == null ? null : Number(day.uptime_percent),
              maintenance: Boolean(day.maintenance),
            })),
            components: Array.isArray(group.components)
              ? group.components.map((item, componentIndex) => {
                  const component = (item ?? {}) as Record<string, unknown>;
                  const componentDaily = Array.isArray(component.daily_history ?? component.dailyHistory)
                    ? (component.daily_history ?? component.dailyHistory) as Array<Record<string, unknown>>
                    : [];
                  return {
                    id: String(component.id ?? `history-component-${componentIndex}`),
                    name: String(component.name ?? `Component ${componentIndex + 1}`),
                    description: component.description ? String(component.description) : null,
                    status: clientStatus(component.status),
                    uptimePercent: component.uptime_percent == null ? null : Number(component.uptime_percent),
                    latencyMs: component.latency_ms == null ? null : Number(component.latency_ms),
                    dailyHistory: componentDaily.map((day) => ({
                      date: String(day.date ?? day.day),
                      status: clientStatus(day.status),
                      uptimePercent: day.uptime_percent == null ? null : Number(day.uptime_percent),
                      maintenance: Boolean(day.maintenance),
                    })),
                  };
                })
              : [],
          } satisfies StatusGroup;
        });
        setDisplayGroups(groups);
    } catch {
      setDisplayGroups([]);
    } finally {
      setHistoryLoading(false);
    }
  }

  useEffect(() => {
    const poll = window.setInterval(() => router.refresh(), 30_000);
    const wsUrl = process.env.NEXT_PUBLIC_REVERB_WS_URL;
    let socket: WebSocket | null = null;
    if (wsUrl) {
      try {
        socket = new WebSocket(wsUrl);
        socket.onopen = () => {
          socket?.send(
            JSON.stringify({
              event: "pusher:subscribe",
              data: { channel: "public-status" },
            }),
          );
        };
        socket.onmessage = (event) => {
          try {
            const payload = JSON.parse(String(event.data)) as { event?: string };
            if (payload.event?.includes("status") || payload.event?.includes("incident")) {
              router.refresh();
            }
          } catch {
            // Ignore malformed broadcast frames; polling remains authoritative.
          }
        };
      } catch {
        socket = null;
      }
    }
    return () => {
      window.clearInterval(poll);
      socket?.close();
    };
  }, [router]);

  return (
    <>
      <section className={`overall-banner status-${initialStatus.overallStatus}`}>
        <StatusIcon status={initialStatus.overallStatus} />
        <div>
          <p className="eyebrow">当前状态</p>
          <h1>{copy.label}</h1>
          <p>
            {initialStatus.controlPlaneAvailable
              ? initialStatus.statusPage.description || "所有关键服务均由独立探针持续验证。"
              : "暂时无法连接控制面，已保留最后可用页面结构，稍后将自动重试。"}
          </p>
        </div>
        <div className="last-updated">
          <span className="live-dot" />
          更新于 {formatDateTime(initialStatus.updatedAt, initialStatus.statusPage.timezone)}
        </div>
      </section>

      {initialStatus.maintenances.length ? (
        <section className="maintenance-strip" aria-label="计划维护">
          <span className="maintenance-icon" aria-hidden="true">◷</span>
          <div>
            <strong>{initialStatus.maintenances[0].title}</strong>
            <p>
              {formatDateTime(initialStatus.maintenances[0].startsAt, initialStatus.statusPage.timezone)}
              {" – "}
              {formatDateTime(initialStatus.maintenances[0].endsAt, initialStatus.statusPage.timezone)}
            </p>
          </div>
        </section>
      ) : null}

      {initialStatus.incidents.length ? (
        <section className="incidents-section" aria-labelledby="active-incidents-title">
          <div className="section-heading">
            <div>
              <p className="eyebrow">Incidents</p>
              <h2 id="active-incidents-title">进行中的事件</h2>
            </div>
            <Link href="/history">查看全部</Link>
          </div>
          <div className="incident-grid">
            {initialStatus.incidents.map((incident) => (
              <IncidentCard
                key={incident.id}
                incident={incident}
                timezone={initialStatus.statusPage.timezone}
              />
            ))}
          </div>
        </section>
      ) : null}

      <section className="status-card" aria-labelledby="system-status-title">
        <div className="status-card-header">
          <h2 id="system-status-title">System status</h2>
          <div className="period-control" aria-label="历史周期">
            <button type="button" onClick={() => void changePeriod(periodOffset - 1)} aria-label="上一周期">‹</button>
            <span>{period.label}</span>
            <button type="button" onClick={() => void changePeriod(Math.min(0, periodOffset + 1))} disabled={periodOffset >= 0} aria-label="下一周期">›</button>
            {historyLoading ? <span className="history-loading" aria-live="polite">读取中</span> : null}
          </div>
          <div className="legend" aria-label="状态图例">
            <span><i className="status-operational" />正常</span>
            <span><i className="status-degraded" />降级</span>
            <span><i className="status-major_outage" />中断</span>
            <span><i className="status-unknown" />暂无数据</span>
          </div>
        </div>
        <div className="groups-list">
          {visibleGroups.length ? (
            visibleGroups.map((group) => (
              <GroupRow key={group.id} group={group} historyAnchor={period.to} />
            ))
          ) : (
            <div className="empty-state">
              <span className="empty-orbit" aria-hidden="true" />
              <h3>{initialStatus.controlPlaneAvailable ? "尚未发布组件" : "等待控制面数据"}</h3>
              <p>
                {initialStatus.controlPlaneAvailable
                  ? "管理员添加并公开组件后，状态和 90 天历史会显示在这里。"
                  : "页面会每 30 秒自动重试，不会使用虚构状态替代真实探测结果。"}
              </p>
            </div>
          )}
        </div>
      </section>

      <div className="history-action">
        <Link className="secondary-button" href="/history">
          <span aria-hidden="true">▦</span> 查看历史记录
        </Link>
      </div>
    </>
  );
}
