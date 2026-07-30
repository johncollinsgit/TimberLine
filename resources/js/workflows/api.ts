export class WorkflowApiError extends Error {
  status: number;
  payload: Record<string, unknown>;

  constructor(status: number, message: string, payload: Record<string, unknown> = {}) {
    super(message);
    this.name = "WorkflowApiError";
    this.status = status;
    this.payload = payload;
  }
}

function firstError(payload: Record<string, unknown>): string | null {
  if (typeof payload.message === "string" && payload.message.trim() !== "") {
    return payload.message;
  }

  if (payload.errors && typeof payload.errors === "object") {
    const values = Object.values(payload.errors as Record<string, unknown>);
    for (const value of values) {
      if (Array.isArray(value) && typeof value[0] === "string") {
        return value[0];
      }
      if (typeof value === "string") {
        return value;
      }
    }
  }

  return null;
}

export function fillEndpoint(
  endpoint: string | undefined,
  values: Record<string, string | number | null | undefined>,
): string {
  if (!endpoint) {
    return "";
  }

  return Object.entries(values).reduce(
    (resolved, [key, value]) => resolved.replaceAll(`{${key}}`, encodeURIComponent(String(value ?? ""))),
    endpoint,
  );
}

export async function workflowRequest<T extends Record<string, unknown> = Record<string, unknown>>(
  endpoint: string,
  csrfToken: string,
  options: {
    method?: "GET" | "POST" | "PUT" | "PATCH" | "DELETE";
    body?: Record<string, unknown>;
    signal?: AbortSignal;
  } = {},
): Promise<T> {
  if (!endpoint) {
    throw new WorkflowApiError(0, "This action is not available yet.");
  }

  const response = await fetch(endpoint, {
    method: options.method ?? "POST",
    credentials: "same-origin",
    redirect: "follow",
    signal: options.signal,
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      "X-CSRF-TOKEN": csrfToken,
      "X-Requested-With": "XMLHttpRequest",
    },
    body: options.body === undefined ? undefined : JSON.stringify(options.body),
  });

  const contentType = response.headers.get("content-type") ?? "";
  const payload = contentType.includes("application/json")
    ? ((await response.json()) as Record<string, unknown>)
    : {};

  if (!response.ok) {
    throw new WorkflowApiError(
      response.status,
      firstError(payload) ?? `The workflow request failed (${response.status}).`,
      payload,
    );
  }

  if (response.redirected && !contentType.includes("application/json")) {
    window.location.assign(response.url);
    return {} as T;
  }

  return payload as T;
}
