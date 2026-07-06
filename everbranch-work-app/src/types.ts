export type User = {
  id: number;
  name: string;
  email: string;
  role: string;
};

export type Tenant = {
  id: number;
  name: string;
  slug: string;
  role: string;
};

export type TabKey = "home" | "jobs" | "team";

export type WorkBootstrap = {
  user: User;
  selected_tenant: Tenant | null;
  available_tenants: Tenant[];
  requires_tenant_selection: boolean;
  tabs: Array<{ key: TabKey; label: string; icon: string }>;
  labels: Record<string, string>;
  permissions: Record<string, boolean>;
};

export type AssignedUser = {
  id: number;
  name: string;
  email: string;
} | null;

export type WorkPhoto = {
  id: number;
  file_path: string;
  caption: string | null;
  captured_at: string | null;
  uploaded_by: AssignedUser;
};

export type WorkComment = {
  id: number;
  item_type: string;
  item_id: number;
  body: string;
  mentioned_user_ids: number[];
  created_at: string | null;
  user: AssignedUser;
};

export type WorkActivity = {
  id: number;
  item_type: string;
  item_id: number;
  event_type: string;
  title: string;
  body: string | null;
  metadata: Record<string, unknown>;
  created_at: string | null;
  actor: AssignedUser;
};

export type WorkTask = {
  id: number;
  job_id: number;
  job_title: string | null;
  title: string;
  status: string;
  due_at: string | null;
  sort_order?: number | null;
  assigned_user: AssignedUser;
};

export type WorkJob = {
  id: number;
  title: string;
  status: string;
  customer: {
    id: number | null;
    name: string | null;
    email: string | null;
    phone: string | null;
  };
  assigned_user: AssignedUser;
  scheduled_for: string | null;
  tasks: WorkTask[];
  service_address?: {
    line_1: string | null;
    line_2: string | null;
    city: string | null;
    state: string | null;
    postal_code: string | null;
    country: string | null;
  };
  description?: string | null;
  completed_at?: string | null;
  metadata?: Record<string, unknown> | null;
  photos?: WorkPhoto[];
  updated_at: string | null;
};

export type WorkNotification = {
  id: number;
  category: string;
  title: string;
  body: string | null;
  item_type: string | null;
  item_id: number | null;
  read_at: string | null;
};

export type TeamUser = User & {
  actions?: {
    notify?: {
      method: string;
      endpoint: string;
    };
  };
};

export type WorkHome = WorkBootstrap & {
  summary: {
    assigned_jobs: number;
    due_soon_tasks: number;
    blocked_tasks: number;
    unread_notifications: number;
  };
  assigned_jobs: WorkJob[];
  due_tasks: WorkTask[];
  blocked_tasks: WorkTask[];
  notifications: WorkNotification[];
  activity: WorkActivity[];
};
