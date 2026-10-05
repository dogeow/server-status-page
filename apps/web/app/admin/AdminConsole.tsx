"use client";

import { FormEvent, useCallback, useEffect, useMemo, useRef, useState } from "react";
import Link from "next/link";
import { ThemeToggle } from "../components/ThemeToggle";

type Section =
  | "overview"
  | "monitors"
  | "components"
  | "integrations"
  | "agents"
  | "incidents"
  | "maintenance"
  | "notifications"
  | "policies"
  | "users"
  | "audit";

type Resource = Record<string, unknown> & { id?: string | number };

const sections: Array<{ id: Section; label: string; icon: string }> = [
  { id: "overview", label: "概览", icon: "◫" },
  { id: "monitors", label: "监控项", icon: "⌁" },
  { id: "components", label: "状态组件", icon: "▤" },
  { id: "integrations", label: "Laravel 应用探针", icon: "⌘" },
  { id: "agents", label: "Agent", icon: "◇" },
  { id: "incidents", label: "事件", icon: "△" },
  { id: "maintenance", label: "维护窗口", icon: "◷" },
  { id: "notifications", label: "通知", icon: "↗" },
  { id: "policies", label: "告警策略", icon: "⌚" },
  { id: "users", label: "用户", icon: "♙" },
  { id: "audit", label: "审计日志", icon: "≡" },
];

const endpoint: Record<Exclude<Section, "overview">, string> = {
  monitors: "monitors",
  components: "components",
  integrations: "laravel-integrations",
  agents: "agents",
  incidents: "incidents",
  maintenance: "maintenance-windows",
  notifications: "notification-channels",
  policies: "notification-policies",
  users: "users",
  audit: "audit-logs",
};

const title: Record<Section, string> = Object.fromEntries(
  sections.map((section) => [section.id, section.label]),
) as Record<Section, string>;

const sectionHelp: Partial<Record<Section, string>> = {
  components: "公开状态页展示的服务组件。组件本身不执行检查，其状态由关联的一个或多个监控项汇总得出。",
  monitors: "实际执行 HTTP、数据库、队列等检查的探针。多个监控项可以共同决定一个状态组件的状态。",
  integrations: "接入你自己的 Laravel 项目，用于接收 Queue、Scheduler 和关键任务心跳；这不是提供给外部用户的开放服务。",
  users: "系统不开放注册。当前自用只需保留 Owner；Admin 和 Viewer 仅用于以后授权其他管理人员。",
};

function getCookie(name: string) {
  return document.cookie
    .split("; ")
    .find((row) => row.startsWith(`${name}=`))
    ?.split("=")
    .slice(1)
    .join("=");
}

async function apiFetch(path: string, init: RequestInit = {}) {
  const method = init.method?.toUpperCase() ?? "GET";
  if (!["GET", "HEAD", "OPTIONS"].includes(method)) {
    await fetch("/sanctum/csrf-cookie", { credentials: "include" });
  }
  const xsrf = getCookie("XSRF-TOKEN");
  const response = await fetch(`/api/admin/v1/${path}`, {
    ...init,
    credentials: "include",
    headers: {
      Accept: "application/json",
      ...(init.body ? { "Content-Type": "application/json" } : {}),
      ...(xsrf ? { "X-XSRF-TOKEN": decodeURIComponent(xsrf) } : {}),
      ...init.headers,
    },
  });
  if (response.status === 401) {
    window.location.assign("/admin/login");
    throw new Error("需要登录");
  }
  const payload = (await response.json().catch(() => ({}))) as Record<string, unknown>;
  if (!response.ok) throw new Error(String(payload.message ?? "请求失败"));
  return payload;
}

type PageMeta = { current: number; last: number; total: number };

function pageMetaFrom(payload: Record<string, unknown>, rowCount: number): PageMeta {
  return {
    current: Math.max(1, Number(payload.current_page ?? 1) || 1),
    last: Math.max(1, Number(payload.last_page ?? 1) || 1),
    total: Math.max(0, Number(payload.total ?? rowCount) || 0),
  };
}

function wallTimeToIso(value: string, timeZone = "Asia/Shanghai") {
  const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(value);
  if (!match) return new Date(value).toISOString();
  const year = Number(match[1]);
  const month = Number(match[2]);
  const day = Number(match[3]);
  const hour = Number(match[4]);
  const minute = Number(match[5]);
  const utcGuess = Date.UTC(year, month - 1, day, hour, minute);
  const parts = new Intl.DateTimeFormat("en-US", {
    timeZone,
    hourCycle: "h23",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
  }).formatToParts(new Date(utcGuess));
  const part = (type: string) => Number(parts.find((item) => item.type === type)?.value ?? "0");
  const zoned = Date.UTC(part("year"), part("month") - 1, part("day"), part("hour") % 24, part("minute"));
  return new Date(utcGuess + (utcGuess - zoned)).toISOString();
}

function rowsFrom(payload: Record<string, unknown>): Resource[] {
  const data = payload.data ?? payload;
  if (Array.isArray(data)) return data as Resource[];
  if (data && typeof data === "object") {
    const record = data as Record<string, unknown>;
    if (Array.isArray(record.data)) return record.data as Resource[];
    if (Array.isArray(record.items)) return record.items as Resource[];
  }
  return [];
}

function statusClass(value: unknown) {
  const status = String(value ?? "unknown");
  if (["up", "healthy", "ok", "success", "pass", "online", "operational", "active", "enabled", "resolved", "completed"].includes(status)) return "operational";
  if (["degraded", "degraded_performance", "partial_outage", "under_maintenance", "warning", "investigating", "scheduled", "in_progress"].includes(status)) return "degraded";
  if (["down", "offline", "major_outage", "failed", "revoked"].includes(status)) return "major_outage";
  return "unknown";
}

