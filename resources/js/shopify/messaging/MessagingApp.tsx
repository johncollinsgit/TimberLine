import {
  AppProvider,
  Banner,
  Badge,
  BlockStack,
  Box,
  Button,
  Card,
  Checkbox,
  Divider,
  InlineStack,
  Modal,
  ProgressBar,
  Spinner,
  Tabs,
  Text,
  TextField,
} from "@shopify/polaris";
import enTranslations from "@shopify/polaris/locales/en.json";
import { lazy, Suspense, useCallback, useEffect, useMemo, useRef, useState } from "react";
import { isAbortLikeError, MessagingApiError, requestMessagingFormData, requestMessagingJson } from "./api";
import { analyzeLocalSms, formatCurrency } from "./smsSafety";
import type {
  AutoAudienceGroup,
  EmailSection,
  EmailTemplateDefinition,
  MessagingBootstrap,
  MessagingChannel,
  MessagingGroupsPayload,
  MessagingHistoryPayload,
  MessagingMediaAsset,
  SmsPlanPayload,
  SavedAudienceGroup,
} from "./types";
import "./messaging.css";

const EmailContentStep = lazy(() =>
  import("./EmailContentStep").then((module) => ({ default: module.EmailContentStep })),
);

const SMS_STEPS = ["Recipients", "Message", "Smoke test", "Send"];
const EMAIL_STEPS = ["Recipients", "Template", "Content", "Smoke test", "Send"];

interface MessagingAppProps {
  bootstrap: MessagingBootstrap;
}

type WorkspaceTab = MessagingChannel | "completed";

interface SelectedTarget {
  targetType: "saved" | "auto";
  groupId?: number;
  groupKey?: string;
  name: string;
  sendableCount: number;
}

interface SmokeResult {
  summary?: Record<string, unknown>;
  sms_plan?: SmsPlanPayload;
  deliveries?: Array<{
    recipient?: string | null;
    status?: string;
    error_code?: string | null;
    error_message?: string | null;
    sent_at?: string | null;
    failed_at?: string | null;
  }>;
  integrity?: {
    valid?: boolean;
    link_count?: number;
    image_count?: number;
    invalid_urls?: string[];
  };
  invalid_inputs?: string[];
}

interface PreviewPayload {
  estimated_recipients?: number;
  resolved_sendable_count?: number;
  query_candidate_count?: number;
  force_send_profile_ids_count?: number;
  target?: Record<string, unknown>;
  sms_plan?: SmsPlanPayload;
}

function formatCount(value: number | undefined): string {
  return new Intl.NumberFormat("en-US").format(value ?? 0);
}

function formatCurrencyFromCents(value: number | undefined): string {
  return formatCurrency((Number(value ?? 0) || 0) / 100);
}

function parseDelimitedInput(value: string): string[] {
  return value
    .split(/[\n,]+/g)
    .map((entry) => entry.trim())
    .filter((entry) => entry.length > 0);
}

function isNonEmpty(value: string | null | undefined): boolean {
  return (value ?? "").trim().length > 0;
}

function composeBodyFromSections(sections: EmailSection[]): string {
  return sections
    .map((section) => {
      if (section.type === "heading") {
        return (section.text ?? "").trim();
      }
      if (section.type === "text") {
        return (section.html ?? "").replace(/<[^>]+>/g, " ").replace(/\s+/g, " ").trim();
      }
      if (section.type === "product") {
        return [section.title ?? "", section.price ?? ""].join(" ").trim();
      }
      if (section.type === "button") {
        return [section.label ?? "", section.href ?? ""].join(" ").trim();
      }
      return "";
    })
    .map((line) => line.trim())
    .filter((line) => line.length > 0)
    .join("\n\n")
    .trim();
}

function templateFallbackThumbnail(template: EmailTemplateDefinition): string {
  const byKey: Record<string, string> = {
    announcement: "Linear heading with one CTA",
    product_spotlight: "Hero product with details",
    event_update: "Event details and action",
    photo_cta: "Image-first action block",
    minimal_plain: "Simple plain message",
  };

  return byKey[template.key] ?? "Reusable email structure";
}

function badgeToneForStatus(status: string):
  | "critical"
  | "info"
  | "success"
  | "warning"
  | "attention"
  | undefined {
  const normalized = status.trim().toLowerCase();

  if (["completed", "sent", "delivered"].includes(normalized)) {
    return "success";
  }
  if (["sending", "queued", "scheduled", "test_sent"].includes(normalized)) {
    return "attention";
  }
  if (["canceled"].includes(normalized)) {
    return "warning";
  }
  if (["partially_failed", "undelivered"].includes(normalized)) {
    return "warning";
  }
  if (["failed"].includes(normalized)) {
    return "critical";
  }

  return "info";
}

function formatCampaignTime(value: string | null | undefined): string | null {
  if (!value) {
    return null;
  }

  const parsed = new Date(value);
  if (Number.isNaN(parsed.getTime())) {
    return null;
  }

  return new Intl.DateTimeFormat("en-US", {
    month: "short",
    day: "numeric",
    hour: "numeric",
    minute: "2-digit",
  }).format(parsed);
}

function campaignHeaderText(campaign: MessagingHistoryPayload["campaigns"][number]): string {
  const targetName = typeof campaign.target?.name === "string" && campaign.target.name.trim() !== ""
    ? campaign.target.name.trim()
    : "Audience";
  const timestamp = formatCampaignTime(campaign.created_at ?? campaign.queued_at ?? campaign.launched_at ?? null);

  return timestamp
    ? `${campaign.channel.toUpperCase()} · ${targetName} · ${timestamp}`
    : `${campaign.channel.toUpperCase()} · ${targetName}`;
}

function hasActiveCampaignWork(campaign: MessagingHistoryPayload["campaigns"][number]): boolean {
  const status = (campaign.status ?? "").trim().toLowerCase();
  if (status === "sending" || status === "preparing") {
    return true;
  }

  const pendingRecipients = Number(campaign.status_counts?.pending ?? 0)
    + Number(campaign.status_counts?.queued_for_approval ?? 0)
    + Number(campaign.status_counts?.approved ?? 0)
    + Number(campaign.status_counts?.scheduled ?? 0)
    + Number(campaign.status_counts?.sending ?? 0);
  const pendingJobs = Number(campaign.job_status_counts?.queued ?? 0)
    + Number(campaign.job_status_counts?.retryable ?? 0)
    + Number(campaign.job_status_counts?.dispatching ?? 0)
    + Number(campaign.job_status_counts?.sending ?? 0);

  return pendingRecipients > 0 || pendingJobs > 0;
}

function normalizeGroups(payload: MessagingBootstrap): MessagingGroupsPayload {
  const groups = payload.data?.groups;

  return {
    saved: Array.isArray(groups?.saved) ? groups.saved : [],
    auto: Array.isArray(groups?.auto) ? groups.auto : [],
  };
}

function normalizeTemplates(payload: MessagingBootstrap): EmailTemplateDefinition[] {
  const templates = payload.data?.templates;

  return Array.isArray(templates) ? templates.slice(0, 5) : [];
}

function normalizeBootstrapAudienceSummary(payload: MessagingBootstrap): {
  groupSummaries: Record<string, Record<string, number>>;
  diagnostics: Record<string, Record<string, number>>;
} {
  const audienceSummary = payload.data?.audience_summary;

  return {
    groupSummaries: audienceSummary?.group_summaries ?? {},
    diagnostics: audienceSummary?.diagnostics ?? {},
  };
}

