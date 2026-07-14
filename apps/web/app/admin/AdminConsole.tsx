"use client";

import { FormEvent, useCallback, useEffect, useMemo, useState } from "react";
import Link from "next/link";

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
  { id: "components", label: "状态页", icon: "▤" },
  { id: "integrations", label: "Laravel 集成", icon: "⌘" },
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
  if (["up", "healthy", "operational", "active", "resolved"].includes(status)) return "operational";
  if (["degraded", "degraded_performance", "warning", "investigating"].includes(status)) return "degraded";
  if (["down", "major_outage", "failed"].includes(status)) return "major_outage";
  return "unknown";
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

function ResourceTable({ rows, section }: { rows: Resource[]; section: Section }) {
  if (!rows.length) return <div className="admin-empty">暂无记录。创建后会从真实控制面数据自动更新。</div>;
  if (section === "audit") return <AuditTable rows={rows} />;
  return (
    <div style={{ overflowX: "auto" }}>
      <table className="resource-table">
        <thead><tr><th>名称</th><th>状态</th><th>更新时间</th></tr></thead>
        <tbody>
          {rows.map((row, index) => (
            <tr key={String(row.id ?? index)}>
              <td>
                <span className="resource-name">{resourceLabel(row)}</span>
                {resourceDetail(row) ? <div className="resource-sub">{resourceDetail(row)}</div> : null}
              </td>
              <td>
                <span className={`mini-status status-${statusClass(row.status ?? row.state ?? (section === "agents" ? row.online : undefined))}`} />
                {String(row.status ?? row.state ?? (row.enabled === false ? "disabled" : "active"))}
              </td>
              <td><time dateTime={String(row.updated_at ?? row.last_seen_at ?? row.created_at ?? "")}>{formatAdminDate(row.updated_at ?? row.last_seen_at ?? row.created_at)}</time></td>
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

function CreateForm({ section, onCreated, onClose }: { section: Section; onCreated: (message?: string) => void; onClose: () => void }) {
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
        starts_at: new Date(String(data.starts_at)).toISOString(),
        ends_at: new Date(String(data.ends_at)).toISOString(),
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
        setSecret(environment || String(issuedSecret));
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
        <div className="form-actions"><button className="primary-button" type="button" onClick={() => onCreated("凭据已创建并显示一次")}>完成</button></div>
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
          <Field label="开始时间" name="starts_at" type="datetime-local" required />
          <Field label="结束时间" name="ends_at" type="datetime-local" required />
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
          <Field label="角色" name="role" required><select id="field-role" name="role" defaultValue="viewer"><option value="owner">Owner</option><option value="admin">Admin</option><option value="viewer">Viewer</option></select></Field>
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

export function AdminConsole() {
  const [section, setSection] = useState<Section>("overview");
  const [rows, setRows] = useState<Resource[]>([]);
  const [overview, setOverview] = useState<Record<string, unknown>>({});
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [drawer, setDrawer] = useState(false);
  const [notice, setNotice] = useState("");

  const load = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      if (section === "overview") {
        const payload = await apiFetch("overview");
        setOverview((payload.data ?? payload) as Record<string, unknown>);
      } else {
        const payload = await apiFetch(endpoint[section]);
        setRows(rowsFrom(payload));
      }
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "无法读取控制面数据");
    } finally {
      setLoading(false);
    }
  }, [section]);

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

  const canCreate = !["overview", "audit"].includes(section);

  function changeSection(next: Section) {
    setSection(next);
    setDrawer(false);
    setNotice("");
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
              <span aria-hidden="true">{item.icon}</span>{item.label}
            </a>
          ))}
        </nav>
        <div className="admin-sidebar-foot"><Link href="/">← 返回公开状态页</Link></div>
      </aside>

      <section className="admin-main">
        <header className="admin-topbar">
          <div><h1>{title[section]}</h1><p>真实探针、事件和通知的统一控制面</p></div>
          <div className="admin-actions">
            <button className="secondary-button" type="button" onClick={() => void load()}>刷新</button>
            {canCreate ? <button className="primary-button" type="button" onClick={() => setDrawer(true)}>新建</button> : null}
          </div>
        </header>

        {notice ? <div className="maintenance-strip"><span className="maintenance-icon">✓</span><strong>{notice}</strong></div> : null}
        {error ? <div className="incident-card status-major_outage"><strong>控制面暂不可用</strong><p>{error}</p></div> : null}

        {section === "overview" ? <>
          <div className="metrics-grid">
            {metrics.map((metric) => <article className="metric-card" key={metric.label}><span className="metric-label">{metric.label}</span><strong className="metric-value">{String(metric.value)}</strong><span className="metric-foot">{metric.foot}</span></article>)}
          </div>
          <div className="admin-grid">
            <article className="admin-card">
              <header className="admin-card-head"><h2>最近检查</h2><button className="secondary-button" type="button" onClick={() => changeSection("monitors")}>查看监控项</button></header>
              <div className="admin-card-body"><ResourceTable rows={Array.isArray(overview.recent_checks) ? overview.recent_checks as Resource[] : []} section="monitors" /></div>
            </article>
            <article className="admin-card">
              <header className="admin-card-head"><h2>系统动态</h2></header>
              <div className="admin-card-body">
                {Array.isArray(overview.recent_events) && overview.recent_events.length ? <ul className="event-list">{(overview.recent_events as Resource[]).map((event, index) => <li key={String(event.id ?? index)}><i /><div><strong>{resourceLabel(event)}</strong><p>{String(event.message ?? event.created_at ?? "已更新")}</p></div></li>)}</ul> : <div className="admin-empty">暂无最新事件</div>}
              </div>
            </article>
          </div>
        </> : (
          <article className="admin-card">
            <header className="admin-card-head"><h2>{title[section]}列表</h2><span className="form-message">{loading ? "读取中…" : `${rows.length} 条记录`}</span></header>
            <div className="admin-card-body"><ResourceTable rows={rows} section={section} /></div>
          </article>
        )}
      </section>

      {drawer ? (
        <div className="drawer-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) setDrawer(false); }}>
          <aside className="drawer" role="dialog" aria-modal="true" aria-label={`新建${title[section]}`}>
            <header className="drawer-head"><div><p className="eyebrow">Create</p><h2>新建{title[section]}</h2></div><button className="icon-button" type="button" onClick={() => setDrawer(false)} aria-label="关闭">×</button></header>
            <CreateForm section={section} onClose={() => setDrawer(false)} onCreated={(message) => { setDrawer(false); setNotice(message || "已创建"); void load(); }} />
          </aside>
        </div>
      ) : null}
    </main>
  );
}
