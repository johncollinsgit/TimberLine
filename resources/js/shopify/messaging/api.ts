import type { MessagingEnvelope } from "./types";

interface EmbeddedAppHelpers {
  resolveEmbeddedAuthHeaders?: (options?: {
    includeJsonContentType?: boolean;
    timeoutMs?: number;
    requestTimeoutMs?: number;
    minTtlMs?: number;
  }) => Promise<Record<string, string>>;
}

export class MessagingApiError extends Error {
  payload?: MessagingEnvelope<unknown>;

  constructor(message: string, payload?: MessagingEnvelope<unknown>) {
    super(message);
    this.name = "MessagingApiError";
    this.payload = payload;
  }
}

function authFailureMessage(status?: string | null, fallback?: string | null): string {
  const byStatus: Record<string, string> = {
    missing_api_auth:
      "Shopify Admin verification is unavailable. Reload this page from Shopify Admin and try again.",
    invalid_session_token:
      "Shopify Admin verification failed. Reload this page from Shopify Admin and try again.",
    expired_session_token:
      "Your Shopify Admin session expired. Reload this page from Shopify Admin and try again.",
  };

  return byStatus[status ?? ""] ?? fallback ?? "Request failed.";
}

export async function resolveEmbeddedAuthHeaders(): Promise<Record<string, string>> {
  const helper = (
    window as Window & {
      ForestryEmbeddedApp?: EmbeddedAppHelpers;
    }
  ).ForestryEmbeddedApp;

  if (helper && typeof helper.resolveEmbeddedAuthHeaders === "function") {
    return helper.resolveEmbeddedAuthHeaders({
      includeJsonContentType: false,
    });
  }

  const bridge = (
    window as Window & {
      shopify?: {
        idToken?: () => Promise<string> | string;
      };
    }
  ).shopify;

  if (!bridge || typeof bridge.idToken !== "function") {
    throw new MessagingApiError(authFailureMessage("missing_api_auth"));
  }

  let token: unknown;

  try {
    token = await Promise.race([
      Promise.resolve(bridge.idToken()),
      new Promise<null>((resolve) => window.setTimeout(() => resolve(null), 6000)),
    ]);
  } catch {
    throw new MessagingApiError(authFailureMessage("invalid_session_token"));
  }

  if (typeof token !== "string" || token.trim() === "") {
    throw new MessagingApiError(authFailureMessage("missing_api_auth"));
  }

  return {
    Accept: "application/json",
    Authorization: `Bearer ${token.trim()}`,
  };
}

export async function requestMessagingJson<TData>(
  url: string,
  options: RequestInit = {},
): Promise<MessagingEnvelope<TData>> {
  const authHeaders = await resolveEmbeddedAuthHeaders();

  const headers = {
    ...authHeaders,
    ...(options.body ? { "Content-Type": "application/json" } : {}),
    ...(options.headers ?? {}),
  };

  const response = await fetch(url, {
    credentials: "same-origin",
    ...options,
    headers,
  });

  const payload = (await response.json().catch(() => ({
    ok: false,
    message: "Unexpected response from Backstage.",
  }))) as MessagingEnvelope<TData>;

  if (!response.ok || !payload.ok) {
    throw new MessagingApiError(
      authFailureMessage(payload.status, payload.message ?? "Request failed."),
      payload,
    );
  }

  return payload;
}

export async function requestMessagingFormData<TData>(
  url: string,
  body: FormData,
  options: RequestInit = {},
): Promise<MessagingEnvelope<TData>> {
  const authHeaders = await resolveEmbeddedAuthHeaders();

  const response = await fetch(url, {
    credentials: "same-origin",
    ...options,
    body,
    headers: {
      ...authHeaders,
      ...(options.headers ?? {}),
    },
  });

  const payload = (await response.json().catch(() => ({
    ok: false,
    message: "Unexpected response from Backstage.",
  }))) as MessagingEnvelope<TData>;

  if (!response.ok || !payload.ok) {
    throw new MessagingApiError(
      authFailureMessage(payload.status, payload.message ?? "Request failed."),
      payload,
    );
  }

  return payload;
}
