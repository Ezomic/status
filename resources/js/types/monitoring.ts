export type StatusState =
    'up' | 'maintenance' | 'degraded' | 'down' | 'unknown';

export type StripState = StatusState | 'none';

export type StripSlot = {
    date: string;
    state: StripState;
    uptime: number | null;
    maintenance: boolean;
};

export type ServiceSummary = {
    id: number;
    name: string;
    slug: string | null;
    url: string;
    host: string;
    state: StatusState;
    state_label: string;
    is_active: boolean;
    is_public: boolean;
    last_response_time_ms: number | null;
    last_checked_at: string | null;
};

export type ServiceRow = ServiceSummary & {
    strip: StripSlot[];
    sparkline: number[];
};

export type ServiceDetail = ServiceSummary & {
    expected_status_code: number;
    expected_body: string | null;
    interval_seconds: number;
    timeout_seconds: number;
    degraded_threshold_ms: number;
    strip: StripSlot[];
};

export type IncidentRow = {
    id: number;
    service?: string;
    service_id?: number;
    severity: StatusState;
    reason: string;
    started_at: string;
    resolved_at: string | null;
};

export type CheckRow = {
    id: number;
    state: StatusState;
    status_code: number | null;
    response_time_ms: number;
    checked_at: string;
};

export type ServiceForm = {
    name: string;
    url: string;
    expected_status_code: number;
    expected_body: string;
    interval_seconds: number;
    timeout_seconds: number;
    degraded_threshold_ms: number;
    is_active: boolean;
};
