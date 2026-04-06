import {
  AppProvider,
  Badge,
  Banner,
  BlockStack,
  Box,
  Button,
  Card,
  InlineStack,
  Spinner,
  Text,
  TextField,
} from "@shopify/polaris";
import enTranslations from "@shopify/polaris/locales/en.json";
import { useEffect, useMemo, useState } from "react";
import { MessagingApiError, requestMessagingJson } from "../messaging/api";
import "./responses.css";

export interface ResponsesBootstrap {
  authorized: boolean;
  tenant_id: number | null;
  status: string;
  module_access: boolean;
  endpoints: {
    index?: string;
    detail_base?: string;
    update_base?: string;
    reply_base?: string;
  };
}

type Channel = "sms" | "email";
type Filter = "open" | "unread" | "opted_out" | "assigned_to_me" | "all";

interface SummaryPayload {
  sms_unread: number;
  email_unread: number;
  open: number;
  needs_follow_up: number;
  opted_out_today: number;
}

interface ConversationRow {
  id: number;
  channel: Channel;
  status: string;
  identity: string;
  display_name?: string | null;
  subject?: string | null;
  preview?: string | null;
  unread_count: number;
  last_message_at?: string | null;
  subscription_state?: string | null;
  opted_out: boolean;
  assigned_to?: {
    id: number;
    name: string;
  } | null;
  profile?: {
    id: number;
    name?: string | null;
    email?: string | null;
    phone?: string | null;
  } | null;
  source_context?: Record<string, unknown>;
}

interface MessageRow {
  id: number;
  direction: "inbound" | "outbound" | "system";
  body: string;
  subject?: string | null;
  message_type: string;
  delivery_status?: string | null;
  from_identity?: string | null;
  to_identity?: string | null;
  sent_at?: string | null;
  received_at?: string | null;
  created_at?: string | null;
  creator?: {
    id: number;
    name: string;
  } | null;
}

interface IndexPayload {
  summary: SummaryPayload;
  conversations: ConversationRow[];
}

interface DetailPayload {
  summary: SummaryPayload;
  conversation: ConversationRow;
  messages: MessageRow[];
}

function replaceConversationId(template: string | undefined, conversationId: number): string {
  return (template ?? "").replace("__CONVERSATION__", String(conversationId));
}

function formatDateTime(value?: string | null): string {
  if (!value) {
    return "Now";
  }

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return value;
  }

  return new Intl.DateTimeFormat("en-US", {
    month: "short",
    day: "numeric",
    hour: "numeric",
    minute: "2-digit",
  }).format(date);
}

function statusTone(status: string):
  | "critical"
  | "warning"
  | "success"
  | "info"
  | "attention"
  | undefined {
  const normalized = status.trim().toLowerCase();
  if (normalized === "opted_out") {
    return "critical";
  }
  if (normalized === "open") {
    return "attention";
  }
  if (normalized === "closed") {
    return "success";
  }
  if (normalized === "archived") {
    return "info";
  }

  return "warning";
}

function subscriptionTone(state?: string | null):
  | "critical"
  | "warning"
  | "success"
  | "info"
  | "attention"
  | undefined {
  const normalized = (state ?? "").trim().toLowerCase();
  if (["unsubscribed", "suppressed", "bounced"].includes(normalized)) {
    return "critical";
  }
  if (normalized === "subscribed") {
    return "success";
  }

  return "info";
}