function statusLabel(value: unknown) {
  const status = String(value ?? "unknown");
  const labels: Record<string, string> = {
    operational: "运行正常",
    up: "运行正常",
    healthy: "运行正常",
    ok: "正常",
    success: "正常",
    pass: "正常",
    active: "已启用",
    enabled: "已启用",
    disabled: "已停用",
    online: "在线",
    offline: "离线",
    pending: "待注册",
    revoked: "已撤销",
    degraded: "性能下降",
    degraded_performance: "性能下降",
    warning: "警告",
    partial_outage: "部分中断",
    major_outage: "严重中断",
    down: "中断",
    failed: "失败",
    under_maintenance: "维护中",
    unknown: "未知",
    investigating: "调查中",
    identified: "已定位",
    monitoring: "监控恢复中",
    resolved: "已恢复",
    scheduled: "已计划",
    in_progress: "进行中",
    completed: "已完成",
    cancelled: "已取消",
  };
  return labels[status] ?? status;
}

function rowStatus(row: Resource) {
  return row.status ?? row.state ?? (row.enabled === false ? "disabled" : row.enabled === true ? "active" : "unknown");
}

function monitorTypeLabel(value: unknown) {
  const type = String(value ?? "");
  const labels: Record<string, string> = {
    http: "HTTP(S)",
    nextjs: "Next.js",
    laravel: "Laravel",
    squid: "Squid",
    mysql: "MySQL",
    postgresql: "PostgreSQL",
    redis: "Redis",
    reverb: "Laravel Reverb",
    laravel_queue: "Laravel Queue",
    laravel_scheduler: "Laravel Scheduler",
    tcp: "TCP",
    dns: "DNS",
    tls: "TLS",
    heartbeat: "Push Heartbeat",
  };
  return labels[type] ?? (type || "—");
}

function roleLabel(value: unknown) {
  return ({ owner: "所有者", admin: "管理员", viewer: "只读用户" } as Record<string, string>)[String(value ?? "")] ?? String(value ?? "—");
}

function resourceLabel(row: Resource) {
  return String(row.name ?? row.title ?? row.email ?? row.event ?? row.id ?? "未命名");
}

function resourceDetail(row: Resource) {
  return [row.kind, row.type, row.target, row.description, row.role]
    .filter(Boolean)
    .map(String)
    .join(" · ");
}

function formatAdminDate(value: unknown) {
  if (!value) return "—";
  const date = new Date(String(value));
  if (Number.isNaN(date.getTime())) return "—";

  const parts = new Intl.DateTimeFormat("en-CA", {
    timeZone: "Asia/Shanghai",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
    hourCycle: "h23",
  }).formatToParts(date);
  const part = (type: Intl.DateTimeFormatPartTypes) => parts.find((item) => item.type === type)?.value ?? "00";
  return `${part("year")}-${part("month")}-${part("day")} ${part("hour")}:${part("minute")}:${part("second")}`;
}

function eventTypeLabel(value: unknown) {
  const event = String(value ?? "");
  const labels: Record<string, string> = {
    "component.status_changed": "组件状态变化",
    "monitor.tls_certificate_expiring": "TLS 证书即将到期",
    "monitor.configuration_error": "监控配置异常",
    "agent.offline": "Agent 已离线",
    "incident.created": "服务事件已创建",
    "incident.updated": "服务事件已更新",
    "incident.resolved": "服务事件已恢复",
    "incident.reminder": "服务事件提醒",
    "maintenance.scheduled": "维护窗口已计划",
    "maintenance.updated": "维护窗口已更新",
  };
  return labels[event] ?? "系统事件";
}

function eventErrorLabel(value: unknown) {
  const error = String(value ?? "");
  const labels: Record<string, string> = {
    tls_certificate_expiring: "证书有效期即将结束",
    authentication_failed: "认证失败",
    configuration_error: "配置错误",
  };
  return labels[error] ?? (error ? `错误代码：${error}` : "需要检查配置");
}

function eventDetail(row: Resource) {
  const event = String(row.event ?? "");
  const target = String(row.target_name ?? "相关服务");
  if (event === "component.status_changed") {
    return `${target}：${statusLabel(row.from_status)} → ${statusLabel(row.to_status)}`;
  }
  if (event === "monitor.tls_certificate_expiring") {
    return row.expires_at ? `${target}：证书将在 ${formatAdminDate(row.expires_at)} 到期` : `${target}：证书有效期即将结束`;
  }
  if (event === "monitor.configuration_error") return `${target}：${eventErrorLabel(row.error_code)}`;
  if (event === "agent.offline") {
    return row.last_seen_at ? `${target}：最后在线于 ${formatAdminDate(row.last_seen_at)}` : `${target}：暂时无法连接`;
  }
  if (event.startsWith("incident.")) return `${target} · ${statusLabel(row.status ?? row.severity)}`;
  if (event.startsWith("maintenance.")) {
    return row.starts_at && row.ends_at
      ? `${target}：${formatAdminDate(row.starts_at)} 至 ${formatAdminDate(row.ends_at)}`
      : target;
  }
  return row.target_name ? target : `内部类型：${event || "未知"}`;
}

function eventStatus(row: Resource) {
  const event = String(row.event ?? "");
  if (event === "component.status_changed") return row.to_status;
  if (event === "monitor.tls_certificate_expiring" || event === "monitor.configuration_error") return "warning";
  if (event === "agent.offline") return "offline";
  if (event === "incident.resolved") return "resolved";
  if (event.startsWith("incident.")) return row.severity ?? row.status;
  if (event.startsWith("maintenance.")) return "under_maintenance";
  return row.status;
}

function auditValue(value: unknown) {
  return value && typeof value === "object" && !Array.isArray(value) ? value as Resource : {};
}

function auditActionLabel(value: unknown) {
  const action = String(value ?? "");
  const labels: Record<string, string> = {
    create: "创建",
    update: "更新",
    delete: "删除",
    merge: "合并组件分组",
    "credentials.update": "更新登录账号",
    "ops.monitor_inventory_sync": "同步监控清单",
  };
  return labels[action] ?? (action || "未知操作");
}

