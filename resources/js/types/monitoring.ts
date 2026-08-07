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
    is_stale: boolean;
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
    uses_tls: boolean;
    certificate_expires_at: string | null;
    certificate_checked_at: string | null;
    certificate_days_remaining: number | null;
    certificate_warn_within_days: number;
    strip: StripSlot[];
};

export type Freshness = {
    stalled: boolean;
    stale_count: number;
    last_check_at: string | null;
};

/** A Sanctum personal access token, as listed in Settings (STAT-14). */
export type ApiToken = {
    id: number;
    name: string;
    last_used_at: string | null;
    created_at: string | null;
};

/** Everything an unauthenticated visitor is allowed to see (STAT-5). */
export type PublicStatusRow = {
    slug: string | null;
    name: string;
    state: StatusState;
    stale: boolean;
    last_checked_at: string | null;
};

export type PublicVerdict = {
    tone: StatusState;
    headline: string;
};

export type DashboardCounts = {
    total: number;
    watched: number;
    paused: number;
    up: number;
    degraded: number;
    down: number;
    maintenance: number;
    unknown: number;
    stale: number;
};

export type DashboardVerdict = {
    tone: StatusState | 'stale';
    headline: string;
    detail: string;
};

export type AttentionRow = {
    id: number;
    name: string;
    host: string;
    state: StatusState;
    state_label: string;
    is_stale: boolean;
    last_response_time_ms: number | null;
    strip: StripSlot[];
    sparkline: number[];
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
