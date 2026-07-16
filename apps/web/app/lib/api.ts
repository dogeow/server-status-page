import type {
  DailyStatus,
  Incident,
  Maintenance,
  PublicStatusPayload,
  ServiceStatus,
  StatusComponent,
  StatusGroup,
} from "../types";

const API_BASE =
  process.env.API_INTERNAL_URL ??
  process.env.NEXT_PUBLIC_API_URL ??
  "http://localhost:8000";

const allowedStatuses = new Set<ServiceStatus>([
  "operational",
  "degraded",
  "partial_outage",
  "major_outage",
  "maintenance",
  "unknown",
]);

function normalizeStatus(value: unknown): ServiceStatus {
  const status = String(value ?? "unknown").toLowerCase();
  if (allowedStatuses.has(status as ServiceStatus)) return status as ServiceStatus;
  if (["up", "healthy", "ok"].includes(status)) return "operational";
  if (["down", "outage", "critical"].includes(status)) return "major_outage";
  if (["warning", "slow", "degraded_performance"].includes(status)) return "degraded";
  if (["partial", "partial-outage"].includes(status)) return "partial_outage";
  if (["under_maintenance", "maintenance_window"].includes(status)) return "maintenance";
  return "unknown";
}

function numberOrNull(value: unknown): number | null {
  const number = Number(value);
  return Number.isFinite(number) ? number : null;
}

function normalizeStatusPeriods(value: unknown): DailyStatus["statusPeriods"] {
  if (!Array.isArray(value)) return [];

  return value.map((item) => {
    const row = (item ?? {}) as Record<string, unknown>;
    const duration = Number(row.duration_seconds ?? row.durationSeconds ?? 0);

    return {
      status: normalizeStatus(row.status),
      startedAt: String(row.started_at ?? row.startedAt ?? ""),
      endedAt: row.ended_at || row.endedAt ? String(row.ended_at ?? row.endedAt) : null,
      durationSeconds: Number.isFinite(duration) ? Math.max(0, duration) : 0,
      ongoing: Boolean(row.ongoing),
      componentName: row.component_name || row.componentName
        ? String(row.component_name ?? row.componentName)
        : null,
      incidentId: row.incident_id || row.incidentId ? String(row.incident_id ?? row.incidentId) : null,
      incidentTitle: row.incident_title || row.incidentTitle ? String(row.incident_title ?? row.incidentTitle) : null,
      incidentMessage: row.incident_message || row.incidentMessage ? String(row.incident_message ?? row.incidentMessage) : null,
    };
  }).filter((period) => period.startedAt !== "");
}

function normalizeDaily(value: unknown): DailyStatus[] {
  if (!Array.isArray(value)) return [];
  return value.slice(-90).map((item, index) => {
    const row = (item ?? {}) as Record<string, unknown>;
    return {
      date: String(row.date ?? row.day ?? `day-${index}`),
      status: normalizeStatus(row.status),
      uptimePercent: numberOrNull(row.uptime_percent ?? row.uptimePercent),
      maintenance: Boolean(row.maintenance),
      statusPeriods: normalizeStatusPeriods(row.status_periods ?? row.statusPeriods),
    };
  });
}

function normalizeComponent(value: unknown, index: number): StatusComponent {
  const row = (value ?? {}) as Record<string, unknown>;
  return {
    id: String(row.id ?? `component-${index}`),
    name: String(row.name ?? `Component ${index + 1}`),
    description: row.description ? String(row.description) : null,
    status: normalizeStatus(row.status),
    uptimePercent: numberOrNull(row.uptime_percent ?? row.uptimePercent),
    latencyMs: numberOrNull(row.latency_ms ?? row.latencyMs),
    dailyHistory: normalizeDaily(row.daily_history ?? row.dailyHistory ?? row.history),
  };
}

function normalizeGroup(value: unknown, index: number): StatusGroup {
  const row = (value ?? {}) as Record<string, unknown>;
  const components = Array.isArray(row.components)
    ? row.components.map(normalizeComponent)
    : [];
  return {
    id: String(row.id ?? `group-${index}`),
    name: String(row.name ?? `Group ${index + 1}`),
    status: normalizeStatus(row.status),
    uptimePercent: numberOrNull(row.uptime_percent ?? row.uptimePercent),
    components,
    dailyHistory: normalizeDaily(row.daily_history ?? row.dailyHistory ?? row.history),
  };
}