function auditTypeLabel(value: unknown) {
  const type = String(value ?? "").split("\\").pop() ?? "";
  const labels: Record<string, string> = {
    User: "用户",
    ComponentGroup: "组件分组",
    Component: "组件",
    StatusPage: "状态页",
    Monitor: "监控项",
    Agent: "Agent",
    Incident: "事件",
    MaintenanceWindow: "维护窗口",
    NotificationChannel: "通知渠道",
    NotificationPolicy: "告警策略",
  };
  return labels[type] ?? (type || "记录");
}

function auditTargetLabel(row: Resource) {
  const before = auditValue(row.before);
  const after = auditValue(row.after);
  if (row.action === "merge") {
    const source = before.name;
    const target = after.target_group_name;
    if (source && target) return `${String(source)} → ${String(target)}`;
  }
  if (row.action === "credentials.update" && before.email && after.email) {
    return `${String(before.email)} → ${String(after.email)}`;
  }
  const label = after.name ?? after.title ?? after.email ?? before.name ?? before.title ?? before.email;
  if (label) return String(label);
  return `${auditTypeLabel(row.auditable_type)} #${String(row.auditable_id ?? "—")}`;
}

function AuditTable({ rows }: { rows: Resource[] }) {
  return (
    <div style={{ overflowX: "auto" }}>
      <table className="resource-table">
        <thead><tr><th>操作</th><th>对象</th><th>操作者</th><th>时间</th></tr></thead>
        <tbody>
          {rows.map((row, index) => (
            <tr key={String(row.id ?? index)}>
              <td>
                <span className="resource-name">{auditActionLabel(row.action)}</span>
                <div className="resource-sub">{String(row.action ?? "未知")}</div>
              </td>
              <td>
                <span className="resource-name">{auditTargetLabel(row)}</span>
                <div className="resource-sub">{auditTypeLabel(row.auditable_type)} #{String(row.auditable_id ?? "—")}</div>
              </td>
              <td>{row.user_id ? `用户 #${String(row.user_id)}` : "系统"}</td>
              <td><time dateTime={String(row.created_at ?? "")}>{formatAdminDate(row.created_at)}</time></td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function StatusCell({ value }: { value: unknown }) {
  return <><span className={`mini-status status-${statusClass(value)}`} />{statusLabel(value)}</>;
}

type TableActions = {
  canWrite: boolean;
  pendingId: string;
  onResolveIncident: (row: Resource) => void;
  onToggleMonitor: (row: Resource) => void;
  onEditComponent: (row: Resource) => void;
};

function SpecializedTable({ rows, section, actions }: { rows: Resource[]; section: Section; actions?: TableActions }) {
  if (section === "components") {
    return <div style={{ overflowX: "auto" }}><table className="resource-table">
      <thead><tr><th>公开组件</th><th>当前状态</th><th>所属分组</th><th>状态更新时间</th>{actions?.canWrite ? <th>操作</th> : null}</tr></thead>
      <tbody>{rows.map((row, index) => <tr key={String(row.id ?? index)}>
        <td><span className="resource-name">{resourceLabel(row)}</span>{row.description ? <div className="resource-sub">{String(row.description)}</div> : null}</td>
        <td><StatusCell value={rowStatus(row)} /></td>
        <td>组件组 #{String(row.component_group_id ?? "—")}</td>
        <td><time dateTime={String(row.status_changed_at ?? row.updated_at ?? "")}>{formatAdminDate(row.status_changed_at ?? row.updated_at)}</time></td>
        {actions?.canWrite ? <td><button className="secondary-button row-action" type="button" disabled={actions.pendingId === String(row.id ?? "")} onClick={() => actions.onEditComponent(row)}>编辑</button></td> : null}
      </tr>)}</tbody>
    </table></div>;
  }
  if (section === "monitors") {
    return <div style={{ overflowX: "auto" }}><table className="resource-table">
      <thead><tr><th>监控探针</th><th>类型</th><th>状态</th><th>频率</th><th>最近检查</th>{actions?.canWrite ? <th>操作</th> : null}</tr></thead>
      <tbody>{rows.map((row, index) => <tr key={String(row.id ?? index)}>
        <td><span className="resource-name">{resourceLabel(row)}</span><div className="resource-sub">状态组件 #{String(row.component_id ?? "—")}</div></td>
        <td>{monitorTypeLabel(row.type)}</td>
        <td><StatusCell value={row.enabled === false ? "disabled" : rowStatus(row)} /></td>
        <td>{row.interval_seconds ? `${String(row.interval_seconds)} 秒` : "—"}</td>
        <td><time dateTime={String(row.last_checked_at ?? row.updated_at ?? "")}>{formatAdminDate(row.last_checked_at ?? row.updated_at)}</time></td>
        {actions?.canWrite ? <td><button className="secondary-button row-action" type="button" disabled={actions.pendingId === String(row.id ?? "")} onClick={() => actions.onToggleMonitor(row)}>{row.enabled === false ? "启用" : "停用"}</button></td> : null}
      </tr>)}</tbody>
    </table></div>;
  }
  if (section === "agents") {
    return <div style={{ overflowX: "auto" }}><table className="resource-table">
      <thead><tr><th>Agent</th><th>状态</th><th>版本</th><th>最后在线</th></tr></thead>
      <tbody>{rows.map((row, index) => <tr key={String(row.id ?? index)}>
        <td><span className="resource-name">{resourceLabel(row)}</span></td>
        <td><StatusCell value={rowStatus(row)} /></td>
        <td>{String(row.version ?? "—")}</td>
        <td><time dateTime={String(row.last_seen_at ?? "")}>{formatAdminDate(row.last_seen_at)}</time></td>
      </tr>)}</tbody>
    </table></div>;
  }
  if (section === "integrations") {
    return <div style={{ overflowX: "auto" }}><table className="resource-table">
      <thead><tr><th>Laravel 应用</th><th>Application ID</th><th>状态</th><th>最后上报</th></tr></thead>
      <tbody>{rows.map((row, index) => <tr key={String(row.id ?? index)}>
        <td><span className="resource-name">{resourceLabel(row)}</span></td>
        <td>{String(row.application_id ?? "—")}</td>
        <td><StatusCell value={rowStatus(row)} /></td>
        <td><time dateTime={String(row.last_seen_at ?? row.created_at ?? "")}>{formatAdminDate(row.last_seen_at ?? row.created_at)}</time></td>
      </tr>)}</tbody>
    </table></div>;
  }
  if (section === "users") {
    return <div style={{ overflowX: "auto" }}><table className="resource-table">
      <thead><tr><th>用户</th><th>Email</th><th>角色</th><th>创建时间</th></tr></thead>
      <tbody>{rows.map((row, index) => <tr key={String(row.id ?? index)}>
        <td><span className="resource-name">{String(row.name ?? "—")}</span></td>
        <td>{String(row.email ?? "—")}</td>
        <td>{roleLabel(row.role)}</td>
        <td><time dateTime={String(row.created_at ?? "")}>{formatAdminDate(row.created_at)}</time></td>
      </tr>)}</tbody>
    </table></div>;
  }
  return null;
}

function ResourceTable({ rows, section, actions, loading = false }: { rows: Resource[]; section: Section; actions?: TableActions; loading?: boolean }) {
  if (!rows.length) return <div className="admin-empty">{loading ? "读取中…" : "暂无记录。创建后会从真实控制面数据自动更新。"}</div>;
  if (section === "audit") return <AuditTable rows={rows} />;
  if (["components", "monitors", "agents", "integrations", "users"].includes(section)) return <SpecializedTable rows={rows} section={section} actions={actions} />;
  const showIncidentAction = section === "incidents" && Boolean(actions?.canWrite);
  return (
    <div style={{ overflowX: "auto" }}>
      <table className="resource-table">
        <thead><tr><th>名称</th><th>状态</th><th>更新时间</th>{showIncidentAction ? <th>操作</th> : null}</tr></thead>
        <tbody>
          {rows.map((row, index) => (
            <tr key={String(row.id ?? index)}>
              <td>
                <span className="resource-name">{resourceLabel(row)}</span>
                {resourceDetail(row) ? <div className="resource-sub">{resourceDetail(row)}</div> : null}
              </td>
              <td>
                <StatusCell value={rowStatus(row)} />
              </td>
              <td><time dateTime={String(row.updated_at ?? row.last_seen_at ?? row.created_at ?? "")}>{formatAdminDate(row.updated_at ?? row.last_seen_at ?? row.created_at)}</time></td>
              {showIncidentAction ? <td>{String(row.status ?? "") === "resolved" ? "—" : <button className="secondary-button row-action" type="button" disabled={actions?.pendingId === String(row.id ?? "")} onClick={() => actions?.onResolveIncident(row)}>标记已恢复</button>}</td> : null}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function Field({ label, name, type = "text", required = false, children, help, defaultValue }: {
  label: string;
  name: string;
  type?: string;
  required?: boolean;
  children?: React.ReactNode;
  help?: string;
  defaultValue?: string | number;
}) {
  return (
    <div className="field">
      <label htmlFor={`field-${name}`}>{label}</label>
      {children ?? <input id={`field-${name}`} name={name} type={type} required={required} defaultValue={defaultValue} />}
      {help ? <small>{help}</small> : null}
    </div>
  );
}

function CreateForm({ section, onCreated, onClose, onSecret }: { section: Section; onCreated: (message?: string) => void; onClose: () => void; onSecret: (secret: string) => void }) {
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [secret, setSecret] = useState("");
  const [monitorKind, setMonitorKind] = useState("http");

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaving(true);
    setError("");
    const data = Object.fromEntries(new FormData(event.currentTarget).entries());
    const path = section === "agents" ? "agent-enrollment-tokens" : endpoint[section as Exclude<Section, "overview">];
    let body: Record<string, unknown> = data;
    if (section === "monitors") {
      const kind = String(data.kind);
      const target = String(data.target || "");
      const config: Record<string, unknown> = {};
      const secretConfig: Record<string, unknown> = {};
      if (["http", "nextjs", "laravel"].includes(kind)) {
        Object.assign(config, { url: target, expected_status: Number(data.expected_status || 200) });
        if (data.keyword) config.keyword = data.keyword;
        if (["nextjs", "laravel"].includes(kind)) config.require_nonce = true;
      } else if (["tcp", "tls"].includes(kind)) {
        config.address = target;
      } else if (kind === "dns") {
        config.name = target;
      } else if (kind === "squid") {
        Object.assign(config, { proxy_url: target, canary_url: data.secondary_target });
        if (data.username) config.username = data.username;
        if (data.secret_ref) secretConfig.password = { secretRef: data.secret_ref };
      } else if (["mysql", "postgresql"].includes(kind)) {
        if (target.includes("://") || target.includes("@tcp(") || target.includes("@unix(")) {
          config.dsn = target;
        } else {
          Object.assign(config, { host: target, port: Number(data.port || (kind === "mysql" ? 3306 : 5432)), user: data.username, database: data.database || undefined, tls_mode: "required" });
        }
        if (data.dsn_secret_ref) secretConfig.dsn = { secretRef: data.dsn_secret_ref };
        else if (data.secret_ref) secretConfig.password = { secretRef: data.secret_ref };
      } else if (kind === "redis") {
        if (data.dsn_secret_ref) secretConfig.url = { secretRef: data.dsn_secret_ref };
        else if (target.includes("://")) config.url = target;
        else config.address = target;
        Object.assign(config, { database: Number(data.database || 0), capability_write: data.capability_write === "1" });
        if (data.username) config.username = data.username;
        if (data.secret_ref) secretConfig.password = { secretRef: data.secret_ref };
      } else if (kind === "reverb") {
        Object.assign(config, { url: target, origin: data.origin || undefined, channel: data.channel || "status-probe.public", trigger_url: data.secondary_target, app_key: data.app_key || undefined, event: "status-probe.nonce" });
        if (data.trigger_secret_ref) secretConfig.trigger_secret = { secretRef: data.trigger_secret_ref };
        if (data.trigger_secret_next_ref) secretConfig.trigger_secret_next = { secretRef: data.trigger_secret_next_ref };
      } else if (["laravel_queue", "laravel_scheduler"].includes(kind)) {
        Object.assign(config, { integration_id: data.integration_id || undefined, application_id: data.application_id || undefined, target: data.monitor_target || (kind === "laravel_scheduler" ? "tick" : "default"), degraded_after_seconds: 150, down_after_seconds: 210 });
      }
      body = {
        name: data.name,
        type: kind,
        component_id: data.component_id || null,
        agent_id: data.agent_id || null,
        interval_seconds: Number(data.interval_seconds || 60),
        timeout_seconds: Math.max(1, Math.ceil(Number(data.timeout_ms || 5000) / 1000)),
        slow_threshold_ms: data.latency_warning_ms ? Number(data.latency_warning_ms) : null,
        config,
        secret_config: Object.keys(secretConfig).length ? secretConfig : null,
        enabled: true,
      };
    } else if (section === "components") {
      body = {
        component_group_id: Number(data.component_group_id),
        name: data.name,
        slug: data.slug,
        description: data.description || null,
        position: Number(data.sort_order || 0),
      };
    } else if (section === "incidents") {
      body = {
        status_page_id: Number(data.status_page_id),
        title: data.title,
        status: "investigating",
        impact: data.severity,
        is_public: true,
        started_at: new Date().toISOString(),
        component_ids: String(data.component_ids || "").split(",").map((value) => Number(value.trim())).filter(Boolean),
      };
    } else if (section === "maintenance") {
      body = {
        status_page_id: Number(data.status_page_id),
        name: data.title,
        message: data.message || null,
        status: "scheduled",
        starts_at: wallTimeToIso(String(data.starts_at)),
        ends_at: wallTimeToIso(String(data.ends_at)),
        exclude_from_uptime: true,
        component_ids: String(data.component_ids || "").split(",").map((value) => Number(value.trim())).filter(Boolean),
      };
    } else if (section === "notifications") {
      body = {
        status_page_id: Number(data.status_page_id),
        name: data.name,
        type: data.type,
        config: data.type === "email"
          ? { to: String(data.recipients || "").split(",").map((value) => value.trim()).filter(Boolean) }
          : { url: data.url || null },
        enabled: true,
      };
    } else if (section === "policies") {
      body = {
        status_page_id: Number(data.status_page_id),
        notification_channel_id: Number(data.notification_channel_id),
        name: data.name,
        events: String(data.events || "").split(",").map((value) => value.trim()).filter(Boolean),
        component_ids: String(data.component_ids || "").split(",").map((value) => Number(value.trim())).filter(Boolean),
        repeat_minutes: Number(data.repeat_minutes || 60),
        quiet_hours: data.quiet_start && data.quiet_end ? { start: data.quiet_start, end: data.quiet_end, timezone: data.timezone || "Asia/Shanghai" } : null,
        enabled: true,
      };
    }
    try {
      let payload = await apiFetch(path, { method: "POST", body: JSON.stringify(body) });
      let raw = (payload.data ?? payload) as Record<string, unknown>;
      if (section === "monitors" && data.kind === "heartbeat" && raw.id) {
        const heartbeat = await apiFetch(`monitors/${raw.id}/rotate-heartbeat-secret`, { method: "POST" });
        payload = {
          ...heartbeat,
          environment: {
            STATUS_HEARTBEAT_URL: heartbeat.heartbeat_url,
            STATUS_HEARTBEAT_SECRET: heartbeat.heartbeat_secret,
          },
        };
        raw = payload;
      }
      if (section === "incidents" && raw.id && data.message) {
        await apiFetch("incident-updates", {
          method: "POST",
          body: JSON.stringify({ incident_id: raw.id, status: "investigating", message: data.message }),
        });
      }
      const issuedSecret = payload.secret_current ?? payload.heartbeat_secret ?? payload.webhook_secret ?? payload.secret ?? payload.token ?? payload.enrollment_token ?? raw.secret ?? raw.token ?? raw.enrollment_token ?? raw.secret_current;
      if (issuedSecret) {
        const environmentSource = payload.environment ?? raw.environment;
        const environment = environmentSource && typeof environmentSource === "object"
          ? Object.entries(environmentSource as Record<string, unknown>).map(([key, value]) => `${key}=${String(value)}`).join("\n")
          : "";
        const shown = environment || String(issuedSecret);
        setSecret(shown);
        onSecret(shown);
        setSaving(false);
      } else {
        onCreated(String(payload.message ?? "已创建"));
      }
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "保存失败");
      setSaving(false);
    }
  }

  if (secret) {
    return (
      <div>
        <p>该凭据只显示一次，请立即保存到目标服务或 Agent 的安全环境变量中。</p>
        <div className="field"><label>一次性凭据 / 环境变量</label><textarea readOnly value={secret} /></div>
        <div className="form-actions">
          <button className="secondary-button" type="button" onClick={() => void navigator.clipboard.writeText(secret)}>复制</button>
          <button className="primary-button" type="button" onClick={() => onCreated("凭据已创建并显示一次")}>完成</button>
        </div>
      </div>
    );
  }

  return (
    <form onSubmit={submit}>
      <div className="form-grid">
        {section === "monitors" ? <>
          <Field label="监控名称" name="name" required />
          <Field label="类型" name="kind" required>
            <select id="field-kind" name="kind" value={monitorKind} onChange={(event) => setMonitorKind(event.target.value)}>
              <option value="http">通用 HTTP(S)</option><option value="nextjs">Next.js readiness</option><option value="laravel">Laravel readiness</option><option value="squid">Squid</option>
              <option value="mysql">MySQL</option><option value="postgresql">PostgreSQL</option>
              <option value="redis">Redis</option><option value="reverb">Laravel Reverb</option>
              <option value="laravel_queue">Laravel Queue</option><option value="laravel_scheduler">Laravel Scheduler</option>
              <option value="tcp">TCP</option><option value="dns">DNS</option><option value="tls">TLS</option><option value="heartbeat">Push Heartbeat</option>
            </select>
          </Field>
          <Field label="主机、URL 或 DSN" name="target" required={!['heartbeat', 'laravel_queue', 'laravel_scheduler'].includes(monitorKind)} help="Next.js/Laravel 会强制 nonce freshness；敏感 DSN 请改用 Agent 本地 secretRef。" />
          <Field label="辅助 URL" name="secondary_target" help="Squid canary URL 或 Reverb trigger URL。" />
          <Field label="用户名" name="username" />
          <Field label="端口" name="port" type="number" />
          <Field label="数据库 / Redis DB" name="database" />
          <Field label="Password secretRef" name="secret_ref" help="例如 env://STATUS_DB_PASSWORD；密钥只在 Agent 本地解析。" />
          <Field label="完整 DSN secretRef" name="dsn_secret_ref" help="设置后优先于主机/用户名/密码字段。" />
          <Field label="HTTP 期望状态码" name="expected_status" type="number" defaultValue={200} />
          <Field label="响应关键字" name="keyword" />
          <Field label="Reverb Channel" name="channel" defaultValue="status-probe.public" />
          <Field label="Reverb App Key" name="app_key" />
          <Field label="Reverb Origin" name="origin" help="例如 https://status.example.com；必须匹配 Reverb allowed_origins。" />
          <Field label="Reverb trigger secretRef" name="trigger_secret_ref" help="对应 Laravel 项目的 STATUS_PROBE_SECRET_CURRENT。" />
          <Field label="Reverb next secretRef" name="trigger_secret_next_ref" help="密钥轮换期间使用。" />
          <Field label="Laravel Integration ID" name="integration_id" />
          <Field label="Laravel Application ID" name="application_id" />
          <Field label="Queue / Scheduler Target" name="monitor_target" />
          <Field label="Redis 读写 canary" name="capability_write"><select id="field-capability_write" name="capability_write" defaultValue="0"><option value="0">仅 PING</option><option value="1">SET/GET/DEL</option></select></Field>
          <Field label="检查频率（秒）" name="interval_seconds" type="number" defaultValue={60} />
          <Field label="总超时（毫秒）" name="timeout_ms">
            <input id="field-timeout_ms" key={monitorKind} name="timeout_ms" type="number" min={1000} defaultValue={monitorKind === "reverb" ? 10000 : 5000} />
          </Field>
          <Field label="组件 ID" name="component_id" required />
          <Field label="Agent ID" name="agent_id" />
          <Field label="慢响应阈值（毫秒）" name="latency_warning_ms" type="number" />
        </> : null}

        {section === "components" ? <>
          <Field label="组件名称" name="name" required />
          <Field label="组件组 ID" name="component_group_id" required />
          <Field label="Slug" name="slug" required help="公开 URL 与 API 使用的稳定标识。" />
          <Field label="说明" name="description" />
          <Field label="排序" name="sort_order" type="number" defaultValue={0} />
        </> : null}

        {section === "agents" ? <>
          <Field label="Agent 名称" name="name" required />
          <Field label="标签" name="labels" help="例如 region=cn-east,network=private" />
        </> : null}

        {section === "integrations" ? <>
          <Field label="集成名称" name="name" required />
          <Field label="状态页 ID" name="status_page_id" required />
          <Field label="Application ID" name="application_id" required help="需与 Laravel 项目的 STATUS_PROBE_APP_ID 完全一致。" />
        </> : null}

        {section === "incidents" ? <>
          <Field label="事件标题" name="title" required />
          <Field label="状态页 ID" name="status_page_id" required />
          <Field label="严重度" name="severity" required>
            <select id="field-severity" name="severity" defaultValue="degraded_performance"><option value="degraded_performance">性能下降</option><option value="partial_outage">部分中断</option><option value="major_outage">严重中断</option></select>
          </Field>
          <Field label="受影响组件 ID" name="component_ids" help="多个 ID 用逗号分隔。" />
          <Field label="公开说明" name="message" required />
        </> : null}

        {section === "maintenance" ? <>
          <Field label="维护标题" name="title" required />
          <Field label="状态页 ID" name="status_page_id" required />
          <Field label="开始时间" name="starts_at" type="datetime-local" required help="按状态页时区 Asia/Shanghai 解释。" />
          <Field label="结束时间" name="ends_at" type="datetime-local" required help="按状态页时区 Asia/Shanghai 解释。" />
          <Field label="公开说明" name="message" />
          <Field label="受影响组件 ID" name="component_ids" />
        </> : null}

        {section === "notifications" ? <>
          <Field label="渠道名称" name="name" required />
          <Field label="状态页 ID" name="status_page_id" required />
          <Field label="类型" name="type" required><select id="field-type" name="type" defaultValue="webhook"><option value="webhook">签名 Webhook</option><option value="email">SMTP Email</option></select></Field>
          <Field label="Webhook URL" name="url" type="url" />
          <Field label="Email 收件人" name="recipients" help="多个地址用逗号分隔；SMTP 发件地址从服务端环境变量读取。" />
        </> : null}

        {section === "policies" ? <>
          <Field label="策略名称" name="name" required />
          <Field label="状态页 ID" name="status_page_id" required />
          <Field label="通知渠道 ID" name="notification_channel_id" required />
          <Field label="事件类型" name="events" help="逗号分隔；留空表示 incident/maintenance 生命周期。" />
          <Field label="组件 ID" name="component_ids" help="逗号分隔；留空表示全部组件。" />
          <Field label="重复提醒（分钟）" name="repeat_minutes" type="number" defaultValue={60} />
          <Field label="静默开始" name="quiet_start" type="time" />
          <Field label="静默结束" name="quiet_end" type="time" />
          <Field label="静默时区" name="timezone" defaultValue="Asia/Shanghai" />
        </> : null}

        {section === "users" ? <>
          <Field label="姓名" name="name" required />
          <Field label="Email" name="email" type="email" required />
          <Field label="角色" name="role" required><select id="field-role" name="role" defaultValue="viewer"><option value="owner">所有者（Owner）</option><option value="admin">管理员（Admin）</option><option value="viewer">只读用户（Viewer）</option></select></Field>
          <Field label="临时密码" name="password" type="password" required />
        </> : null}
      </div>
      <div className="form-actions">
        <button className="primary-button" type="submit" disabled={saving}>{saving ? "保存中…" : "保存"}</button>
        <button className="secondary-button" type="button" onClick={onClose}>取消</button>
        {error ? <span className="form-message error">{error}</span> : null}
      </div>
    </form>
  );
}

function ComponentEditForm({ row, onSaved, onClose }: { row: Resource; onSaved: (message: string) => void; onClose: () => void }) {
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (row.id == null) return;
    setSaving(true);
    setError("");
    const data = Object.fromEntries(new FormData(event.currentTarget).entries());
    try {
      await apiFetch(`components/${row.id}`, {
        method: "PATCH",
        body: JSON.stringify({ name: data.name, description: data.description ? String(data.description) : null }),
      });
      onSaved("组件已更新");
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "保存失败");
      setSaving(false);
    }
  }

  return (
    <form onSubmit={submit}>
      <div className="form-grid">
        <Field label="组件名称" name="name" required defaultValue={String(row.name ?? "")} />
        <Field label="说明" name="description" defaultValue={String(row.description ?? "")} />
      </div>
      <div className="form-actions">
        <button className="primary-button" type="submit" disabled={saving}>{saving ? "保存中…" : "保存"}</button>
        <button className="secondary-button" type="button" onClick={onClose}>取消</button>
        {error ? <span className="form-message error">{error}</span> : null}
      </div>
    </form>
  );
}

export function AdminConsole() {
  const [section, setSection] = useState<Section>("overview");
  const [page, setPage] = useState(1);
  const [pageMeta, setPageMeta] = useState<PageMeta>({ current: 1, last: 1, total: 0 });
  const [rows, setRows] = useState<Resource[]>([]);
  const [overview, setOverview] = useState<Record<string, unknown>>({});
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [drawer, setDrawer] = useState(false);
  const [notice, setNotice] = useState("");
  const [role, setRole] = useState("");
  const [holdingSecret, setHoldingSecret] = useState(false);
  const [componentEdit, setComponentEdit] = useState<Resource | null>(null);
  const [pendingId, setPendingId] = useState("");
  const requestSeq = useRef(0);
  const loadedKey = useRef("");

  useEffect(() => {
    let cancelled = false;
    void apiFetch("me").then((payload) => {
      const user = payload.user;
      const roleName = user && typeof user === "object" ? String((user as Resource).role ?? "") : "";
      if (!cancelled) setRole(roleName);
    }).catch(() => {
      if (!cancelled) setRole("");
    });
    return () => {
      cancelled = true;
    };
  }, []);

  const load = useCallback(async () => {
    const seq = ++requestSeq.current;
    const key = `${section}:${page}`;
    const switched = loadedKey.current !== key;
    loadedKey.current = key;
    if (switched && section !== "overview") {
      setRows([]);
      setPageMeta({ current: page, last: 1, total: 0 });
    }
    setLoading(true);
    setError("");
    try {
      if (section === "overview") {
        const payload = await apiFetch("overview");
        if (seq !== requestSeq.current) return;
        setOverview((payload.data ?? payload) as Record<string, unknown>);
      } else {
        const payload = await apiFetch(`${endpoint[section]}?page=${page}`);
        if (seq !== requestSeq.current) return;
        const nextRows = rowsFrom(payload);
        setRows(nextRows);
        setPageMeta(pageMetaFrom(payload, nextRows.length));
      }
    } catch (reason) {
      if (seq !== requestSeq.current) return;
      setError(reason instanceof Error ? reason.message : "无法读取控制面数据");
    } finally {
      if (seq === requestSeq.current) setLoading(false);
    }
  }, [section, page]);

  useEffect(() => {
    const initial = window.setTimeout(() => void load(), 0);
    const timer = window.setInterval(() => void load(), 30_000);
    return () => {
      window.clearTimeout(initial);
      window.clearInterval(timer);
    };
  }, [load]);

  const metrics = useMemo(() => {
    const monitors = (overview.monitors ?? {}) as Record<string, unknown>;
    const agents = (overview.agents ?? {}) as Record<string, unknown>;
    const activeIncidents = Array.isArray(overview.active_incidents)
      ? overview.active_incidents.length
      : Number(overview.active_incidents ?? 0);

    return [
      { label: "运行监控", value: monitors.enabled ?? overview.active_monitors ?? overview.monitors_count ?? 0, foot: `共 ${monitors.total ?? overview.monitors_count ?? 0} 个` },
      { label: "可用率", value: overview.uptime_percent != null ? `${Number(overview.uptime_percent).toFixed(2)}%` : "—", foot: "最近 30 天" },
      { label: "活动事件", value: activeIncidents, foot: "需要关注" },
      { label: "在线 Agent", value: agents.online ?? overview.online_agents ?? 0, foot: `共 ${agents.total ?? overview.agents_count ?? 0} 个` },
    ];
  }, [overview]);

  const canWrite = role === "owner" || role === "admin";
  const canCreate = canWrite && !["overview", "audit"].includes(section) && (section !== "users" || role === "owner");
  const tableActions: TableActions = {
    canWrite,
    pendingId,
    onResolveIncident: (row) => {
      if (row.id == null) return;
      void mutate(`incidents/${row.id}`, { status: "resolved", resolved_at: new Date().toISOString() }, String(row.id), "事件已标记为恢复");
    },
    onToggleMonitor: (row) => {
      if (row.id == null) return;
      const enabled = row.enabled === false;
      void mutate(`monitors/${row.id}`, { enabled }, String(row.id), enabled ? "监控已启用" : "监控已停用");
    },
    onEditComponent: (row) => {
      setHoldingSecret(false);
      setComponentEdit(row);
      setDrawer(true);
    },
  };

  function changeSection(next: Section) {
    setSection(next);
    setPage(1);
    setDrawer(false);
    setHoldingSecret(false);
    setComponentEdit(null);
    setNotice("");
  }

  function closeDrawer() {
    if (holdingSecret) return;
    setDrawer(false);
    setComponentEdit(null);
  }

  async function logout() {
    try {
      await apiFetch("logout", { method: "POST" });
    } finally {
      window.location.assign("/admin/login");
    }
  }

  async function mutate(path: string, body: Record<string, unknown>, id: string, message: string) {
    setPendingId(id);
    setError("");
    try {
      await apiFetch(path, { method: "PATCH", body: JSON.stringify(body) });
      setNotice(message);
      await load();
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "保存失败");
    } finally {
      setPendingId("");
    }
  }

  return (
    <main className="admin-shell">
      <aside className="admin-sidebar">
        <Link className="brand" href="/admin">
          <span className="brand-mark" aria-hidden="true"><span /><span /><span /></span>
          <span>Server Status Page</span>
        </Link>
        <nav className="admin-nav" aria-label="管理后台导航">
          {sections.map((item) => (
            <a
              href={`#${item.id}`}
              className={item.id === section ? "active" : ""}
              onClick={(event) => { event.preventDefault(); changeSection(item.id); }}
              key={item.id}
              aria-current={item.id === section ? "page" : undefined}
            >
              <span className="admin-nav-icon" aria-hidden="true">{item.icon}</span>
              <span className="admin-nav-label">{item.label}</span>
            </a>
          ))}
        </nav>
        <div className="admin-sidebar-foot"><Link href="/">← 返回公开状态页</Link></div>
      </aside>

      <section className="admin-main">
        <header className="admin-topbar">
          <div><h1>{title[section]}</h1><p>真实探针、事件和通知的统一控制面</p></div>
          <div className="admin-actions">
            <ThemeToggle />
            <button className="secondary-button" type="button" onClick={() => void load()}>刷新</button>
            <button className="secondary-button" type="button" onClick={() => void logout()}>退出</button>
            {canCreate ? <button className="primary-button" type="button" onClick={() => { setHoldingSecret(false); setComponentEdit(null); setDrawer(true); }}>新建</button> : null}
          </div>
        </header>

        {notice ? <div className="maintenance-strip"><span className="maintenance-icon">✓</span><strong>{notice}</strong></div> : null}
        {error ? <div className="incident-card status-major_outage"><strong>控制面暂不可用</strong><p>{error}</p></div> : null}
        {sectionHelp[section] ? <div className="section-note">
          {sectionHelp[section]}
          {section === "integrations" ? <a href="https://github.com/dogeow/server-status-page/tree/main/packages/laravel-probe#readme" target="_blank" rel="noreferrer">查看 Laravel 应用接入文档 →</a> : null}
        </div> : null}

        {section === "overview" ? <>
          <div className="metrics-grid">
            {metrics.map((metric) => <article className="metric-card" key={metric.label}><span className="metric-label">{metric.label}</span><strong className="metric-value">{String(metric.value)}</strong><span className="metric-foot">{metric.foot}</span></article>)}
          </div>
          <div className="admin-grid">
            <article className="admin-card">
              <header className="admin-card-head"><h2>最近检查</h2><button className="secondary-button" type="button" onClick={() => changeSection("monitors")}>查看监控项</button></header>
              <div className="admin-card-body"><ResourceTable rows={Array.isArray(overview.recent_checks) ? overview.recent_checks as Resource[] : []} section="overview" actions={tableActions} /></div>
            </article>
            <article className="admin-card">
              <header className="admin-card-head"><h2>系统动态</h2></header>
              <div className="admin-card-body">
                {Array.isArray(overview.recent_events) && overview.recent_events.length ? <ul className="event-list">{(overview.recent_events as Resource[]).map((event, index) => <li key={String(event.id ?? index)}><i className={`mini-status status-${statusClass(eventStatus(event))}`} /><div><strong>{eventTypeLabel(event.event)}</strong><p>{eventDetail(event)}</p><time dateTime={String(event.created_at ?? "")}>{formatAdminDate(event.created_at)}</time></div></li>)}</ul> : <div className="admin-empty">暂无最新事件</div>}
              </div>
            </article>
          </div>
        </> : (
          <article className="admin-card">
            <header className="admin-card-head"><h2>{title[section]}列表</h2><span className="form-message">{loading && rows.length === 0 ? "读取中…" : `${pageMeta.total || rows.length} 条记录`}</span></header>
            <div className="admin-card-body">
              <ResourceTable rows={rows} section={section} actions={tableActions} loading={loading} />
              {page > 1 || pageMeta.last > 1 ? (
                <div className="admin-pager">
                  <button className="secondary-button row-action" type="button" disabled={page <= 1 || loading} onClick={() => setPage((current) => Math.max(1, current - 1))}>上一页</button>
                  <span>第 {pageMeta.current} / {pageMeta.last} 页</span>
                  <button className="secondary-button row-action" type="button" disabled={page >= pageMeta.last || loading} onClick={() => setPage((current) => current + 1)}>下一页</button>
                </div>
              ) : null}
            </div>
          </article>
        )}
      </section>

      {drawer ? (
        <div className="drawer-backdrop" role="presentation" onMouseDown={(event) => { if (holdingSecret) return; if (event.target === event.currentTarget) closeDrawer(); }}>
          <aside className="drawer" role="dialog" aria-modal="true" aria-label={componentEdit ? "编辑状态组件" : `新建${title[section]}`}>
            <header className="drawer-head"><div><p className="eyebrow">{componentEdit ? "Edit" : "Create"}</p><h2>{componentEdit ? "编辑状态组件" : `新建${title[section]}`}</h2></div><button className="icon-button" type="button" onClick={closeDrawer} aria-label="关闭" disabled={holdingSecret}>×</button></header>
            {componentEdit ? (
              <ComponentEditForm key={String(componentEdit.id)} row={componentEdit} onClose={closeDrawer} onSaved={(message) => { setDrawer(false); setComponentEdit(null); setNotice(message); void load(); }} />
            ) : (
              <CreateForm section={section} onSecret={() => setHoldingSecret(true)} onClose={closeDrawer} onCreated={(message) => { setDrawer(false); setHoldingSecret(false); setNotice(message || "已创建"); void load(); }} />
            )}
          </aside>
        </div>
      ) : null}
    </main>
  );
}