export function MessagingApp({ bootstrap }: MessagingAppProps) {
  const [globalTone, setGlobalTone] = useState<"critical" | "success" | "info" | "warning">("info");
  const [globalMessage, setGlobalMessage] = useState<string | null>(null);

  const [groups] = useState<MessagingGroupsPayload>(() => normalizeGroups(bootstrap));
  const [templates] = useState<EmailTemplateDefinition[]>(() => normalizeTemplates(bootstrap));
  const [groupSummaries, setGroupSummaries] = useState<Record<string, Record<string, number>>>(
    () => normalizeBootstrapAudienceSummary(bootstrap).groupSummaries,
  );
  const [audienceDiagnostics, setAudienceDiagnostics] = useState<Record<string, Record<string, number>>>(
    () => normalizeBootstrapAudienceSummary(bootstrap).diagnostics,
  );

  const [workspaceTab, setWorkspaceTab] = useState<WorkspaceTab>("sms");
  const [activeChannel, setActiveChannel] = useState<MessagingChannel>("sms");
  const [smsStep, setSmsStep] = useState(0);
  const [emailStep, setEmailStep] = useState(0);

  const [selectedTargets, setSelectedTargets] = useState<{
    sms: SelectedTarget | null;
    email: SelectedTarget | null;
  }>({
    sms: null,
    email: null,
  });

  const [detailsTarget, setDetailsTarget] = useState<SavedAudienceGroup | AutoAudienceGroup | null>(null);

  const [smsMessage, setSmsMessage] = useState("");
  const [smsLinkInput, setSmsLinkInput] = useState("");
  const [smsShortenLinks, setSmsShortenLinks] = useState(false);
  const [smsShowAdvanced, setSmsShowAdvanced] = useState(false);
  const [smsSenderKey, setSmsSenderKey] = useState("");
  const [smsSmokeNumbers, setSmsSmokeNumbers] = useState("");
  const [smsSmokeSending, setSmsSmokeSending] = useState(false);
  const [smsSmokeResult, setSmsSmokeResult] = useState<SmokeResult | null>(null);
  const [smsPreviewLoading, setSmsPreviewLoading] = useState(false);
  const [smsPreview, setSmsPreview] = useState<PreviewPayload | null>(null);
  const [smsScheduleFor, setSmsScheduleFor] = useState("");
  const [smsSendLoading, setSmsSendLoading] = useState(false);

  const [emailTemplateKey, setEmailTemplateKey] = useState<string | null>(null);
  const [emailSubject, setEmailSubject] = useState("");
  const [emailMode, setEmailMode] = useState<"sections" | "legacy_html">("sections");
  const [emailSections, setEmailSections] = useState<EmailSection[]>([]);
  const [emailAdvancedHtml, setEmailAdvancedHtml] = useState("");
  const [emailSmokeRecipients, setEmailSmokeRecipients] = useState("");
  const [emailSmokeSending, setEmailSmokeSending] = useState(false);
  const [emailSmokeResult, setEmailSmokeResult] = useState<SmokeResult | null>(null);
  const [emailPreviewLoading, setEmailPreviewLoading] = useState(false);
  const [emailPreview, setEmailPreview] = useState<PreviewPayload | null>(null);
  const [emailScheduleFor, setEmailScheduleFor] = useState("");
  const [emailSendLoading, setEmailSendLoading] = useState(false);

  const [audienceSummaryLoading, setAudienceSummaryLoading] = useState(false);
  const [historyLoading, setHistoryLoading] = useState(false);
  const [historyLoaded, setHistoryLoaded] = useState(false);
  const [history, setHistory] = useState<MessagingHistoryPayload>({ entries: [], campaigns: [] });
  const [cancelingCampaignId, setCancelingCampaignId] = useState<number | null>(null);
  const historyFetchRef = useRef<Promise<void> | null>(null);

  const endpoints = bootstrap.endpoints ?? {};

  const activeSteps = activeChannel === "sms" ? SMS_STEPS : EMAIL_STEPS;
  const activeStepIndex = activeChannel === "sms" ? smsStep : emailStep;
  const activeSendStepIndex = activeChannel === "sms" ? 3 : 4;
  const historyVisible = workspaceTab === "completed" || activeStepIndex === activeSendStepIndex;

  const smsSafety = useMemo(() => analyzeLocalSms(smsMessage), [smsMessage]);
  const smsChars = smsSafety.characterCount;
  const smsSegments = smsSafety.smsSegments;

  const emailBody = useMemo(() => composeBodyFromSections(emailSections), [emailSections]);

  const setStep = (nextStep: number) => {
    const bounded = Math.max(0, Math.min(nextStep, activeSteps.length - 1));
    if (activeChannel === "sms") {
      setSmsStep(bounded);
      return;
    }
    setEmailStep(bounded);
  };

  const setBanner = (tone: "critical" | "success" | "info" | "warning", message: string | null) => {
    setGlobalTone(tone);
    setGlobalMessage(message);
  };

  const handleApiError = (error: unknown, fallback: string) => {
    if (isAbortLikeError(error)) {
      return;
    }

    if (error instanceof MessagingApiError) {
      const fieldErrors = error.payload?.errors ?? {};
      const firstField = Object.keys(fieldErrors)[0];
      const fieldMessage = firstField ? fieldErrors[firstField]?.[0] : null;
      setBanner("critical", fieldMessage ?? error.message);
      return;
    }

    setBanner("critical", error instanceof Error ? error.message : fallback);
  };

  const loadAudienceSummary = useCallback(async () => {
    if (Object.keys(groupSummaries).length > 0 || Object.keys(audienceDiagnostics).length > 0) {
      return;
    }

    if (!endpoints.audience_summary) {
      return;
    }

    setAudienceSummaryLoading(true);

    try {
      const response = await requestMessagingJson<{
        group_summaries?: Record<string, Record<string, number>>;
        diagnostics?: Record<string, Record<string, number>>;
      }>(endpoints.audience_summary, {
        auth: {
          timeoutMs: 1200,
          requestTimeoutMs: 1200,
          minTtlMs: 5000,
        },
      });

      const payload = response.data;
      setGroupSummaries(payload?.group_summaries ?? {});
      setAudienceDiagnostics(payload?.diagnostics ?? {});
    } catch (error) {
      if (!isAbortLikeError(error)) {
        console.warn("[messaging] audience summary load skipped", error);
      }
    } finally {
      setAudienceSummaryLoading(false);
    }
  }, [audienceDiagnostics, endpoints.audience_summary, groupSummaries]);

  const loadHistory = useCallback(async (force = false) => {
    if (!endpoints.history) {
      return;
    }

    if (historyLoaded && !force) {
      return;
    }

    if (historyFetchRef.current) {
      await historyFetchRef.current;
      return;
    }

    const fetchPromise = (async () => {
      setHistoryLoading(true);

      try {
        const response = await requestMessagingJson<MessagingHistoryPayload>(
          `${endpoints.history}?limit=16`,
        );
        setHistory(response.data ?? { entries: [], campaigns: [] });
        setHistoryLoaded(true);
      } catch (error) {
        handleApiError(error, "Failed to load campaign history.");
      } finally {
        setHistoryLoading(false);
      }
    })();

    historyFetchRef.current = fetchPromise;

    try {
      await fetchPromise;
    } finally {
      historyFetchRef.current = null;
    }
  }, [endpoints.history, historyLoaded]);

  const cancelCampaign = useCallback(async (campaignId: number) => {
    const endpoint = endpoints.cancel_campaign_base?.replace("__CAMPAIGN__", String(campaignId));
    if (!endpoint) {
      return;
    }

    setCancelingCampaignId(campaignId);

    try {
      await requestMessagingJson<{
        campaign?: { id?: number; status?: string };
      }>(endpoint, {
        method: "POST",
      });

      setBanner("success", `Campaign canceled. Remaining sends stopped (#${campaignId}).`);
      await loadHistory(true);
    } catch (error) {
      handleApiError(error, "Campaign cancel failed.");
    } finally {
      setCancelingCampaignId(null);
    }
  }, [endpoints.cancel_campaign_base, loadHistory]);

  useEffect(() => {
    if (templates.length === 0) {
      return;
    }

    if (!emailTemplateKey) {
      const first = templates[0];
      setEmailTemplateKey(first.key);
      if (first.default_subject) {
        setEmailSubject(first.default_subject);
      }
      if (Array.isArray(first.default_sections)) {
        setEmailSections(first.default_sections);
      }
    }
  }, [templates, emailTemplateKey]);

  useEffect(() => {
    if (historyVisible) {
      void loadHistory();
    }
  }, [historyVisible, loadHistory]);

  useEffect(() => {
    const campaigns = Array.isArray(history.campaigns) ? history.campaigns : [];
    if (!historyLoaded || !historyVisible || !campaigns.some(hasActiveCampaignWork)) {
      return;
    }

    const timer = window.setInterval(() => {
      void loadHistory(true);
    }, 4000);

    return () => {
      window.clearInterval(timer);
    };
  }, [history.campaigns, historyLoaded, historyVisible, loadHistory]);

  const groupCards = useMemo(() => {
    const saved = groups.saved.map((group) => {
      const count = Number(group.members_count ?? 0);

      return {
        id: `saved:${group.id}`,
        name: group.name,
        subtitle: `${formatCount(count)} sendable`,
        count,
        kind: "saved" as const,
        group,
      };
    });

    const auto = groups.auto.map((group) => {
      const summary = group.counts ?? groupSummaries[group.key] ?? {};
      const hasSummary = (group.counts !== null && group.counts !== undefined)
        || groupSummaries[group.key] !== undefined;
      const count = Number(summary[activeChannel] ?? 0);

      return {
        id: `auto:${group.key}`,
        name: group.name,
        subtitle: hasSummary
          ? `${formatCount(count)} sendable`
          : (audienceSummaryLoading ? "Loading sendable" : "Open details to load"),
        count,
        kind: "auto" as const,
        group,
      };
    });

    return [...saved, ...auto];
  }, [groups, groupSummaries, activeChannel, audienceSummaryLoading]);

  const selectGroup = (group: SavedAudienceGroup | AutoAudienceGroup) => {
    if (group.type === "saved") {
      setSelectedTargets((prev) => ({
        ...prev,
        [activeChannel]: {
          targetType: "saved",
          groupId: group.id,
          name: group.name,
          sendableCount: Number(group.members_count ?? 0),
        },
      }));
      return;
    }

    if (!group.counts && !groupSummaries[group.key]) {
      void loadAudienceSummary();
    }

    const summary = group.counts ?? groupSummaries[group.key] ?? {};
    setSelectedTargets((prev) => ({
      ...prev,
      [activeChannel]: {
        targetType: "auto",
        groupKey: group.key,
        name: group.name,
        sendableCount: Number(summary[activeChannel] ?? 0),
      },
    }));
  };

  const addSmsLink = () => {
    if (smsLinkInput.trim() === "") {
      return;
    }

    setSmsMessage((previous) => `${previous.trim()} ${smsLinkInput.trim()}`.trim());
    setSmsLinkInput("");
  };

  const searchProducts = async (query: string) => {
    if (!endpoints.search_products) {
      return [];
    }

    const response = await requestMessagingJson<Array<{
      id: string;
      gid: string;
      title: string;
      image_url?: string | null;
      price?: string | null;
      url?: string | null;
    }>>(`${endpoints.search_products}?q=${encodeURIComponent(query)}&limit=8`);

    return Array.isArray(response.data) ? response.data : [];
  };

  const listMediaAssets = useCallback(async () => {
    if (!endpoints.media_list) {
      return [];
    }

    const response = await requestMessagingJson<MessagingMediaAsset[]>(
      `${endpoints.media_list}?channel=email&limit=18`,
    );

    return Array.isArray(response.data) ? response.data : [];
  }, [endpoints.media_list]);

  const uploadMediaAsset = useCallback(async (file: File, altText?: string) => {
    if (!endpoints.media_upload) {
      throw new Error("Photo uploads are not available for this workspace yet.");
    }

    const form = new FormData();
    form.append("image", file);
    form.append("channel", "email");
    if ((altText ?? "").trim() !== "") {
      form.append("alt_text", altText?.trim() ?? "");
    }

    const response = await requestMessagingFormData<MessagingMediaAsset>(endpoints.media_upload, form, {
      method: "POST",
    });

    if (!response.data) {
      throw new Error("Photo upload completed without a usable asset.");
    }

    return response.data;
  }, [endpoints.media_upload]);

  const loadSmsPreview = useCallback(async () => {
    const selected = selectedTargets.sms;
    if (!endpoints.preview_group || !selected || !isNonEmpty(smsMessage)) {
      return;
    }

    setSmsPreviewLoading(true);

    try {
      const response = await requestMessagingJson<PreviewPayload>(endpoints.preview_group, {
        method: "POST",
        body: JSON.stringify({
          target_type: selected.targetType,
          group_id: selected.groupId,
          group_key: selected.groupKey,
          channel: "sms",
          body: smsMessage,
        }),
      });

      setSmsPreview(response.data ?? null);
    } catch (error) {
      handleApiError(error, "Failed to load SMS review.");
    } finally {
      setSmsPreviewLoading(false);
    }
  }, [endpoints.preview_group, selectedTargets.sms, smsMessage]);

  const loadEmailPreview = useCallback(async () => {
    const selected = selectedTargets.email;
    if (!endpoints.preview_group || !selected || !isNonEmpty(emailSubject)) {
      return;
    }

    setEmailPreviewLoading(true);

    try {
      const response = await requestMessagingJson<PreviewPayload>(endpoints.preview_group, {
        method: "POST",
        body: JSON.stringify({
          target_type: selected.targetType,
          group_id: selected.groupId,
          group_key: selected.groupKey,
          channel: "email",
          subject: emailSubject,
          body: emailBody,
          email_template_mode: emailMode,
          email_template_key: emailTemplateKey,
          email_sections: emailSections,
          email_advanced_html: emailAdvancedHtml,
        }),
      });

      setEmailPreview(response.data ?? null);
    } catch (error) {
      handleApiError(error, "Failed to load Email review.");
    } finally {
      setEmailPreviewLoading(false);
    }
  }, [
    endpoints.preview_group,
    selectedTargets.email,
    emailSubject,
    emailBody,
    emailMode,
    emailTemplateKey,
    emailSections,
    emailAdvancedHtml,
  ]);

  useEffect(() => {
    if (activeChannel === "sms" && smsStep === 3) {
      void loadSmsPreview();
    }
  }, [activeChannel, smsStep, loadSmsPreview]);

  useEffect(() => {
    if (activeChannel === "email" && emailStep === 4) {
      void loadEmailPreview();
    }
  }, [activeChannel, emailStep, loadEmailPreview]);

  const sendSmsSmokeTest = async () => {
    if (!endpoints.smoke_sms) {
      return;
    }

    const targets = parseDelimitedInput(smsSmokeNumbers);
    if (targets.length === 0) {
      setBanner("critical", "Add at least one test phone number.");
      return;
    }

    if (!isNonEmpty(smsMessage)) {
      setBanner("critical", "Add an SMS message before smoke test.");
      return;
    }

    setSmsSmokeSending(true);

    try {
      const response = await requestMessagingJson<SmokeResult>(endpoints.smoke_sms, {
        method: "POST",
        body: JSON.stringify({
          test_numbers: targets,
          message: smsMessage,
          sender_key: smsSenderKey || null,
        }),
      });

      setSmsSmokeResult(response.data ?? null);
      setBanner("success", "SMS smoke test sent.");
    } catch (error) {
      handleApiError(error, "SMS smoke test failed.");
    } finally {
      setSmsSmokeSending(false);
    }
  };

  const sendEmailSmokeTest = async () => {
    if (!endpoints.smoke_email) {
      return;
    }

    const targets = parseDelimitedInput(emailSmokeRecipients);
    if (targets.length === 0) {
      setBanner("critical", "Add at least one test email.");
      return;
    }

    if (!isNonEmpty(emailSubject)) {
      setBanner("critical", "Add an email subject before smoke test.");
      return;
    }

    setEmailSmokeSending(true);

    try {
      const response = await requestMessagingJson<SmokeResult>(endpoints.smoke_email, {
        method: "POST",
        body: JSON.stringify({
          test_emails: targets,
          subject: emailSubject,
          body: emailBody,
          email_template_mode: emailMode,
          email_template_key: emailTemplateKey,
          email_sections: emailSections,
          email_advanced_html: emailAdvancedHtml,
        }),
      });

      setEmailSmokeResult(response.data ?? null);
      setBanner("success", "Email smoke test sent.");
    } catch (error) {
      handleApiError(error, "Email smoke test failed.");
    } finally {
      setEmailSmokeSending(false);
    }
  };

  const sendSmsCampaign = async () => {
    if (!endpoints.send_group || !selectedTargets.sms) {
      return;
    }

    setSmsSendLoading(true);

    try {
      const response = await requestMessagingJson<{
        summary?: Record<string, unknown>;
        campaign?: { id?: number; status?: string };
      }>(endpoints.send_group, {
        method: "POST",
        body: JSON.stringify({
          target_type: selectedTargets.sms.targetType,
          group_id: selectedTargets.sms.groupId,
          group_key: selectedTargets.sms.groupKey,
          channel: "sms",
          body: smsMessage,
          sender_key: smsSenderKey || null,
          shorten_links: smsShortenLinks,
          schedule_for: smsScheduleFor || null,
        }),
      });

      const campaignId = Number(response.data?.campaign?.id ?? 0);
      setBanner(
        "success",
        campaignId > 0
          ? `SMS campaign queued (#${campaignId}).`
          : "SMS campaign queued.",
      );
      await loadHistory(true);
      await loadSmsPreview();
    } catch (error) {
      handleApiError(error, "SMS campaign send failed.");
    } finally {
      setSmsSendLoading(false);
    }
  };

  const sendEmailCampaign = async () => {
    if (!endpoints.send_group || !selectedTargets.email) {
      return;
    }

    setEmailSendLoading(true);

    try {
      const response = await requestMessagingJson<{
        summary?: Record<string, unknown>;
        campaign?: { id?: number; status?: string };
      }>(endpoints.send_group, {
        method: "POST",
        body: JSON.stringify({
          target_type: selectedTargets.email.targetType,
          group_id: selectedTargets.email.groupId,
          group_key: selectedTargets.email.groupKey,
          channel: "email",
          subject: emailSubject,
          body: emailBody,
          email_template_mode: emailMode,
          email_template_key: emailTemplateKey,
          email_sections: emailSections,
          email_advanced_html: emailAdvancedHtml,
          schedule_for: emailScheduleFor || null,
        }),
      });

      const campaignId = Number(response.data?.campaign?.id ?? 0);
      setBanner(
        "success",
        campaignId > 0
          ? `Email campaign queued (#${campaignId}).`
          : "Email campaign queued.",
      );
      await loadHistory(true);
      await loadEmailPreview();
    } catch (error) {
      handleApiError(error, "Email campaign send failed.");
    } finally {
      setEmailSendLoading(false);
    }
  };

  const selectTemplate = (template: EmailTemplateDefinition) => {
    setEmailTemplateKey(template.key);
    setEmailSubject(template.default_subject ?? "");
    setEmailSections(Array.isArray(template.default_sections) ? template.default_sections : []);
    setEmailMode("sections");
    setEmailAdvancedHtml("");
  };

  const canContinue = useMemo(() => {
    if (activeChannel === "sms") {
      if (smsStep === 0) {
        return selectedTargets.sms !== null;
      }
      if (smsStep === 1) {
        return isNonEmpty(smsMessage);
      }
      return smsStep < SMS_STEPS.length - 1;
    }

    if (emailStep === 0) {
      return selectedTargets.email !== null;
    }
    if (emailStep === 1) {
      return isNonEmpty(emailTemplateKey);
    }
    if (emailStep === 2) {
      return isNonEmpty(emailSubject) && (emailMode === "legacy_html"
        ? isNonEmpty(emailAdvancedHtml)
        : (emailSections.length > 0 || isNonEmpty(emailBody)));
    }

    return emailStep < EMAIL_STEPS.length - 1;
  }, [
    activeChannel,
    smsStep,
    emailStep,
    selectedTargets,
    smsMessage,
    emailTemplateKey,
    emailSubject,
    emailMode,
    emailAdvancedHtml,
    emailSections,
    emailBody,
  ]);

  const moveForward = () => {
    if (!canContinue) {
      setBanner("critical", "Finish this step before continuing.");
      return;
    }
    setStep(activeStepIndex + 1);
  };

  const moveBack = () => {
    setStep(activeStepIndex - 1);
  };

  const renderRecipientsStep = () => {
    const selected = selectedTargets[activeChannel];

    return (
      <Card>
        <BlockStack gap="300">
          <InlineStack align="space-between" blockAlign="center" wrap>
            <Text as="h3" variant="headingMd">
              Recipients
            </Text>
            {selected ? (
              <Badge tone="info">{selected.name}</Badge>
            ) : (
              <Text as="p" tone="subdued" variant="bodySm">
                Select one audience
              </Text>
            )}
          </InlineStack>

          <Box className="sf-messaging-group-grid">
            {groupCards.map((card) => {
              const isSelected = card.kind === "saved"
                ? selected?.targetType === "saved" && selected.groupId === (card.group as SavedAudienceGroup).id
                : selected?.targetType === "auto" && selected.groupKey === (card.group as AutoAudienceGroup).key;

              return (
                <button
                  key={card.id}
                  type="button"
                  className={`sf-messaging-group-card${isSelected ? " is-selected" : ""}`}
                  onClick={() => selectGroup(card.group)}
                >
                  <BlockStack gap="150">
                    <InlineStack align="space-between" blockAlign="start">
                      <Text as="p" variant="bodyMd" fontWeight="semibold">
                        {card.name}
                      </Text>
                      <Text as="p" tone="subdued" variant="bodySm">
                        {card.subtitle}
                      </Text>
                    </InlineStack>
                    <InlineStack align="space-between" blockAlign="center" wrap>
                      <Text as="p" tone="subdued" variant="bodySm">
                        {card.kind === "auto" ? "Auto audience" : "Saved group"}
                      </Text>
                      <Button
                        size="slim"
                        variant="plain"
                        onClick={(event) => {
                          event.stopPropagation();
                          if (card.group.type === "auto") {
                            void loadAudienceSummary();
                          }
                          setDetailsTarget(card.group);
                        }}
                      >
                        Details
                      </Button>
                    </InlineStack>
                  </BlockStack>
                </button>
              );
            })}
          </Box>
        </BlockStack>
      </Card>
    );
  };

  const renderSmsMessageStep = () => {
    return (
      <BlockStack gap="300">
        <Card>
          <BlockStack gap="300">
            <TextField
              label="Message"
              autoComplete="off"
              value={smsMessage}
              onChange={setSmsMessage}
              multiline={8}
              maxLength={5000}
            />
            <InlineStack gap="200" wrap>
              <TextField
                label="Add link"
                autoComplete="off"
                value={smsLinkInput}
                onChange={setSmsLinkInput}
                placeholder="https://"
              />
              <Button onClick={addSmsLink}>Insert link</Button>
            </InlineStack>
            <Checkbox
              label="Shorten links"
              checked={smsShortenLinks}
              onChange={setSmsShortenLinks}
            />
            <InlineStack gap="200" blockAlign="center" wrap>
              <Badge tone="info">{formatCount(smsChars)} chars</Badge>
              <Badge tone={smsSafety.encoding === "gsm7" ? "success" : "warning"}>
                {smsSafety.encoding === "gsm7" ? "GSM-7" : "Unicode"}
              </Badge>
              <Badge tone={smsSegments > 2 ? "warning" : "info"}>{smsSegments} SMS segments</Badge>
              <Badge tone="info">SMS {formatCurrency(smsSafety.smsCostPerRecipient)}/recipient</Badge>
              <Badge tone={smsSafety.mmsCouldBeCheaper ? "success" : "info"}>
                MMS {formatCurrency(smsSafety.mmsCostPerRecipient)}/recipient
              </Badge>
              <Button variant="plain" onClick={() => setSmsShowAdvanced((value) => !value)}>
                {smsShowAdvanced ? "Hide advanced" : "Advanced sender"}
              </Button>
            </InlineStack>
            {(smsSafety.normalizationApplied || smsSafety.encoding === "unicode" || smsSafety.mmsCouldBeCheaper) ? (
              <BlockStack gap="100">
                {smsSafety.normalizationApplied ? (
                  <Text as="p" tone="subdued" variant="bodySm">
                    Smart punctuation will be normalized before send.
                  </Text>
                ) : null}
                {smsSafety.encoding === "unicode" ? (
                  <Text as="p" tone="critical" variant="bodySm">
                    Unicode characters increase SMS segment cost: {smsSafety.unsupportedCharacters.join(" ")}
                  </Text>
                ) : null}
                {smsSafety.mmsCouldBeCheaper ? (
                  <Text as="p" tone="success" variant="bodySm">
                    MMS can be cheaper than segmented SMS for eligible US and Canada recipients.
                  </Text>
                ) : null}
              </BlockStack>
            ) : null}
            {smsShowAdvanced ? (
              <TextField
                label="Sender key"
                autoComplete="off"
                value={smsSenderKey}
                onChange={setSmsSenderKey}
                placeholder="default"
              />
            ) : null}
          </BlockStack>
        </Card>

        <Card>
          <BlockStack gap="200">
            <Text as="h3" variant="headingMd">
              Preview
            </Text>
            <Box className="sf-messaging-phone-shell">
              <Box className="sf-messaging-phone-bubble">
                {isNonEmpty(smsSafety.normalizedMessage) ? smsSafety.normalizedMessage : "Your SMS preview appears here."}
              </Box>
            </Box>
          </BlockStack>
        </Card>
      </BlockStack>
    );
  };

  const renderSmsSmokeStep = () => {
    return (
      <Card>
        <BlockStack gap="300">
          <Text as="h3" variant="headingMd">
            Smoke test
          </Text>
          <TextField
            label="Test numbers"
            value={smsSmokeNumbers}
            onChange={setSmsSmokeNumbers}
            multiline={4}
            autoComplete="off"
            placeholder="+15551234567, +15557654321"
          />
          <InlineStack gap="200">
            <Button variant="primary" tone="success" size="large" loading={smsSmokeSending} onClick={sendSmsSmokeTest}>
              Run smoke test
            </Button>
          </InlineStack>

          {smsSmokeResult?.sms_plan ? (
            <Card>
              <BlockStack gap="150">
                <Text as="p" variant="bodySm" fontWeight="semibold">
                  Estimated delivery
                </Text>
                <InlineStack gap="200" wrap>
                  <Badge tone="info">
                    {smsSmokeResult.sms_plan.recommended_channel === "mms" ? "MMS" : "SMS"}
                  </Badge>
                  <Badge tone="info">
                    {smsSmokeResult.sms_plan.estimated_cost_per_recipient_formatted ?? "n/a"}/recipient
                  </Badge>
                </InlineStack>
              </BlockStack>
            </Card>
          ) : null}

          {smsSmokeResult ? (
            <Box className="sf-messaging-smoke-results">
              {(smsSmokeResult.deliveries ?? []).map((delivery, index) => (
                <Card key={`${delivery.recipient ?? "recipient"}-${index}`}>
                  <InlineStack align="space-between" blockAlign="start" wrap>
                    <Text as="p" variant="bodySm" fontWeight="semibold">
                      {delivery.recipient ?? "Unknown recipient"}
                    </Text>
                    <Badge tone={badgeToneForStatus(delivery.status ?? "sent")}>{delivery.status ?? "sent"}</Badge>
                  </InlineStack>
                  {delivery.error_message ? (
                    <Text as="p" tone="critical" variant="bodySm">
                      {delivery.error_message}
                    </Text>
                  ) : null}
                </Card>
              ))}
            </Box>
          ) : null}
        </BlockStack>
      </Card>
    );
  };

  const renderSmsSendStep = () => {
    const selected = selectedTargets.sms;
    const smsPlan = smsPreview?.sms_plan ?? null;
    const smsBlocked = Boolean(smsPlan?.blocked);
    const exactSendable = Number(smsPreview?.resolved_sendable_count ?? selected?.sendableCount ?? 0);

    return (
      <BlockStack gap="300">
        <Card>
          <BlockStack gap="200">
            <Text as="h3" variant="headingMd">
              Final review
            </Text>
            <InlineStack gap="200" wrap>
              <Badge tone="info">Audience: {selected?.name ?? "None"}</Badge>
              <Badge tone="info">
                Sendable: {formatCount(exactSendable)}
              </Badge>
              {smsPlan ? (
                <Badge tone={smsPlan.recommended_channel === "mms" ? "success" : "info"}>
                  {smsPlan.recommended_channel === "mixed"
                    ? `Mixed delivery`
                    : `Send as ${String(smsPlan.recommended_channel ?? "sms").toUpperCase()}`}
                </Badge>
              ) : null}
            </InlineStack>
            {smsPlan ? (
              <Box className={`sf-messaging-cost-card${smsBlocked ? " is-blocked" : ""}`}>
                <BlockStack gap="150">
                  <InlineStack align="space-between" blockAlign="center" wrap>
                    <Text as="p" variant="bodyMd" fontWeight="semibold">
                      Estimated cost
                    </Text>
                    <Text as="p" variant="bodyMd" fontWeight="semibold">
                      {smsPlan.estimated_total_cost_formatted ?? "n/a"}
                    </Text>
                  </InlineStack>
                  <InlineStack gap="200" wrap>
                    <Text as="p" tone="subdued" variant="bodySm">
                      Avg: {smsPlan.estimated_cost_per_recipient_formatted ?? "n/a"}/recipient
                    </Text>
                    <Text as="p" tone="subdued" variant="bodySm">
                      SMS path: {smsPlan.estimated_sms_cost_per_recipient_formatted ?? "n/a"}
                    </Text>
                    <Text as="p" tone="subdued" variant="bodySm">
                      MMS path: {smsPlan.estimated_mms_cost_per_recipient_formatted ?? "n/a"}
                    </Text>
                    {smsPlan.bulk_spend_limit_formatted ? (
                      <Text as="p" tone="subdued" variant="bodySm">
                        Safety ceiling: {smsPlan.bulk_spend_limit_formatted}
                      </Text>
                    ) : null}
                  </InlineStack>
                  {smsPlan.recommended_channel === "mixed" ? (
                    <Text as="p" tone="subdued" variant="bodySm">
                      MMS recipients: {formatCount(Number(smsPlan.mms_recipient_count ?? 0))} · SMS recipients: {formatCount(Number(smsPlan.sms_recipient_count ?? 0))}
                    </Text>
                  ) : null}
                  {!smsBlocked && smsPlan.bulk_spend_limit_formatted ? (
                    <Text as="p" tone="subdued" variant="bodySm">
                      Sends above {smsPlan.bulk_spend_limit_formatted} are blocked automatically before dispatch.
                    </Text>
                  ) : null}
                  {(smsPlan.notes ?? []).map((note) => (
                    <Text key={note} as="p" tone="subdued" variant="bodySm">
                      {note}
                    </Text>
                  ))}
                  {smsBlocked ? (
                    <Box className="sf-messaging-cost-warning">
                      {(smsPlan.blocking_reasons ?? []).map((reason) => (
                        <Text key={reason} as="p" tone="critical" variant="bodySm">
                          {reason}
                        </Text>
                      ))}
                    </Box>
                  ) : null}
                </BlockStack>
              </Box>
            ) : (
              <Text as="p" tone="subdued" variant="bodySm">
                Refresh summary to calculate the exact audience cost and delivery mode.
              </Text>
            )}
            <TextField
              label="Schedule later (optional)"
              type="datetime-local"
              value={smsScheduleFor}
              onChange={setSmsScheduleFor}
              autoComplete="off"
            />
            <InlineStack gap="200" wrap>
              <Button loading={smsPreviewLoading} onClick={loadSmsPreview}>
                Refresh summary
              </Button>
              <Button variant="primary" loading={smsSendLoading} onClick={sendSmsCampaign} disabled={smsBlocked}>
                {smsPlan?.recommended_channel === "mms" ? "Send as MMS" : "Send now"}
              </Button>
            </InlineStack>
          </BlockStack>
        </Card>

        <CampaignHistoryCard
          history={history}
          loading={historyLoading}
          onCancel={cancelCampaign}
          cancelingCampaignId={cancelingCampaignId}
        />
      </BlockStack>
    );
  };

  const renderEmailTemplateStep = () => {
    return (
      <Card>
        <BlockStack gap="300">
          <Text as="h3" variant="headingMd">
            Template
          </Text>
          <Box className="sf-messaging-template-grid">
            {templates.map((template) => {
              const selected = emailTemplateKey === template.key;
              return (
                <button
                  key={template.key}
                  type="button"
                  className={`sf-messaging-template-card${selected ? " is-selected" : ""}`}
                  onClick={() => selectTemplate(template)}
                >
                  <Box className="sf-messaging-template-thumb">
                    {template.thumbnail_svg ? (
                      <span dangerouslySetInnerHTML={{ __html: template.thumbnail_svg }} />
                    ) : (
                      <Text as="p" variant="bodySm" tone="subdued">
                        {templateFallbackThumbnail(template)}
                      </Text>
                    )}
                  </Box>
                  <BlockStack gap="050">
                    <Text as="p" variant="bodyMd" fontWeight="semibold">
                      {template.name}
                    </Text>
                    <Text as="p" tone="subdued" variant="bodySm">
                      {template.description ?? ""}
                    </Text>
                  </BlockStack>
                </button>
              );
            })}
          </Box>
        </BlockStack>
      </Card>
    );
  };

  const renderEmailContentStep = () => {
    return (
      <Suspense
        fallback={
          <Card>
            <InlineStack blockAlign="center" gap="200">
              <Spinner accessibilityLabel="Loading email editor" size="small" />
              <Text as="p" tone="subdued">
                Loading email editor
              </Text>
            </InlineStack>
          </Card>
        }
      >
        <EmailContentStep
          subject={emailSubject}
          onSubjectChange={setEmailSubject}
          sections={emailSections}
          onSectionsChange={setEmailSections}
          mode={emailMode}
          onModeChange={setEmailMode}
          advancedHtml={emailAdvancedHtml}
          onAdvancedHtmlChange={setEmailAdvancedHtml}
          searchProducts={searchProducts}
          listMediaAssets={listMediaAssets}
          uploadMediaAsset={uploadMediaAsset}
        />
      </Suspense>
    );
  };

  const renderEmailSmokeStep = () => {
    return (
      <Card>
        <BlockStack gap="300">
          <Text as="h3" variant="headingMd">
            Smoke test
          </Text>
          <TextField
            label="Test emails"
            value={emailSmokeRecipients}
            onChange={setEmailSmokeRecipients}
            multiline={4}
            autoComplete="off"
            placeholder="owner@example.com, test@example.com"
          />
          <InlineStack gap="200">
            <Button variant="primary" tone="success" size="large" loading={emailSmokeSending} onClick={sendEmailSmokeTest}>
              Run smoke test
            </Button>
          </InlineStack>

          {emailSmokeResult?.integrity ? (
            <InlineStack gap="200" wrap>
              <Badge tone={emailSmokeResult.integrity.valid ? "success" : "critical"}>
                Integrity {emailSmokeResult.integrity.valid ? "passed" : "failed"}
              </Badge>
              <Badge tone="info">
                {emailSmokeResult.integrity.link_count ?? 0} links
              </Badge>
              <Badge tone="info">
                {emailSmokeResult.integrity.image_count ?? 0} images
              </Badge>
            </InlineStack>
          ) : null}

          {emailSmokeResult ? (
            <Box className="sf-messaging-smoke-results">
              {(emailSmokeResult.deliveries ?? []).map((delivery, index) => (
                <Card key={`${delivery.recipient ?? "recipient"}-${index}`}>
                  <InlineStack align="space-between" blockAlign="start" wrap>
                    <Text as="p" variant="bodySm" fontWeight="semibold">
                      {delivery.recipient ?? "Unknown recipient"}
                    </Text>
                    <Badge tone={badgeToneForStatus(delivery.status ?? "sent")}>{delivery.status ?? "sent"}</Badge>
                  </InlineStack>
                </Card>
              ))}
            </Box>
          ) : null}
        </BlockStack>
      </Card>
    );
  };

  const renderEmailSendStep = () => {
    const selected = selectedTargets.email;
    const templateName = templates.find((template) => template.key === emailTemplateKey)?.name ?? "Template";

    return (
      <BlockStack gap="300">
        <Card>
          <BlockStack gap="200">
            <Text as="h3" variant="headingMd">
              Final review
            </Text>
            <InlineStack gap="200" wrap>
              <Badge tone="info">Audience: {selected?.name ?? "None"}</Badge>
              <Badge tone="info">Template: {templateName}</Badge>
              <Badge tone="info">
                Sendable: {formatCount(Number(emailPreview?.resolved_sendable_count ?? selected?.sendableCount ?? 0))}
              </Badge>
            </InlineStack>
            <Text as="p" variant="bodySm" tone="subdued">
              Subject: {emailSubject || "Untitled"}
            </Text>
            <TextField
              label="Schedule later (optional)"
              type="datetime-local"
              value={emailScheduleFor}
              onChange={setEmailScheduleFor}
              autoComplete="off"
            />
            <InlineStack gap="200" wrap>
              <Button loading={emailPreviewLoading} onClick={loadEmailPreview}>
                Refresh summary
              </Button>
              <Button variant="primary" loading={emailSendLoading} onClick={sendEmailCampaign}>
                Send now
              </Button>
            </InlineStack>
          </BlockStack>
        </Card>

        <CampaignHistoryCard
          history={history}
          loading={historyLoading}
          onCancel={cancelCampaign}
          cancelingCampaignId={cancelingCampaignId}
        />
      </BlockStack>
    );
  };

  const renderActiveStep = () => {
    if (activeChannel === "sms") {
      if (smsStep === 0) {
        return renderRecipientsStep();
      }
      if (smsStep === 1) {
        return renderSmsMessageStep();
      }
      if (smsStep === 2) {
        return renderSmsSmokeStep();
      }
      return renderSmsSendStep();
    }

    if (emailStep === 0) {
      return renderRecipientsStep();
    }
    if (emailStep === 1) {
      return renderEmailTemplateStep();
    }
    if (emailStep === 2) {
      return renderEmailContentStep();
    }
    if (emailStep === 3) {
      return renderEmailSmokeStep();
    }
    return renderEmailSendStep();
  };

  return (
    <AppProvider i18n={enTranslations}>
      <BlockStack gap="400">
        {globalMessage ? (
          <Banner tone={globalTone} onDismiss={() => setGlobalMessage(null)}>
            {globalMessage}
          </Banner>
        ) : null}

        <Card>
          <BlockStack gap="300">
            <Tabs
              tabs={[
                { id: "sms", content: "SMS" },
                { id: "email", content: "Email" },
                { id: "completed", content: "Completed runs" },
              ]}
              selected={workspaceTab === "sms" ? 0 : workspaceTab === "email" ? 1 : 2}
              onSelect={(index) => {
                if (index === 0) {
                  setWorkspaceTab("sms");
                  setActiveChannel("sms");
                  return;
                }
                if (index === 1) {
                  setWorkspaceTab("email");
                  setActiveChannel("email");
                  return;
                }
                setWorkspaceTab("completed");
              }}
            />

            <Divider />

            {workspaceTab === "completed" ? (
              <CompletedRunsCard history={history} loading={historyLoading} />
            ) : (
              <>
                <InlineStack gap="200" wrap>
                  {activeSteps.map((step, index) => {
                    const selected = index === activeStepIndex;
                    return (
                      <Button
                        key={`${activeChannel}-${step}`}
                        onClick={() => setStep(index)}
                        pressed={selected}
                        size="slim"
                      >
                        {index + 1}. {step}
                      </Button>
                    );
                  })}
                </InlineStack>

                <Divider />

                {renderActiveStep()}

                <Divider />

                <InlineStack align="space-between" wrap>
                  <Button disabled={activeStepIndex === 0} onClick={moveBack}>
                    Back
                  </Button>
                  {activeStepIndex < activeSteps.length - 1 ? (
                    <Button variant="primary" onClick={moveForward} disabled={!canContinue}>
                      Continue
                    </Button>
                  ) : (
                    <Text as="p" tone="subdued" variant="bodySm">
                      Review complete. Send when ready.
                    </Text>
                  )}
                </InlineStack>
              </>
            )}
          </BlockStack>
        </Card>
      </BlockStack>

      <Modal
        open={detailsTarget !== null}
        onClose={() => setDetailsTarget(null)}
        title={detailsTarget?.name ?? "Audience details"}
        primaryAction={{
          content: "Close",
          onAction: () => setDetailsTarget(null),
        }}
      >
        <Modal.Section>
          {detailsTarget?.type === "auto" ? (
            <BlockStack gap="200">
              {(detailsTarget.counts ?? groupSummaries[detailsTarget.key]) ? (
                <InlineStack gap="200" wrap>
                  <Badge tone="info">SMS: {formatCount(Number((detailsTarget.counts ?? groupSummaries[detailsTarget.key] ?? {}).sms ?? 0))}</Badge>
                  <Badge tone="info">Email: {formatCount(Number((detailsTarget.counts ?? groupSummaries[detailsTarget.key] ?? {}).email ?? 0))}</Badge>
                  <Badge tone="info">Unique: {formatCount(Number((detailsTarget.counts ?? groupSummaries[detailsTarget.key] ?? {}).unique ?? 0))}</Badge>
                </InlineStack>
              ) : (
                <Text as="p" tone="subdued" variant="bodySm">
                  {audienceSummaryLoading
                    ? "Audience summary is loading."
                    : "Open this audience to load the latest sendable counts."}
                </Text>
              )}
              <Text as="p" tone="subdued" variant="bodySm">
                {detailsTarget.description ?? "Automatic audience details."}
              </Text>
              {audienceDiagnostics[detailsTarget.key] ? (
                <BlockStack gap="050">
                  <Text as="p" variant="bodySm">
                    Query candidates: {formatCount(Number(audienceDiagnostics[detailsTarget.key]?.query_candidate_count ?? 0))}
                  </Text>
                  <Text as="p" variant="bodySm">
                    Resolved sendable: {formatCount(Number(audienceDiagnostics[detailsTarget.key]?.resolved_sendable_count ?? 0))}
                  </Text>
                </BlockStack>
              ) : null}
            </BlockStack>
          ) : detailsTarget?.type === "saved" ? (
            <BlockStack gap="200">
              <Badge tone="info">Members: {formatCount(Number(detailsTarget.members_count ?? 0))}</Badge>
              <Text as="p" tone="subdued" variant="bodySm">
                {detailsTarget.description ?? "Saved audience group."}
              </Text>
            </BlockStack>
          ) : null}
        </Modal.Section>
      </Modal>
    </AppProvider>
  );
}

