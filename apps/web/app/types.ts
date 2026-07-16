export type ServiceStatus =
  | "operational"
  | "degraded"
  | "partial_outage"
  | "major_outage"
  | "maintenance"
  | "unknown";

export type DailyStatusPeriod = {
  status: ServiceStatus;
  startedAt: string;
  endedAt: string | null;
  durationSeconds: number;
  ongoing: boolean;
  componentName?: string | null;
  incidentId?: string | null;
  incidentTitle?: string | null;
  incidentMessage?: string | null;
};

export type DailyStatus = {
  date: string;
  status: ServiceStatus;
  uptimePercent?: number | null;
  maintenance?: boolean;
  statusPeriods?: DailyStatusPeriod[];
};

export type StatusComponent = {
  id: string;
  name: string;
  description?: string | null;
  status: ServiceStatus;
  uptimePercent: number | null;
  latencyMs?: number | null;
  dailyHistory: DailyStatus[];
};

export type StatusGroup = {
  id: string;
  name: string;
  status: ServiceStatus;
  uptimePercent: number | null;
  components: StatusComponent[];
  dailyHistory: DailyStatus[];
};

export type IncidentUpdate = {
  id: string;
  status: string;
  message: string;
  createdAt: string;
};

export type Incident = {
  id: string;
  slug: string;
  title: string;
  severity: ServiceStatus;
  phase: "investigating" | "identified" | "monitoring" | "resolved" | string;
  message?: string | null;
  startedAt: string;
  resolvedAt?: string | null;
  componentNames: string[];
  updates: IncidentUpdate[];
};

export type Maintenance = {
  id: string;
  title: string;
  startsAt: string;
  endsAt: string;
  componentNames: string[];
};

export type PublicStatusPayload = {
  statusPage: {
    id: string;
    name: string;
    description?: string | null;
    timezone: string;
  };
  overallStatus: ServiceStatus;
  updatedAt: string;
  historyAvailableFrom: string | null;
  groups: StatusGroup[];
  incidents: Incident[];
  maintenances: Maintenance[];
  controlPlaneAvailable: boolean;
};
