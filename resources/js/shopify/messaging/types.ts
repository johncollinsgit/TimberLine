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
    audience_summary?: {
      summary?: Record<string, number>;
      group_summaries?: Record<string, Record<string, number>>;
      diagnostics?: Record<string, Record<string, number>>;
    };
  };
  endpoints?: MessagingEndpoints;
}

export interface MessagingEndpoints {
  bootstrap?: string;
  audience_summary?: string;
  search_customers?: string;
  search_products?: string;
  media_list?: string;
  media_upload?: string;
  groups?: string;
  group_detail_base?: string;
  create_group?: string;
  update_group_base?: string;
  preview_group?: string;
  send_individual?: string;
  send_group?: string;
  cancel_campaign_base?: string;
  smoke_sms?: string;
  smoke_email?: string;
  history?: string;
}

export interface MessagingMediaAsset {
  id: number;
  channel: "email" | "sms";
  url: string;
  original_name: string;
  alt_text?: string | null;
  mime_type: string;
  size_bytes: number;
  width?: number | null;
  height?: number | null;
  created_at?: string | null;
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

export type EmailSectionType =
  | "heading"
  | "text"
  | "button"
  | "product"
  | "product_grid_4"
  | "image"
  | "fading_divider";

export interface EmailProductTile {
  productId?: string;
  title?: string;
  imageUrl?: string;
  price?: string;
  href?: string;
  buttonLabel?: string;
}

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
  heading?: string;
  products?: EmailProductTile[];
  spacingTop?: number;
  spacingBottom?: number;
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
    target?: Record<string, unknown>;
    subject?: string | null;
    status_counts?: Record<string, number>;
    job_status_counts?: Record<string, number>;
    failure_codes?: Array<{ code: string; count: number }>;
    scheduled_for?: string | null;
    queued_at?: string | null;
    launched_at?: string | null;
    completed_at?: string | null;
    created_at?: string | null;
    cancelable?: boolean;
  }>;
}

export interface SmsPlanPayload {
  normalized_body?: string;
  normalization_applied?: boolean;
  normalization_replacements?: string[];
  encoding?: "gsm7" | "unicode";
  character_count?: number;
  sms_segments?: number;
  recommended_channel?: "sms" | "mms" | "mixed";
  sms_recipient_count?: number;
  mms_recipient_count?: number;
  recipient_count?: number;
  estimated_cost_per_recipient?: number;
  estimated_cost_per_recipient_formatted?: string;
  estimated_total_cost?: number;
  estimated_total_cost_formatted?: string;
  estimated_sms_cost_per_recipient_formatted?: string;
  estimated_mms_cost_per_recipient_formatted?: string;
  bulk_spend_limit_formatted?: string | null;
  blocked?: boolean;
  blocking_reasons?: string[];
  notes?: string[];
}

export interface MessagingEnvelope<TData> {
  ok: boolean;
  message?: string;
  status?: string;
  data?: TData;
  errors?: Record<string, string[]>;
}