function CompletedRunsCard({
  history,
  loading,
}: {
  history: MessagingHistoryPayload;
  loading: boolean;
}) {
  const campaigns = Array.isArray(history.campaigns) ? history.campaigns : [];
  const completedCampaigns = campaigns.filter((campaign) => !hasActiveCampaignWork(campaign));

  return (
    <Card>
      <BlockStack gap="300">
        <InlineStack align="space-between" blockAlign="center" wrap>
          <Text as="h3" variant="headingMd">
            Completed runs
          </Text>
          {loading ? <Spinner accessibilityLabel="Loading completed runs" size="small" /> : null}
        </InlineStack>
        <Text as="p" tone="subdued" variant="bodySm">
          Email and SMS runs with final status, provider spend, and attributed order revenue.
        </Text>

        {completedCampaigns.length === 0 ? (
          <Text as="p" tone="subdued" variant="bodySm">
            No completed runs yet.
          </Text>
        ) : (
          <Box className="sf-messaging-history-list">
            {completedCampaigns.map((campaign) => {
              const sentCount = Number(campaign.status_counts?.sent ?? 0)
                + Number(campaign.status_counts?.delivered ?? 0);
              const failedCount = Number(campaign.status_counts?.failed ?? 0)
                + Number(campaign.status_counts?.undelivered ?? 0);
              const expenseCents = Number(campaign.expense_cents ?? 0);
              const attributedOrders = Number(campaign.attributed_orders ?? 0);
              const attributedRevenueCents = Number(campaign.attributed_revenue_cents ?? 0);

              return (
                <Card key={`completed-${campaign.id}`}>
                  <BlockStack gap="200">
                    <InlineStack align="space-between" blockAlign="start" wrap>
                      <BlockStack gap="050">
                        <Text as="p" variant="bodyMd" fontWeight="semibold">
                          {campaignHeaderText(campaign)}
                        </Text>
                        <Text as="p" tone="subdued" variant="bodySm">
                          {campaign.channel.toUpperCase()} · {campaign.subject ?? "No subject"}
                        </Text>
                      </BlockStack>
                      <Badge tone={badgeToneForStatus(campaign.status)}>{campaign.status}</Badge>
                    </InlineStack>

                    <InlineStack gap="200" wrap>
                      <Badge tone="success">Sent {formatCount(sentCount)}</Badge>
                      <Badge tone={failedCount > 0 ? "critical" : "info"}>
                        Failed {formatCount(failedCount)}
                      </Badge>
                      <Badge tone={expenseCents > 0 ? "warning" : "info"}>
                        Expense {formatCurrencyFromCents(expenseCents)}
                      </Badge>
                      <Badge tone={attributedRevenueCents > 0 ? "success" : "info"}>
                        Revenue {formatCurrencyFromCents(attributedRevenueCents)}
                      </Badge>
                      <Badge tone="info">Orders {formatCount(attributedOrders)}</Badge>
                    </InlineStack>
                  </BlockStack>
                </Card>
              );
            })}
          </Box>
        )}
      </BlockStack>
    </Card>
  );
}

