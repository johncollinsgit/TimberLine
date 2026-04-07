import type { MessagingEnvelope } from "./types";

interface EmbeddedAppHelpers {
  resolveEmbeddedAuthHeaders?: (options?: {
    includeJsonContentType?: boolean;
    timeoutMs?: number;
    requestTimeoutMs?: number;
    minTtlMs?: number;
  }) => Promise<Record<string, string>>;
}

interface EmbeddedAuthHeaderOptions {
  includeJsonContentType?: boolean;
  timeoutMs?: number;
  requestTimeoutMs?: number;
  minTtlMs?: number;
  signal?: AbortSignal;
}

interface MessagingRequestOptions extends RequestInit {
  auth?: Omit<EmbeddedAuthHeaderOptions, "includeJsonContentType">;
}

export class MessagingApiError extends Error {
  payload?: MessagingEnvelope<unknown>;

  constructor(message: string, payload?: MessagingEnvelope<unknown>) {
    super(message);
    this.name = "MessagingApiError";
    this.payload = payload;
  }
}

function createAbortError(): DOMException {
  return new DOMException("The operation was aborted.", "AbortError");
}

export function isAbortLikeError(error: unknown): boolean {
  return error instanceof DOMException
    ? error.name === "AbortError"
    : error instanceof Error && error.name === "AbortError";
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

export async function resolveEmbeddedAuthHeaders(
  options: EmbeddedAuthHeaderOptions = {},
): Promise<Record<string, string>> {
  if (options.signal?.aborted) {
    throw createAbortError();
  }

  const helper = (
    window as Window & {
      ForestryEmbeddedApp?: EmbeddedAppHelpers;
    }
  ).ForestryEmbeddedApp;

  if (helper && typeof helper.resolveEmbeddedAuthHeaders === "function") {
    const headers = await helper.resolveEmbeddedAuthHeaders({
      includeJsonContentType: false,
      timeoutMs: options.timeoutMs,
      requestTimeoutMs: options.requestTimeoutMs,
      minTtlMs: options.minTtlMs,
    });

    if (options.signal?.aborted) {
      throw createAbortError();
    }

    return headers;
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
  } catch (error) {
    if (isAbortLikeError(error)) {
      throw error;
    }

    throw new MessagingApiError(authFailureMessage("invalid_session_token"));
  }

  if (options.signal?.aborted) {
    throw createAbortError();
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
  options: MessagingRequestOptions = {},
): Promise<MessagingEnvelope<TData>> {
  const { auth, ...requestOptions } = options;
  const authHeaders = await resolveEmbeddedAuthHeaders({
    ...auth,
    signal: requestOptions.signal,
  });

  const headers = {
    ...authHeaders,
    ...(requestOptions.body ? { "Content-Type": "application/json" } : {}),
    ...(requestOptions.headers ?? {}),
  };

  const response = await fetch(url, {
    credentials: "same-origin",
    ...requestOptions,
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
  options: MessagingRequestOptions = {},
): Promise<MessagingEnvelope<TData>> {
  const { auth, ...requestOptions } = options;
  const authHeaders = await resolveEmbeddedAuthHeaders({
    ...auth,
    signal: requestOptions.signal,
  });

  const response = await fetch(url, {
    credentials: "same-origin",
    ...requestOptions,
    body,
    headers: {
      ...authHeaders,
      ...(requestOptions.headers ?? {}),
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
