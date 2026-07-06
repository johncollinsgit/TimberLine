import Constants from "expo-constants";
import * as SecureStore from "expo-secure-store";
import { WorkActivity, WorkBootstrap, WorkComment, WorkHome, WorkJob, WorkTask, TeamUser } from "./types";

const TOKEN_KEY = "everbranch_work_token";

const extra = Constants.expoConfig?.extra as { apiBaseUrl?: string } | undefined;

export const apiBaseUrl =
  process.env.EXPO_PUBLIC_EVERBRANCH_WORK_API_BASE ||
  extra?.apiBaseUrl ||
  "http://127.0.0.1:8000/api/mobile/work/v1";

export async function getToken() {
  return SecureStore.getItemAsync(TOKEN_KEY);
}

export async function setToken(token: string) {
  await SecureStore.setItemAsync(TOKEN_KEY, token);
}

export async function clearToken() {
  await SecureStore.deleteItemAsync(TOKEN_KEY);
}

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const token = await getToken();
  const headers = new Headers(options.headers);
  headers.set("Accept", "application/json");
  if (!(options.body instanceof FormData)) {
    headers.set("Content-Type", "application/json");
  }
  if (token) {
    headers.set("Authorization", `Bearer ${token}`);
  }

  const response = await fetch(`${apiBaseUrl}${path}`, { ...options, headers });
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    throw new Error(payload.message || "Request failed");
  }

  return payload as T;
}

export function requestLink(email: string) {
  return request<{ ok: boolean; debug?: { token: string } }>("/auth/request-link", {
    method: "POST",
    body: JSON.stringify({ email })
  });
}

export async function acceptLink(token: string) {
  const payload = await request<{ access_token: string; bootstrap: WorkBootstrap }>("/auth/accept-link", {
    method: "POST",
    body: JSON.stringify({ token })
  });
  await setToken(payload.access_token);
  return payload.bootstrap;
}

export function bootstrap() {
  return request<WorkBootstrap>("/bootstrap");
}

export function selectTenant(tenant: string) {
  return request<{ bootstrap: WorkBootstrap }>("/tenants/select", {
    method: "POST",
    body: JSON.stringify({ tenant })
  });
}

export function home() {
  return request<WorkHome>("/home");
}

export function jobs(query = "") {
  return request<{ jobs: WorkJob[] }>(`/jobs${query}`);
}

export function updateJobStatus(jobId: number, status: string) {
  return request<{ job: WorkJob }>(`/jobs/${jobId}`, {
    method: "PATCH",
    body: JSON.stringify({ status })
  });
}

export function updateTaskStatus(taskId: number, status: string) {
  return request<{ task: WorkTask }>(`/tasks/${taskId}`, {
    method: "PATCH",
    body: JSON.stringify({ status })
  });
}

export function jobComments(jobId: number) {
  return request<{ comments: WorkComment[] }>(`/jobs/${jobId}/comments`);
}

export function storeJobComment(jobId: number, body: string, mentioned_user_ids: number[] = []) {
  return request<{ comment: WorkComment }>(`/jobs/${jobId}/comments`, {
    method: "POST",
    body: JSON.stringify({ body, mentioned_user_ids })
  });
}

export function jobActivity(jobId: number) {
  return request<{ activity: WorkActivity[] }>(`/jobs/${jobId}/activity`);
}

export function taskComments(taskId: number) {
  return request<{ comments: WorkComment[] }>(`/tasks/${taskId}/comments`);
}

export function storeTaskComment(taskId: number, body: string, mentioned_user_ids: number[] = []) {
  return request<{ comment: WorkComment }>(`/tasks/${taskId}/comments`, {
    method: "POST",
    body: JSON.stringify({ body, mentioned_user_ids })
  });
}

export function taskActivity(taskId: number) {
  return request<{ activity: WorkActivity[] }>(`/tasks/${taskId}/activity`);
}

export function team() {
  return request<{ team: TeamUser[] }>("/team");
}

export function notifyTeamMember(userId: number, body: string) {
  return request(`/team/${userId}/notify`, {
    method: "POST",
    body: JSON.stringify({ body })
  });
}

export function registerPushDevice(input: {
  platform: "ios" | "android";
  device_token: string;
  authorization_status?: string;
  app_version?: string;
}) {
  return request("/notifications/push/register", {
    method: "POST",
    body: JSON.stringify(input)
  });
}