function CampaignHistoryCard({
  history,
  loading,
  onCancel,
  cancelingCampaignId,
}: {
  history: MessagingHistoryPayload;
  loading: boolean;
  onCancel?: (campaignId: number) => Promise<void>;
  cancelingCampaignId?: number | null;
}) {
  const campaigns = Array.isArray(history.campaigns) ? history.campaigns : [];

  return (
    <Card>
      <BlockStack gap="300">
        <InlineStack align="space-between" blockAlign="center" wrap>
          <Text as="h3" variant="headingMd">
            Campaign history
          </Text>
          {loading ? <Spinner accessibilityLabel="Loading campaign history" size="small" /> : null}
        </InlineStack>

        {campaigns.length === 0 ? (
          <Text as="p" tone="subdued" variant="bodySm">
            No campaigns yet.
          </Text>
        ) : (
          <Box className="sf-messaging-history-list">
            {campaigns.map((campaign) => {
              const sentCount = Number(campaign.status_counts?.sent ?? 0)
                + Number(campaign.status_counts?.delivered ?? 0);
              const failedCount = Number(campaign.status_counts?.failed ?? 0)
                + Number(campaign.status_counts?.undelivered ?? 0);
              const recipientPendingCount = Number(campaign.status_counts?.pending ?? 0)
                + Number(campaign.status_counts?.queued_for_approval ?? 0)
                + Number(campaign.status_counts?.approved ?? 0)
                + Number(campaign.status_counts?.scheduled ?? 0)
                + Number(campaign.status_counts?.sending ?? 0);
              const activeJobCount = Number(campaign.job_status_counts?.queued ?? 0)
                + Number(campaign.job_status_counts?.retryable ?? 0)
                + Number(campaign.job_status_counts?.dispatching ?? 0)
                + Number(campaign.job_status_counts?.sending ?? 0);
              const pendingCount = Math.max(recipientPendingCount, activeJobCount);
              const progressTotal = sentCount + failedCount + pendingCount;
              const progressValue = progressTotal > 0 ? sentCount / progressTotal : 0;

              return (
                <Card key={campaign.id}>
                  <BlockStack gap="200">
                    <InlineStack align="space-between" blockAlign="start" wrap>
                      <BlockStack gap="050">
                        <Text as="p" variant="bodyMd" fontWeight="semibold">
                          {campaignHeaderText(campaign)}
                        </Text>
                        <Text as="p" tone="subdued" variant="bodySm">
                          {campaign.channel.toUpperCase()} · {campaign.subject ?? "No subject"}
                        </Text>
                      </BlockStack>
                      <InlineStack gap="200" blockAlign="center" wrap>
                        <Badge tone={badgeToneForStatus(campaign.status)}>{campaign.status}</Badge>
                        {campaign.cancelable && onCancel ? (
                          <Button
                            size="slim"
                            loading={cancelingCampaignId === campaign.id}
                            disabled={cancelingCampaignId !== null && cancelingCampaignId !== campaign.id}
                            onClick={() => {
                              void onCancel(campaign.id);
                            }}
                          >
                            Cancel send
                          </Button>
                        ) : null}
                      </InlineStack>
                    </InlineStack>

                    <InlineStack gap="200" wrap>
                      <Badge tone="success">Sent {formatCount(sentCount)}</Badge>
                      <Badge tone={failedCount > 0 ? "critical" : "info"}>
                        Failed {formatCount(failedCount)}
                      </Badge>
                      <Badge tone="attention">Pending {formatCount(pendingCount)}</Badge>
                    </InlineStack>

                    <ProgressBar progress={Math.min(100, Math.max(0, progressValue * 100))} size="small" />

                    {(campaign.failure_codes ?? []).length > 0 ? (
                      <Text as="p" tone="subdued" variant="bodySm">
                        Top failure codes: {(campaign.failure_codes ?? [])
                          .map((entry) => `${entry.code} (${entry.count})`)
                          .join(", ")}
                      </Text>
                    ) : null}
                  </BlockStack>
                </Card>
              );
            })}
          </Box>
        )}
      </BlockStack>
    </Card>
  );
}