function normalizeIncident(value: unknown, index: number): Incident {
  const row = (value ?? {}) as Record<string, unknown>;
  const rawUpdates = Array.isArray(row.updates) ? row.updates : [];
  const lastUpdate = rawUpdates.length
    ? (rawUpdates[rawUpdates.length - 1] as Record<string, unknown>)
    : null;
  return {
    id: String(row.id ?? `incident-${index}`),
    slug: String(row.slug ?? row.id ?? `incident-${index}`),
    title: String(row.title ?? "服务事件"),
    severity: normalizeStatus(row.severity ?? row.impact ?? row.status),
    phase: String(row.phase ?? row.state ?? row.status ?? "investigating"),
    message: row.message ? String(row.message) : lastUpdate?.message ? String(lastUpdate.message) : null,
    startedAt: String(row.started_at ?? row.startedAt ?? new Date().toISOString()),
    resolvedAt: row.resolved_at ? String(row.resolved_at) : null,
    componentNames: Array.isArray(row.component_names)
      ? row.component_names.map(String)
      : Array.isArray(row.components)
        ? row.components.map((component) =>
            typeof component === "string"
              ? component
              : String((component as Record<string, unknown>)?.name ?? "组件"),
          )
        : [],
    updates: rawUpdates.map((item, updateIndex) => {
      const update = (item ?? {}) as Record<string, unknown>;
      return {
        id: String(update.id ?? `${row.id}-update-${updateIndex}`),
        status: String(update.status ?? update.phase ?? "update"),
        message: String(update.message ?? "状态已更新"),
        createdAt: String(update.created_at ?? update.createdAt ?? row.started_at),
      };
    }),
  };
}

function normalizeMaintenance(value: unknown, index: number): Maintenance {
  const row = (value ?? {}) as Record<string, unknown>;
  return {
    id: String(row.id ?? `maintenance-${index}`),
    title: String(row.title ?? row.name ?? "计划维护"),
    startsAt: String(row.starts_at ?? row.startsAt ?? new Date().toISOString()),
    endsAt: String(row.ends_at ?? row.endsAt ?? new Date().toISOString()),
    componentNames: Array.isArray(row.component_names)
      ? row.component_names.map(String)
      : Array.isArray(row.components)
        ? row.components.map((component) => typeof component === "string" ? component : String((component as Record<string, unknown>)?.name ?? "组件"))
        : [],
  };
}

function unavailablePayload(): PublicStatusPayload {
  return {
    statusPage: {
      id: "unavailable",
      name: "System Status",
      description: "服务可用性与运行事件",
      timezone: "Asia/Shanghai",
    },
    overallStatus: "unknown",
    updatedAt: new Date().toISOString(),
    groups: [],
    incidents: [],
    maintenances: [],
    controlPlaneAvailable: false,
  };
}

export async function getPublicStatus(): Promise<PublicStatusPayload> {
  try {
    const response = await fetch(`${API_BASE}/api/public/v1/status`, {
      headers: { Accept: "application/json" },
      next: { revalidate: 15 },
      signal: AbortSignal.timeout(4500),
    });
    if (!response.ok) return unavailablePayload();
    const json = (await response.json()) as Record<string, unknown>;
    const raw = ((json.data ?? json) || {}) as Record<string, unknown>;
    const statusPage = ((raw.status_page ?? raw.statusPage) || {}) as Record<
      string,
      unknown
    >;
    return {
      statusPage: {
        id: String(statusPage.id ?? "default"),
        name: String(statusPage.name ?? "System Status"),
        description: statusPage.description ? String(statusPage.description) : null,
        timezone: String(statusPage.timezone ?? "Asia/Shanghai"),
      },
      overallStatus: normalizeStatus(raw.overall_status ?? raw.overallStatus),
      updatedAt: String(
        raw.updated_at ?? raw.updatedAt ?? raw.generated_at ?? raw.generatedAt ?? new Date().toISOString(),
      ),
      groups: Array.isArray(raw.groups) ? raw.groups.map(normalizeGroup) : [],
      incidents: Array.isArray(raw.incidents)
        ? raw.incidents.map(normalizeIncident)
        : [],
      maintenances: Array.isArray(raw.maintenances)
        ? raw.maintenances.map(normalizeMaintenance)
        : [],
      controlPlaneAvailable: true,
    };
  } catch {
    return unavailablePayload();
  }
}

export async function getIncident(slug: string): Promise<Incident | null> {
  try {
    const response = await fetch(`${API_BASE}/api/public/v1/incidents/${slug}`, {
      headers: { Accept: "application/json" },
      next: { revalidate: 15 },
      signal: AbortSignal.timeout(4500),
    });
    if (!response.ok) return null;
    const json = (await response.json()) as Record<string, unknown>;
    return normalizeIncident(json.data ?? json.incident ?? json, 0);
  } catch {
    return null;
  }
}
