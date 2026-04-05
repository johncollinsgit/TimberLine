export type MessagingChannel = "sms" | "email";

export interface MessagingBootstrap {
  authorized: boolean;
  tenant_id: number | null;
  status: string;
  module_access: boolean;
  module_state: Record<string, unknown> | null;
  data?: {
    groups?: MessagingGroupsPayload;
    templates?: EmailTemplateDefinition[];
  };
  endpoints?: MessagingEndpoints;
}

export interface MessagingEndpoints {
  bootstrap?: string;
  audience_summary?: string;
  search_products?: string;
  preview_group?: string;
  send_group?: string;
  smoke_sms?: string;
  smoke_email?: string;
  history?: string;
}

export interface MessagingGroupsPayload {
  saved: SavedAudienceGroup[];
  auto: AutoAudienceGroup[];
}

export interface SavedAudienceGroup {
  type: "saved";
  id: number;
  name: string;
  description?: string | null;
  channel?: string;
  members_count?: number;
}

export interface AutoAudienceGroup {
  type: "auto";
  key: string;
  name: string;
  description?: string | null;
  channels?: string[];
  counts?: {
    sms?: number;
    email?: number;
    overlap?: number;
    unique?: number;
  } | null;
}

export interface EmailTemplateDefinition {
  id: number;
  key: string;
  name: string;
  description?: string | null;
  default_subject?: string | null;
  default_sections?: EmailSection[];
  thumbnail_svg?: string | null;
}

export type EmailSectionType = "heading" | "text" | "button" | "product" | "image";

export interface EmailSection {
  id: string;
  type: EmailSectionType;
  text?: string;
  html?: string;
  label?: string;
  href?: string;
  align?: "left" | "center" | "right";
  productId?: string;
  title?: string;
  imageUrl?: string;
  price?: string;
  buttonLabel?: string;
  alt?: string;
  padding?: string;
}

export interface MessagingHistoryPayload {
  entries: Array<{
    channel: string;
    status: string;
    recipient: string;
    profile_name: string;
    message_preview: string;
    sent_at: string | null;
  }>;
  campaigns: Array<{
    id: number;
    name: string;
    status: string;
    channel: string;
    subject?: string | null;
    status_counts?: Record<string, number>;
    failure_codes?: Array<{ code: string; count: number }>;
    queued_at?: string | null;
    completed_at?: string | null;
    created_at?: string | null;
  }>;
}

export interface MessagingEnvelope<TData> {
  ok: boolean;
  message?: string;
  status?: string;
  data?: TData;
  errors?: Record<string, string[]>;
}