export function ResponsesApp({ bootstrap }: { bootstrap: ResponsesBootstrap }) {
  const [channel, setChannel] = useState<Channel>("sms");
  const [filter, setFilter] = useState<Filter>("open");
  const [search, setSearch] = useState("");
  const [summary, setSummary] = useState<SummaryPayload>({
    sms_unread: 0,
    email_unread: 0,
    open: 0,
    needs_follow_up: 0,
    opted_out_today: 0,
  });
  const [conversations, setConversations] = useState<ConversationRow[]>([]);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [selectedConversation, setSelectedConversation] = useState<ConversationRow | null>(null);
  const [messages, setMessages] = useState<MessageRow[]>([]);
  const [loadingList, setLoadingList] = useState(false);
  const [loadingThread, setLoadingThread] = useState(false);
  const [mutating, setMutating] = useState(false);
  const [replyBody, setReplyBody] = useState("");
  const [replySubject, setReplySubject] = useState("");
  const [banner, setBanner] = useState<{ tone: "critical" | "success" | "info" | "warning"; message: string } | null>(null);

  const canReply = useMemo(() => {
    if (!selectedConversation) {
      return false;
    }

    const state = (selectedConversation.subscription_state ?? "").toLowerCase();
    if (selectedConversation.channel === "sms") {
      return !selectedConversation.opted_out && !["unsubscribed", "suppressed"].includes(state);
    }

    return !["unsubscribed", "suppressed", "bounced"].includes(state);
  }, [selectedConversation]);

  async function loadConversation(conversationId: number) {
    const detailUrl = replaceConversationId(bootstrap.endpoints.detail_base, conversationId);
    if (!detailUrl) {
      return;
    }

    setLoadingThread(true);
    try {
      const response = await requestMessagingJson<{ data: DetailPayload } | DetailPayload>(detailUrl);
      const payload = (response.data ?? response) as unknown as DetailPayload;
      setSummary(payload.summary);
      setSelectedConversation(payload.conversation);
      setMessages(payload.messages);
      setSelectedId(payload.conversation.id);
      setReplySubject(payload.conversation.subject ?? "");
    } catch (error) {
      const message = error instanceof MessagingApiError ? error.message : "Could not load conversation.";
      setBanner({ tone: "critical", message });
    } finally {
      setLoadingThread(false);
    }
  }

  async function loadList(preserveSelected = true) {
    if (!bootstrap.endpoints.index) {
      return;
    }

    setLoadingList(true);
    try {
      const url = new URL(bootstrap.endpoints.index, window.location.origin);
      url.searchParams.set("channel", channel);
      url.searchParams.set("filter", filter);
      if (search.trim()) {
        url.searchParams.set("search", search.trim());
      }

      const response = await requestMessagingJson<{ data: IndexPayload } | IndexPayload>(url.toString());
      const payload = (response.data ?? response) as unknown as IndexPayload;
      setSummary(payload.summary);
      setConversations(payload.conversations);

      const nextSelectedId =
        preserveSelected && selectedId && payload.conversations.some((row) => row.id === selectedId)
          ? selectedId
          : payload.conversations[0]?.id ?? null;
      setSelectedId(nextSelectedId);

      if (nextSelectedId) {
        await loadConversation(nextSelectedId);
      } else {
        setSelectedConversation(null);
        setMessages([]);
      }
    } catch (error) {
      const message = error instanceof MessagingApiError ? error.message : "Could not load responses.";
      setBanner({ tone: "critical", message });
    } finally {
      setLoadingList(false);
    }
  }

  useEffect(() => {
    void loadList(false);
  }, [channel, filter, search]);

  async function runAction(action: string) {
    if (!selectedId) {
      return;
    }

    const url = replaceConversationId(bootstrap.endpoints.update_base, selectedId);
    if (!url) {
      return;
    }

    setMutating(true);
    try {
      await requestMessagingJson(url, {
        method: "POST",
        body: JSON.stringify({ action }),
      });
      setBanner({ tone: "success", message: "Conversation updated." });
      await loadList();
    } catch (error) {
      const message = error instanceof MessagingApiError ? error.message : "Could not update conversation.";
      setBanner({ tone: "critical", message });
    } finally {
      setMutating(false);
    }
  }

  async function sendReply() {
    if (!selectedId || !replyBody.trim()) {
      return;
    }

    const url = replaceConversationId(bootstrap.endpoints.reply_base, selectedId);
    if (!url) {
      return;
    }

    setMutating(true);
    try {
      await requestMessagingJson(url, {
        method: "POST",
        body: JSON.stringify({
          body: replyBody.trim(),
          subject: selectedConversation?.channel === "email" ? replySubject.trim() : undefined,
        }),
      });
      setReplyBody("");
      setBanner({ tone: "success", message: "Reply sent." });
      await loadList();
    } catch (error) {
      const message = error instanceof MessagingApiError ? error.message : "Could not send reply.";
      setBanner({ tone: "critical", message });
    } finally {
      setMutating(false);
    }
  }

  return (
    <AppProvider i18n={enTranslations}>
      <BlockStack gap="400">
        {banner ? (
          <Banner tone={banner.tone} onDismiss={() => setBanner(null)}>
            <p>{banner.message}</p>
          </Banner>
        ) : null}

        <Card>
          <BlockStack gap="300">
            <InlineStack align="space-between" blockAlign="center">
              <BlockStack gap="100">
                <Text as="h2" variant="headingLg">
                  Responses
                </Text>
                <Text as="p" variant="bodySm" tone="subdued">
                  Lightweight support inbox for Text and Email replies, with opt-out safety built in.
                </Text>
              </BlockStack>
              <InlineStack gap="200">
                <Button pressed={channel === "sms"} onClick={() => setChannel("sms")}>
                  Text
                </Button>
                <Button pressed={channel === "email"} onClick={() => setChannel("email")}>
                  Email
                </Button>
              </InlineStack>
            </InlineStack>

            <div className="responses-summary-grid">
              <div className="responses-summary-card">
                <span>Unread Text</span>
                <strong>{summary.sms_unread}</strong>
              </div>
              <div className="responses-summary-card">
                <span>Unread Email</span>
                <strong>{summary.email_unread}</strong>
              </div>
              <div className="responses-summary-card">
                <span>Open</span>
                <strong>{summary.open}</strong>
              </div>
              <div className="responses-summary-card">
                <span>Opted Out Today</span>
                <strong>{summary.opted_out_today}</strong>
              </div>
            </div>

            <div className="responses-toolbar">
              <TextField label="Search" labelHidden value={search} onChange={setSearch} placeholder="Search name, phone, email, or preview" autoComplete="off" />
              <InlineStack gap="200" wrap>
                {(["open", "unread", "opted_out", "assigned_to_me", "all"] as Filter[]).map((option) => (
                  <Button
                    key={option}
                    pressed={filter === option}
                    onClick={() => setFilter(option)}
                  >
                    {option === "assigned_to_me" ? "Assigned to me" : option.replace("_", " ")}
                  </Button>
                ))}
                <Button onClick={() => void loadList()} loading={loadingList}>
                  Refresh
                </Button>
              </InlineStack>
            </div>
          </BlockStack>
        </Card>

        <div className="responses-layout">
          <Card>
            <div className="responses-sidebar">
              {loadingList ? (
                <div className="responses-empty">
                  <Spinner size="small" />
                </div>
              ) : conversations.length === 0 ? (
                <div className="responses-empty">
                  <Text as="p" variant="bodyMd">
                    No {channel === "sms" ? "text" : "email"} conversations match this filter.
                  </Text>
                </div>
              ) : (
                conversations.map((conversation) => (
                  <button
                    type="button"
                    key={conversation.id}
                    className={`responses-thread-row${selectedId === conversation.id ? " is-selected" : ""}`}
                    onClick={() => void loadConversation(conversation.id)}
                  >
                    <InlineStack align="space-between" blockAlign="start">
                      <BlockStack gap="050">
                        <Text as="span" variant="bodyMd" fontWeight="semibold">
                          {conversation.display_name ?? conversation.identity}
                        </Text>
                        <Text as="span" variant="bodySm" tone="subdued">
                          {conversation.subject ?? conversation.preview ?? conversation.identity}
                        </Text>
                      </BlockStack>
                      <Text as="span" variant="bodySm" tone="subdued">
                        {formatDateTime(conversation.last_message_at)}
                      </Text>
                    </InlineStack>
                    <InlineStack gap="200" wrap>
                      <Badge tone={statusTone(conversation.status)}>{conversation.status}</Badge>
                      {conversation.subscription_state ? (
                        <Badge tone={subscriptionTone(conversation.subscription_state)}>
                          {conversation.subscription_state}
                        </Badge>
                      ) : null}
                      {conversation.unread_count > 0 ? (
                        <Badge tone="attention">{conversation.unread_count} unread</Badge>
                      ) : null}
                    </InlineStack>
                  </button>
                ))
              )}
            </div>
          </Card>

          <Card>
            <div className="responses-thread">
              {loadingThread ? (
                <div className="responses-empty">
                  <Spinner size="small" />
                </div>
              ) : !selectedConversation ? (
                <div className="responses-empty">
                  <Text as="p" variant="bodyMd">
                    Select a conversation to review the thread.
                  </Text>
                </div>
              ) : (
                <>
                  <div className="responses-thread-header">
                    <BlockStack gap="100">
                      <Text as="h3" variant="headingMd">
                        {selectedConversation.display_name ?? selectedConversation.identity}
                      </Text>
                      <InlineStack gap="200" wrap>
                        <Badge tone="info">{selectedConversation.channel === "sms" ? "Text" : "Email"}</Badge>
                        <Badge tone={statusTone(selectedConversation.status)}>{selectedConversation.status}</Badge>
                        {selectedConversation.subscription_state ? (
                          <Badge tone={subscriptionTone(selectedConversation.subscription_state)}>
                            {selectedConversation.subscription_state}
                          </Badge>
                        ) : null}
                        {selectedConversation.profile?.id ? (
                          <Badge tone="info">Customer #{selectedConversation.profile.id}</Badge>
                        ) : null}
                        {selectedConversation.source_context?.campaign_id ? (
                          <Badge tone="info">Campaign linked</Badge>
                        ) : null}
                      </InlineStack>
                      <Text as="p" variant="bodySm" tone="subdued">
                        {selectedConversation.identity}
                      </Text>
                    </BlockStack>
                    <InlineStack gap="200" wrap>
                      <Button size="slim" onClick={() => void runAction("mark_read")} disabled={mutating}>
                        Mark read
                      </Button>
                      <Button size="slim" onClick={() => void runAction("mark_unread")} disabled={mutating}>
                        Mark unread
                      </Button>
                      <Button size="slim" onClick={() => void runAction("assign_to_me")} disabled={mutating}>
                        Assign to me
                      </Button>
                      <Button size="slim" onClick={() => void runAction("close")} disabled={mutating}>
                        Close
                      </Button>
                      <Button size="slim" onClick={() => void runAction("archive")} disabled={mutating}>
                        Archive
                      </Button>
                    </InlineStack>
                  </div>

                  <div className="responses-messages">
                    {messages.map((message) => (
                      <div key={message.id} className={`responses-message-card responses-message-card--${message.direction}`}>
                        <InlineStack align="space-between" blockAlign="start">
                          <Text as="span" variant="bodySm" fontWeight="semibold">
                            {message.direction === "inbound"
                              ? "Customer"
                              : message.direction === "outbound"
                                ? message.creator?.name ?? "Backstage"
                                : "System"}
                          </Text>
                          <Text as="span" variant="bodySm" tone="subdued">
                            {formatDateTime(message.received_at ?? message.sent_at ?? message.created_at)}
                          </Text>
                        </InlineStack>
                        {message.subject ? (
                          <Text as="p" variant="bodySm" fontWeight="medium">
                            {message.subject}
                          </Text>
                        ) : null}
                        <Text as="p" variant="bodyMd">
                          {message.body || "(No body)"}
                        </Text>
                        <InlineStack gap="200" wrap>
                          <Badge tone="info">{message.message_type}</Badge>
                          {message.delivery_status ? <Badge tone="info">{message.delivery_status}</Badge> : null}
                        </InlineStack>
                      </div>
                    ))}
                  </div>

                  <div className="responses-composer">
                    {selectedConversation.channel === "email" ? (
                      <TextField
                        label="Subject"
                        value={replySubject}
                        onChange={setReplySubject}
                        autoComplete="off"
                      />
                    ) : null}
                    <TextField
                      label="Reply"
                      value={replyBody}
                      onChange={setReplyBody}
                      multiline={5}
                      autoComplete="off"
                      disabled={!canReply}
                      helpText={
                        canReply
                          ? "Replies are sent from Backstage and appended to this thread."
                          : selectedConversation.channel === "sms"
                            ? "SMS replies are blocked because this contact is opted out or suppressed."
                            : "Email replies are blocked because this contact is unsubscribed, bounced, or suppressed."
                      }
                    />
                    <InlineStack align="space-between" blockAlign="center">
                      <Text as="span" variant="bodySm" tone="subdued">
                        {selectedConversation.assigned_to
                          ? `Assigned to ${selectedConversation.assigned_to.name}`
                          : "Unassigned"}
                      </Text>
                      <Button variant="primary" onClick={() => void sendReply()} disabled={!canReply || !replyBody.trim()} loading={mutating}>
                        Send reply
                      </Button>
                    </InlineStack>
                  </div>
                </>
              )}
            </div>
          </Card>
        </div>
      </BlockStack>
    </AppProvider>
  );
}
