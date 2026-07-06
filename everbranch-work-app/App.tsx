import { Ionicons } from "@expo/vector-icons";
import Constants from "expo-constants";
import * as Linking from "expo-linking";
import * as Notifications from "expo-notifications";
import { StatusBar } from "expo-status-bar";
import type { ReactNode } from "react";
import { useCallback, useEffect, useMemo, useState } from "react";
import {
  ActivityIndicator,
  FlatList,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  RefreshControl,
  SafeAreaView,
  StyleSheet,
  Text,
  TextInput,
  Modal,
  ScrollView,
  View
} from "react-native";
import {
  acceptLink,
  apiBaseUrl,
  bootstrap,
  clearToken,
  jobActivity,
  jobComments,
  home,
  jobs,
  notifyTeamMember,
  registerPushDevice,
  requestLink,
  storeJobComment,
  storeTaskComment,
  selectTenant,
  taskActivity,
  taskComments,
  team,
  updateJobStatus,
  updateTaskStatus
} from "./src/api";
import { TeamUser, WorkActivity, WorkBootstrap, WorkComment, WorkHome, WorkJob, WorkTask } from "./src/types";

type Tab = "home" | "jobs" | "team";
type DetailItem =
  | { kind: "job"; item: WorkJob }
  | { kind: "task"; item: WorkTask };

const statuses = ["open", "scheduled", "in_progress", "blocked", "done"];
const demoCrew: TeamUser[] = [
  { id: -101, name: "Carlos", email: "carlos@demo.local", role: "member" },
  { id: -102, name: "Andrew", email: "andrew@demo.local", role: "member" },
  { id: -103, name: "Neal", email: "neal@demo.local", role: "member" }
];

export default function App() {
  const [boot, setBoot] = useState<WorkBootstrap | null>(null);
  const [tab, setTab] = useState<Tab>("home");
  const [email, setEmail] = useState("");
  const [message, setMessage] = useState("");
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [homeData, setHomeData] = useState<WorkHome | null>(null);
  const [jobRows, setJobRows] = useState<WorkJob[]>([]);
  const [teamRows, setTeamRows] = useState<TeamUser[]>([]);
  const [teamLoading, setTeamLoading] = useState(false);
  const [query, setQuery] = useState("");
  const [scheduleView, setScheduleView] = useState<"day" | "list" | "week">("day");
  const [detailItem, setDetailItem] = useState<DetailItem | null>(null);
  const visibleTeamRows = teamRows.length ? teamRows : demoCrew;

  const lookupJob = useCallback((jobId: number) => {
    return (
      homeData?.assigned_jobs?.find((job) => job.id === jobId)
      || jobRows.find((job) => job.id === jobId)
    );
  }, [homeData?.assigned_jobs, jobRows]);

  const loadBootstrap = useCallback(async () => {
    const payload = await bootstrap();
    setBoot(payload);
    return payload;
  }, []);

  const loadHome = useCallback(async () => {
    const payload = await home();
    setHomeData(payload);
    setBoot(payload);
  }, []);

  const loadJobs = useCallback(async () => {
    const suffix = query.trim() ? `?q=${encodeURIComponent(query.trim())}` : "";
    const payload = await jobs(suffix);
    setJobRows(payload.jobs);
  }, [query]);

  const loadTeam = useCallback(async () => {
    setTeamLoading(true);
    try {
      const payload = await team();
      setTeamRows(payload.team);
    } finally {
      setTeamLoading(false);
    }
  }, []);

  const refresh = useCallback(async () => {
    if (!boot?.selected_tenant || boot.requires_tenant_selection) return;
    setRefreshing(true);
    try {
      if (tab === "home") await loadHome();
      if (tab === "jobs") await loadJobs();
      if (tab === "team") await loadTeam();
    } finally {
      setRefreshing(false);
    }
  }, [boot?.requires_tenant_selection, boot?.selected_tenant, loadHome, loadJobs, loadTeam, tab]);

  const finishLogin = useCallback(async (token: string) => {
    setLoading(true);
    try {
      const payload = await acceptLink(token);
      setBoot(payload);
      if (!payload.requires_tenant_selection) {
        await registerForPush();
        await loadHome();
      }
    } finally {
      setLoading(false);
    }
  }, [loadHome]);

  useEffect(() => {
    let mounted = true;

    async function bootApp() {
      try {
        const url = await Linking.getInitialURL();
        const token = tokenFromUrl(url);
        if (token) {
          await finishLogin(token);
          return;
        }
        const payload = await loadBootstrap();
        if (mounted && payload.selected_tenant && !payload.requires_tenant_selection) {
          await loadHome();
        }
      } catch {
        await clearToken();
      } finally {
        if (mounted) setLoading(false);
      }
    }

    const subscription = Linking.addEventListener("url", (event) => {
      const token = tokenFromUrl(event.url);
      if (token) finishLogin(token).catch(() => setMessage("Link failed"));
    });

    bootApp();
    return () => {
      mounted = false;
      subscription.remove();
    };
  }, [finishLogin, loadBootstrap, loadHome]);

  useEffect(() => {
    if (boot?.selected_tenant && !boot.requires_tenant_selection) {
      refresh().catch(() => setMessage("Could not load"));
    }
  }, [boot?.selected_tenant?.id, query, refresh]);

  useEffect(() => {
    if (tab === "team" && boot?.selected_tenant && !boot.requires_tenant_selection) {
      loadTeam().catch(() => setMessage("Could not load"));
    }
  }, [boot?.requires_tenant_selection, boot?.selected_tenant?.id, loadTeam, tab]);

  const tabs = useMemo(() => (boot?.tabs?.length ? boot.tabs : [
    { key: "home", label: "Home" },
    { key: "jobs", label: "Jobs" },
    { key: "team", label: "Team" }
  ]), [boot?.tabs]);
  const weekDays = useMemo(() => buildWeekDays(jobRows), [jobRows]);

  if (loading) {
    return <Shell><ActivityIndicator color="#173B35" /></Shell>;
  }

  if (!boot) {
    return (
      <Shell>
        <KeyboardAvoidingView behavior={Platform.OS === "ios" ? "padding" : undefined} style={styles.login}>
          <Text style={styles.logo}>Everbranch Work</Text>
          <TextInput
            autoCapitalize="none"
            autoComplete="email"
            keyboardType="email-address"
            onChangeText={setEmail}
            placeholder="Email"
            style={styles.input}
            value={email}
          />
          <Pressable
            style={styles.primaryButton}
            onPress={async () => {
              setMessage("");
              const response = await requestLink(email);
              if (response.debug?.token) {
                await finishLogin(response.debug.token);
              } else {
                setMessage("Check email");
              }
            }}
          >
            <Text style={styles.primaryButtonText}>Send link</Text>
          </Pressable>
          {!!message && <Text style={styles.message}>{message}</Text>}
          <Text style={styles.apiBase}>{apiBaseUrl.replace(/^https?:\/\//, "")}</Text>
        </KeyboardAvoidingView>
      </Shell>
    );
  }

  if (boot.requires_tenant_selection) {
    return (
      <Shell title="Workspace">
        <FlatList
          data={boot.available_tenants}
          keyExtractor={(item) => String(item.id)}
          renderItem={({ item }) => (
            <Row
              title={item.name}
              meta={item.role}
              onPress={async () => {
                const payload = await selectTenant(item.slug);
                setBoot(payload.bootstrap);
                await registerForPush();
                await loadHome();
              }}
            />
          )}
        />
      </Shell>
    );
  }

  return (
    <Shell
      title={tab === "home" ? undefined : tab === "jobs" ? "Jobs" : "Team"}
      right={tab === "home" ? undefined : <Badge count={homeData?.summary.unread_notifications || 0} />}
    >
      {tab === "home" && (
        <ScrollView
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor="#EAF2CC" colors={["#173B35"]} />}
          contentContainerStyle={styles.feedContent}
        >
          <Summary
            data={homeData}
            onOpenJob={(item) => setDetailItem({ kind: "job", item })}
            onOpenTask={(item) => setDetailItem({ kind: "task", item })}
          />
        </ScrollView>
      )}

      {tab === "jobs" && (
        <View style={styles.flex}>
          <View style={styles.jobsTop}>
            <View style={styles.segmentedControl}>
              {(["day", "list", "week"] as const).map((item) => (
                <Pressable
                  key={item}
                  onPress={() => setScheduleView(item)}
                  style={[styles.segment, scheduleView === item && styles.segmentActive]}
                >
                  <Text style={[styles.segmentText, scheduleView === item && styles.segmentTextActive]}>{itemLabel(item)}</Text>
                </Pressable>
              ))}
            </View>
            <View style={styles.scheduleStrip}>
              {weekDays.map((item) => (
                <View key={item.key} style={[styles.dayTile, item.active && styles.dayTileActive]}>
                  <Text style={[styles.dayLabel, item.active && styles.dayLabelActive]}>{item.day}</Text>
                  <Text style={[styles.dayNumber, item.active && styles.dayNumberActive]}>{item.number}</Text>
                  {!!item.count && <Text style={[styles.dayCount, item.active && styles.dayCountActive]}>{item.count}</Text>}
                </View>
              ))}
            </View>
            <View style={styles.searchShell}>
              <Ionicons name="search" size={18} color="#7D847D" />
              <TextInput
                placeholder="Search jobs"
                value={query}
                onChangeText={setQuery}
                style={styles.search}
                placeholderTextColor="#8A908A"
              />
            </View>
          </View>
          <FlatList
            refreshing={refreshing}
            onRefresh={refresh}
            contentContainerStyle={styles.listContent}
            data={jobRows}
            ListEmptyComponent={<Empty label="No jobs" />}
            keyExtractor={(item) => String(item.id)}
            renderItem={({ item }) => (
              <JobRow job={item} onPress={() => setDetailItem({ kind: "job", item })} />
            )}
          />
        </View>
      )}

      {tab === "team" && (
        <FlatList
          refreshing={refreshing}
          onRefresh={refresh}
          contentContainerStyle={styles.listContent}
          data={visibleTeamRows}
          ListEmptyComponent={teamLoading ? <View style={styles.teamLoading}><ActivityIndicator color="#173B35" /></View> : <Empty label="No team" />}
          keyExtractor={(item) => String(item.id)}
          renderItem={({ item }) => (
            <Row
              title={item.name}
              meta={item.role}
              right={<Ionicons name="notifications-outline" size={20} color="#173B35" />}
              onPress={async () => {
                if (item.id < 0) {
                  setMessage("Demo crew");
                  return;
                }
                await notifyTeamMember(item.id, "Can you check this?");
                setMessage("Sent");
              }}
            />
          )}
        />
      )}

      {!!message && <Text style={styles.toast}>{message}</Text>}
      <View style={styles.tabs}>
        {tabs.map((item) => (
          <Pressable key={item.key} style={styles.tabButton} onPress={() => setTab(item.key as Tab)}>
            <Ionicons name={iconForTab(item.key)} size={22} color={tab === item.key ? "#173B35" : "#78817C"} />
            <Text style={[styles.tabLabel, tab === item.key && styles.tabLabelActive]}>{item.label}</Text>
          </Pressable>
        ))}
      </View>
      <DetailSheet
        item={detailItem}
        onClose={() => setDetailItem(null)}
        onOpenJob={(job) => setDetailItem({ kind: "job", item: job })}
        onOpenTask={(task) => setDetailItem({ kind: "task", item: task })}
        lookupJob={lookupJob}
        onChangeStatus={async (status) => {
          if (!detailItem) return;
          if (detailItem.kind === "job") {
            await updateJobStatus(detailItem.item.id, status);
            await loadHome();
            if (tab === "jobs") await loadJobs();
          } else {
            await updateTaskStatus(detailItem.item.id, status);
            await loadHome();
          }
        }}
      />
      <StatusBar style="dark" />
    </Shell>
  );
}

function Shell({ children, title, right }: { children: ReactNode; title?: string; right?: ReactNode }) {
  return (
    <SafeAreaView style={styles.safe}>
      <View style={styles.app}>
        {title && (
          <View style={styles.header}>
            <Text style={styles.title}>{title}</Text>
            {right}
          </View>
        )}
        <View style={styles.content}>{children}</View>
      </View>
    </SafeAreaView>
  );
}

function Summary({
  data,
  onOpenJob,
  onOpenTask
}: {
  data: WorkHome | null;
  onOpenJob: (job: WorkJob) => void;
  onOpenTask: (task: WorkTask) => void;
}) {
  const today = new Date();
  const firstName = data?.user.name?.split(" ")?.[0] || "there";
  const upcomingJobs = useMemo(() => {
    const jobs = [...(data?.assigned_jobs || [])]
      .filter((job) => job.status === "open" || job.status === "scheduled")
      .sort((a, b) => sortJobForHome(a).localeCompare(sortJobForHome(b)));

    return jobs.slice(0, 4);
  }, [data?.assigned_jobs]);
  const tasksToday = useMemo(() => {
    const matchesToday = (data?.due_tasks || []).filter((task) => isSameDay(task.due_at, today));
    return (matchesToday.length ? matchesToday : (data?.due_tasks || [])).slice(0, 4);
  }, [data?.due_tasks]);
  const jobsInProgress = useMemo(
    () => [...(data?.assigned_jobs || [])]
      .filter((job) => job.status === "in_progress")
      .slice(0, 4),
    [data?.assigned_jobs]
  );

  return (
    <View style={styles.summaryStack}>
      <View style={styles.homeHero}>
        <Text style={styles.homeDate}>{formatHomeDate(today)}</Text>
        <Text style={styles.homeGreeting}>{`${timeOfDay(today)}, ${firstName}.`}</Text>
      </View>

      <SectionBlock title="Upcoming jobs" count={upcomingJobs.length}>
        {upcomingJobs.length ? upcomingJobs.map((job, index) => (
          <UpcomingJobCard key={`upcoming-${job.id}-${index}`} job={job} onOpenJob={onOpenJob} onOpenTask={onOpenTask} />
        )) : <Empty label="No upcoming jobs" />}
      </SectionBlock>

      <SectionBlock title="My tasks for today" count={tasksToday.length}>
        {tasksToday.length ? tasksToday.map((task, index) => (
          <CompactTask key={`today-${task.id}-${index}`} title={task.title} meta={task.job_title || shortDate(task.due_at) || "Today"} status={task.status} onPress={() => onOpenTask(task)} />
        )) : <Empty label="No tasks" />}
      </SectionBlock>

      <SectionBlock title="Jobs in Progress" count={jobsInProgress.length}>
        {jobsInProgress.length ? jobsInProgress.map((job, index) => (
          <UpcomingJobCard key={`progress-${job.id}-${index}`} job={job} onOpenJob={onOpenJob} onOpenTask={onOpenTask} />
        )) : <Empty label="No jobs" />}
      </SectionBlock>
    </View>
  );
}

function SectionBlock({ title, count, children }: { title: string; count: number; children: ReactNode }) {
  return (
    <View style={styles.sectionBlock}>
      <View style={styles.sectionHeader}>
        <Text style={styles.sectionTitle}>{title}</Text>
        <View style={styles.sectionPill}>
          <Text style={styles.sectionPillText}>{count}</Text>
        </View>
      </View>
      <View style={styles.sectionList}>{children}</View>
    </View>
  );
}

function CompactJob({ title, meta, status, onPress }: { title: string; meta?: string | null; status: string; onPress: () => void }) {
  return (
    <Pressable style={styles.compactCard} onPress={onPress}>
      <View style={styles.compactCopy}>
        <Text style={styles.compactTitle} numberOfLines={1}>{title}</Text>
        {!!meta && <Text style={styles.compactMeta} numberOfLines={1}>{meta}</Text>}
      </View>
      <Status status={status} />
    </Pressable>
  );
}

function CompactTask({ title, meta, status, onPress }: { title: string; meta?: string | null; status: string; onPress: () => void }) {
  return (
    <Pressable style={styles.compactCard} onPress={onPress}>
      <View style={styles.compactCopy}>
        <Text style={styles.compactTitle} numberOfLines={1}>{title}</Text>
        {!!meta && <Text style={styles.compactMeta} numberOfLines={1}>{meta}</Text>}
      </View>
      <Status status={status} />
    </Pressable>
  );
}

function UpcomingJobCard({
  job,
  onOpenJob,
  onOpenTask
}: {
  job: WorkJob;
  onOpenJob: (job: WorkJob) => void;
  onOpenTask: (task: WorkTask) => void;
}) {
  const taskStages = getJobStages(job);
  const address = jobAddressLine(job);
  const summary = [shortDate(job.scheduled_for ?? null), shortDate(job.completed_at ?? null), job.customer.phone ?? null].filter(Boolean).join(" · ");

  return (
    <Pressable style={styles.projectCard} onPress={() => onOpenJob(job)}>
      <View style={styles.projectTop}>
        <View style={styles.projectCopy}>
          <Text style={styles.rowTitle} numberOfLines={1}>{job.title}</Text>
          {!!address && <Text style={styles.projectMeta} numberOfLines={1}>{address}</Text>}
          {!!summary && <Text style={styles.projectMeta} numberOfLines={1}>{summary}</Text>}
        </View>
        <Status status={job.status} />
      </View>
      <View style={styles.projectStats}>
        <MetaChip label={`${taskStages.length} stages`} />
        {!!job.description && <MetaChip label="notes" />}
        {!!job.metadata && Object.keys(job.metadata).length > 0 && <MetaChip label="site details" />}
      </View>
      {!!taskStages.length && (
        <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.phaseRow}>
          {taskStages.map((task, index) => (
            <Pressable key={`${job.id}-${task.id}`} style={[styles.phaseStep, phaseStyleForTask(task.status, index)]} onPress={() => onOpenTask(task)}>
              <Text style={styles.phaseStepLabel} numberOfLines={1}>{task.title}</Text>
              {!!task.due_at && <Text style={styles.phaseStepDate}>{shortDate(task.due_at)}</Text>}
            </Pressable>
          ))}
        </ScrollView>
      )}
    </Pressable>
  );
}

function JobRow({ job, onPress }: { job: WorkJob; onPress: () => void }) {
  return (
    <Pressable style={styles.card} onPress={onPress}>
      <View style={styles.cardTop}>
        <View style={styles.cardTitleWrap}>
          <Text style={styles.rowTitle} numberOfLines={1}>{job.title}</Text>
          <Text style={styles.cardMeta} numberOfLines={1}>
            {[job.customer.name, jobAddressLine(job), shortDate(job.scheduled_for ?? null)].filter(Boolean).join(" · ")}
          </Text>
        </View>
        <Status status={job.status} />
      </View>
    </Pressable>
  );
}

function TaskRow({ task, onPress }: { task: WorkTask; onPress: () => void }) {
  return (
    <Pressable style={styles.card} onPress={onPress}>
      <View style={styles.cardTop}>
        <View style={styles.cardTitleWrap}>
          <Text style={styles.rowTitle} numberOfLines={1}>{task.title}</Text>
          <Text style={styles.cardMeta} numberOfLines={1}>
            {[task.job_title, shortDate(task.due_at), task.assigned_user?.name].filter(Boolean).join(" · ")}
          </Text>
        </View>
        <Status status={task.status} />
      </View>
    </Pressable>
  );
}

function StatusStrip({ current, onPress }: { current: string; onPress: (status: string) => Promise<void> }) {
  return (
    <View style={styles.statusStrip}>
      {statuses.map((status) => (
        <Pressable key={status} onPress={() => onPress(status)} style={[styles.statusButton, current === status && styles.statusButtonActive]}>
          <Text style={[styles.statusButtonText, current === status && styles.statusButtonTextActive]}>{statusLabel(status)}</Text>
        </Pressable>
      ))}
    </View>
  );
}

function Row({ title, meta, right, onPress }: { title: string; meta?: string; right?: React.ReactNode; onPress?: () => void }) {
  const initials = title
    .split(" ")
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase())
    .join("");
  return (
    <Pressable style={styles.row} onPress={onPress}>
      <View style={styles.rowLeft}>
        <View style={styles.avatar}>
          <Text style={styles.avatarText}>{initials || "E"}</Text>
        </View>
        <View style={styles.rowCopy}>
          <Text style={styles.rowTitle}>{title}</Text>
          {!!meta && <Text style={styles.meta}>{meta}</Text>}
        </View>
      </View>
      {right}
    </Pressable>
  );
}

function Status({ status }: { status: string }) {
  return (
    <View style={[styles.status, status === "blocked" && styles.statusBlocked, status === "done" && styles.statusDone]}>
      <Text style={styles.statusText}>{statusLabel(status)}</Text>
    </View>
  );
}

function Badge({ count }: { count: number }) {
  if (!count) return null;
  return (
    <View style={styles.badge}>
      <Text style={styles.badgeText}>{count}</Text>
    </View>
  );
}

function Empty({ label }: { label: string }) {
  return <Text style={styles.empty}>{label}</Text>;
}

function DetailSheet({
  item,
  onClose,
  onChangeStatus,
  onOpenJob,
  onOpenTask,
  lookupJob
}: {
  item: DetailItem | null;
  onClose: () => void;
  onChangeStatus: (status: string) => Promise<void>;
  onOpenJob: (job: WorkJob) => void;
  onOpenTask: (task: WorkTask) => void;
  lookupJob: (jobId: number) => WorkJob | undefined;
}) {
  if (!item) return null;

  const [comments, setComments] = useState<WorkComment[]>([]);
  const [activity, setActivity] = useState<WorkActivity[]>([]);
  const [body, setBody] = useState("");
  const [loading, setLoading] = useState(true);
  const [sending, setSending] = useState(false);

  const title = item.kind === "job" ? item.item.title : item.item.title;
  const subtitle =
    item.kind === "job"
      ? item.item.customer.name || item.item.customer.phone || "Job"
      : item.item.job_title || "Task";
  const parentJob = item.kind === "task" ? lookupJob(item.item.job_id) : null;
  const commentThread = comments.length ? comments : loading ? [] : demoCommentsFor(item);
  const activityThread = activity.length ? activity : loading ? [] : demoActivityFor(item);
  const photos = item.kind === "job" ? item.item.photos || [] : [];
  const metadata = item.kind === "job" ? item.item.metadata || {} : {};

  useEffect(() => {
    let active = true;
    setLoading(true);
    setBody("");
    (async () => {
      try {
        const [commentPayload, activityPayload] = await Promise.all([
          item.kind === "job" ? jobComments(item.item.id) : taskComments(item.item.id),
          item.kind === "job" ? jobActivity(item.item.id) : taskActivity(item.item.id)
        ]);
        if (!active) return;
        setComments(commentPayload.comments || []);
        setActivity(activityPayload.activity || []);
      } catch {
        if (!active) return;
        setComments([]);
        setActivity([]);
      } finally {
        if (active) setLoading(false);
      }
    })();
    return () => {
      active = false;
    };
  }, [item]);

  const submitComment = useCallback(async () => {
    const trimmed = body.trim();
    if (!trimmed) return;
    setSending(true);
    try {
      if (item.kind === "job") {
        const payload = await storeJobComment(item.item.id, trimmed);
        setComments((current) => [...current, payload.comment]);
      } else {
        const payload = await storeTaskComment(item.item.id, trimmed);
        setComments((current) => [...current, payload.comment]);
      }
      setBody("");
    } finally {
      setSending(false);
    }
  }, [body, item]);

  return (
    <Modal transparent animationType="fade" visible onRequestClose={onClose}>
      <View style={styles.sheetBackdrop}>
        <Pressable style={styles.sheetScrim} onPress={onClose} />
        <KeyboardAvoidingView behavior={Platform.OS === "ios" ? "padding" : undefined} style={styles.sheetWrap}>
          <View style={styles.sheet}>
            <View style={styles.sheetHandle} />
            <View style={styles.sheetHeader}>
              <View style={styles.sheetHeaderCopy}>
                <Text style={styles.sheetTitle} numberOfLines={1}>{title}</Text>
                <Text style={styles.sheetSubtitle} numberOfLines={1}>{subtitle}</Text>
              </View>
              <Pressable onPress={onClose} style={styles.sheetClose}>
                <Ionicons name="close" size={18} color="#173B35" />
              </Pressable>
            </View>

            <ScrollView showsVerticalScrollIndicator={false} contentContainerStyle={styles.sheetScroll}>
              <View style={styles.sheetMetaRow}>
                {item.kind === "job" && !!item.item.scheduled_for && <MetaChip label={formatDetailDate(item.item.scheduled_for)} />}
                {item.kind === "job" && !!item.item.completed_at && <MetaChip label={`done ${formatDetailDate(item.item.completed_at)}`} />}
                {item.kind === "task" && !!item.item.due_at && <MetaChip label={formatDetailDate(item.item.due_at)} />}
                {item.kind === "job" && !!item.item.customer.phone && (
                  <Pressable style={styles.metaAction} onPress={() => Linking.openURL(`tel:${stripPhone(item.item.customer.phone || "")}`)}>
                    <Ionicons name="call-outline" size={14} color="#173B35" />
                    <Text style={styles.metaActionText}>Call</Text>
                  </Pressable>
                )}
                {item.kind === "job" && !!jobMapsUrl(item.item) && (
                  <Pressable style={styles.metaAction} onPress={() => {
                    const url = jobMapsUrl(item.item);
                    if (url) Linking.openURL(url);
                  }}>
                    <Ionicons name="map-outline" size={14} color="#173B35" />
                    <Text style={styles.metaActionText}>Maps</Text>
                  </Pressable>
                )}
              </View>

              {item.kind === "job" && (
                <>
                  <View style={styles.infoBand}>
                    {!!item.item.customer.phone && (
                      <Text style={styles.infoText} numberOfLines={1}>{`Phone ${item.item.customer.phone}`}</Text>
                    )}
                    {!!jobAddressLine(item.item) && <Text style={styles.infoText} numberOfLines={2}>{jobAddressLine(item.item)}</Text>}
                    {!!jobNotes(item.item) && <Text style={styles.infoText} numberOfLines={3}>{jobNotes(item.item)}</Text>}
                    {!!jobLockBoxCode(item.item) && <Text style={styles.infoText} numberOfLines={1}>{`Lock box ${jobLockBoxCode(item.item)}`}</Text>}
                  </View>

                  {!!jobStages(item.item).length && (
                    <View style={styles.sectionStack}>
                      <Text style={styles.sheetSectionTitle}>Stages</Text>
                      <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.phaseRow}>
                        {jobStages(item.item).map((task, index) => (
                          <Pressable
                            key={`stage-${task.id}`}
                            style={[styles.phaseStep, phaseStyleForTask(task.status, index)]}
                            onPress={() => onOpenTask(task)}
                          >
                            <Text style={styles.phaseStepLabel} numberOfLines={1}>{task.title}</Text>
                            {!!task.due_at && <Text style={styles.phaseStepDate}>{shortDate(task.due_at)}</Text>}
                          </Pressable>
                        ))}
                      </ScrollView>
                    </View>
                  )}

                  {!!photos.length && (
                    <View style={styles.sectionStack}>
                      <Text style={styles.sheetSectionTitle}>Photos</Text>
                      <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.photoRow}>
                        {photos.map((photo) => (
                          <View key={photo.id} style={styles.photoCard}>
                            <Ionicons name="images-outline" size={18} color="#173B35" />
                            <Text style={styles.photoCaption} numberOfLines={2}>{photo.caption || "iCloud photo"}</Text>
                          </View>
                        ))}
                      </ScrollView>
                    </View>
                  )}

                  {!!Object.keys(metadata).length && (
                    <View style={styles.sectionStack}>
                      <Text style={styles.sheetSectionTitle}>Notes</Text>
                      <View style={styles.infoBand}>
                        {!!jobLockBoxCode(item.item) && <Text style={styles.infoText} numberOfLines={1}>{`Lock box ${jobLockBoxCode(item.item)}`}</Text>}
                      </View>
                    </View>
                  )}
                </>
              )}

              {item.kind === "task" && parentJob && (
                <Pressable style={styles.parentJobCard} onPress={() => onOpenJob(parentJob)}>
                  <Text style={styles.parentJobLabel}>Job</Text>
                  <Text style={styles.parentJobTitle} numberOfLines={1}>{parentJob.title}</Text>
                </Pressable>
              )}

              <View style={styles.sectionStack}>
                <View style={styles.sheetSectionHeader}>
                  <Text style={styles.sheetSectionTitle}>Comments</Text>
                  {loading && <ActivityIndicator size="small" color="#173B35" />}
                </View>
                {commentThread.length ? commentThread.map((comment) => (
                  <View key={comment.id} style={styles.commentCard}>
                    <View style={styles.commentAvatar}>
                      <Text style={styles.commentAvatarText}>{comment.user?.name?.split(" ")?.[0]?.[0]?.toUpperCase() || "E"}</Text>
                    </View>
                    <View style={styles.commentBody}>
                      <Text style={styles.commentAuthor}>{comment.user?.name || "Everbranch"}</Text>
                      <Text style={styles.commentText}>{comment.body}</Text>
                    </View>
                  </View>
                )) : <Empty label="No comments" />}
              </View>

              <View style={styles.sectionStack}>
                <Text style={styles.sheetSectionTitle}>Add comment</Text>
                <TextInput
                  style={styles.commentInput}
                  multiline
                  placeholder="Comment"
                  placeholderTextColor="#8A908A"
                  value={body}
                  onChangeText={setBody}
                />
                <Pressable style={[styles.primaryButton, sending && styles.primaryButtonDisabled]} onPress={submitComment} disabled={sending}>
                  <Text style={styles.primaryButtonText}>{sending ? "Sending" : "Add comment"}</Text>
                </Pressable>
              </View>

              {!!activityThread.length && (
                <View style={styles.sectionStack}>
                  <Text style={styles.sheetSectionTitle}>Activity</Text>
                  {activityThread.slice(0, 3).map((entry) => (
                    <View key={entry.id} style={styles.activityRow}>
                      <Text style={styles.activityText} numberOfLines={2}>{entry.title}</Text>
                    </View>
                  ))}
                </View>
              )}
            </ScrollView>

            <View style={styles.sheetActions}>
              <StatusStrip current={item.item.status} onPress={onChangeStatus} />
            </View>
          </View>
        </KeyboardAvoidingView>
      </View>
    </Modal>
  );
}

function formatHomeDate(date: Date) {
  return date.toLocaleDateString(undefined, { weekday: "short", month: "long", day: "numeric" });
}

function timeOfDay(date: Date) {
  const hour = date.getHours();
  if (hour < 12) return "Good morning";
  if (hour < 18) return "Good afternoon";
  return "Good evening";
}

function isSameDay(value: string | null, date: Date) {
  if (!value) return false;
  const parsed = new Date(value);
  return parsed.getFullYear() === date.getFullYear()
    && parsed.getMonth() === date.getMonth()
    && parsed.getDate() === date.getDate();
}

function sortJobForHome(job: WorkJob) {
  return `${job.scheduled_for ? localDayKey(new Date(job.scheduled_for)) : "9999-12-31"}-${job.title}`;
}

async function registerForPush() {
  const permissions = await Notifications.requestPermissionsAsync();
  if (!permissions.granted) return;
  const projectId = Constants.expoConfig?.extra?.eas?.projectId;
  const token = await Notifications.getExpoPushTokenAsync(projectId ? { projectId } : undefined);
  await registerPushDevice({
    platform: Platform.OS === "android" ? "android" : "ios",
    device_token: token.data,
    authorization_status: permissions.status,
    app_version: Constants.expoConfig?.version
  });
}

function tokenFromUrl(url: string | null) {
  if (!url) return null;
  const parsed = Linking.parse(url);
  const token = parsed.queryParams?.token;
  return typeof token === "string" ? token : null;
}

function shortDate(value: string | null) {
  if (!value) return null;
  return new Date(value).toLocaleDateString(undefined, { month: "short", day: "numeric" });
}

function statusLabel(status: string) {
  return status.replace(/_/g, " ");
}

function itemLabel(value: "day" | "list" | "week") {
  return value[0].toUpperCase() + value.slice(1);
}

function MetaChip({ label }: { label: string }) {
  return (
    <View style={styles.metaChip}>
      <Text style={styles.metaChipText}>{label}</Text>
    </View>
  );
}

function jobStages(job: WorkJob) {
  return [...(job.tasks || [])]
    .sort((a, b) => {
      const aOrder = a.sort_order || 0;
      const bOrder = b.sort_order || 0;
      if (aOrder !== bOrder) return aOrder - bOrder;
      return (a.due_at || "").localeCompare(b.due_at || "");
    })
    .slice(0, 6);
}

function getJobStages(job: WorkJob) {
  return jobStages(job);
}

function jobAddressLine(job: WorkJob) {
  const address = job.service_address;
  if (!address) return null;
  return [address.line_1, address.line_2, [address.city, address.state, address.postal_code].filter(Boolean).join(" ")].filter(Boolean).join(", ");
}

function jobNotes(job: WorkJob) {
  return typeof job.description === "string" && job.description.trim() ? job.description.trim() : null;
}

function jobLockBoxCode(job: WorkJob) {
  const value = job.metadata && typeof job.metadata === "object" ? job.metadata['lock_box_code'] : null;
  return typeof value === "string" && value.trim() ? value.trim() : null;
}

function jobMapsUrl(job: WorkJob) {
  const address = jobAddressLine(job);
  if (!address) return null;
  return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(address)}`;
}

function stripPhone(value: string) {
  return value.replace(/[^0-9+]/g, "");
}

function formatDetailDate(value: string | null) {
  if (!value) return "";
  return new Date(value).toLocaleDateString(undefined, { month: "short", day: "numeric" });
}

function phaseStyleForTask(status: string, index: number) {
  if (status === "done") return { backgroundColor: "#DCEBC8" };
  if (status === "blocked") return { backgroundColor: "#F3D5CD" };
  if (status === "in_progress") return { backgroundColor: index % 2 === 0 ? "#D8E7D9" : "#BDD7B6" };
  return { backgroundColor: index % 2 === 0 ? "#E9ECE4" : "#D9E4DB" };
}

function demoCommentsFor(item: DetailItem): WorkComment[] {
  const base = item.kind === "job" ? item.item.title : item.item.title;
  return [
    {
      id: -201,
      item_type: item.kind === "job" ? "field_service_job" : "field_service_task",
      item_id: item.item.id,
      body: `Carlos checked the ${base.toLowerCase()} notes.`,
      mentioned_user_ids: [],
      created_at: new Date().toISOString(),
      user: { id: -101, name: "Carlos", email: "carlos@demo.local" }
    },
    {
      id: -202,
      item_type: item.kind === "job" ? "field_service_job" : "field_service_task",
      item_id: item.item.id,
      body: `Andrew said the crew is clear to continue.`,
      mentioned_user_ids: [],
      created_at: new Date().toISOString(),
      user: { id: -102, name: "Andrew", email: "andrew@demo.local" }
    },
    {
      id: -203,
      item_type: item.kind === "job" ? "field_service_job" : "field_service_task",
      item_id: item.item.id,
      body: `Neal marked this as ready for the next phase.`,
      mentioned_user_ids: [],
      created_at: new Date().toISOString(),
      user: { id: -103, name: "Neal", email: "neal@demo.local" }
    }
  ];
}

function demoActivityFor(item: DetailItem): WorkActivity[] {
  return [
    {
      id: -301,
      item_type: item.kind === "job" ? "field_service_job" : "field_service_task",
      item_id: item.item.id,
      event_type: "created",
      title: `${item.kind === "job" ? "Job" : "Task"} opened`,
      body: null,
      metadata: {},
      created_at: new Date().toISOString(),
      actor: { id: -101, name: "Carlos", email: "carlos@demo.local" }
    }
  ];
}

function buildWeekDays(jobs: WorkJob[]) {
  const today = new Date();
  const dayIndex = today.getDay();
  const start = new Date(today);
  start.setDate(today.getDate() - ((dayIndex + 6) % 7));

  const jobDays = new Map<string, number>();
  jobs.forEach((job) => {
    const key = dayKey(job.scheduled_for);
    jobDays.set(key, (jobDays.get(key) || 0) + 1);
  });

  return Array.from({ length: 7 }, (_, index) => {
    const current = new Date(start);
    current.setDate(start.getDate() + index);
    const day = current.toLocaleDateString(undefined, { weekday: "short" }).slice(0, 1);
    const number = current.getDate();
    const label = current.toLocaleDateString(undefined, { month: "short", day: "numeric" });
    const active = current.toDateString() === today.toDateString();
    return {
      key: localDayKey(current),
      day,
      number,
      label,
      active,
      count: jobDays.get(localDayKey(current)) || 0
    };
  });
}

function dayKey(value: string | null) {
  if (!value) return "";
  return localDayKey(new Date(value));
}

function localDayKey(date: Date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
}

function iconForTab(key: string) {
  if (key === "jobs") return "briefcase-outline";
  if (key === "team") return "people-outline";
  return "home-outline";
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: "#F7F5EF" },
  app: { flex: 1, backgroundColor: "#F7F5EF" },
  content: { flex: 1, backgroundColor: "#F7F5EF" },
  flex: { flex: 1 },
  header: {
    minHeight: 58,
    paddingHorizontal: 18,
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: "#DDD8CC"
  },
  title: { fontSize: 24, fontWeight: "700", color: "#173B35" },
  logo: { fontSize: 28, fontWeight: "800", color: "#173B35", marginBottom: 26 },
  login: { flex: 1, justifyContent: "center", padding: 24 },
  input: {
    height: 48,
    borderWidth: 1,
    borderColor: "#CCC6B8",
    backgroundColor: "#FFFDF7",
    borderRadius: 8,
    paddingHorizontal: 14,
    fontSize: 16,
    marginBottom: 12
  },
  search: {
    flex: 1,
    height: 44,
    paddingHorizontal: 2,
    fontSize: 15,
    color: "#173B35"
  },
  primaryButton: { height: 48, borderRadius: 12, backgroundColor: "#173B35", alignItems: "center", justifyContent: "center" },
  primaryButtonText: { color: "#FFFFFF", fontSize: 16, fontWeight: "700" },
  apiBase: { color: "#8B8A82", fontSize: 12, marginTop: 20, textAlign: "center" },
  message: { color: "#173B35", marginTop: 12, textAlign: "center", fontWeight: "600" },
  toast: {
    position: "absolute",
    bottom: 78,
    alignSelf: "center",
    backgroundColor: "#173B35",
    color: "#FFFFFF",
    paddingHorizontal: 14,
    paddingVertical: 8,
    borderRadius: 8,
    overflow: "hidden"
  },
  tabs: {
    height: 66,
    flexDirection: "row",
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: "#DDD8CC",
    backgroundColor: "#FFFDF7"
  },
  tabButton: { flex: 1, alignItems: "center", justifyContent: "center", gap: 3, paddingTop: 2 },
  tabLabel: { fontSize: 11, color: "#78817C", fontWeight: "700" },
  tabLabelActive: { color: "#173B35" },
  feedContent: { paddingTop: 8, paddingBottom: 24 },
  listContent: { paddingTop: 10, paddingBottom: 20 },
  homeHero: {
    marginHorizontal: 14,
    marginTop: 8,
    marginBottom: 14,
    padding: 18,
    borderRadius: 28,
    backgroundColor: "#173B35",
    shadowColor: "#0A1E1B",
    shadowOpacity: 0.16,
    shadowRadius: 14,
    shadowOffset: { width: 0, height: 8 },
    elevation: 4
  },
  homeDate: { color: "#D3DDD8", fontSize: 13, fontWeight: "700" },
  homeGreeting: { color: "#FFFFFF", fontSize: 30, lineHeight: 34, fontWeight: "800", marginTop: 4 },
  summaryStack: { gap: 12 },
  sectionBlock: {
    marginHorizontal: 14,
    padding: 14,
    borderRadius: 22,
    backgroundColor: "#FFFDF7",
    borderWidth: 1,
    borderColor: "#E5DFD2",
    marginBottom: 10
  },
  sectionHeader: { flexDirection: "row", alignItems: "center", justifyContent: "space-between", marginBottom: 10 },
  sectionTitle: { color: "#173B35", fontSize: 16, fontWeight: "800" },
  sectionPill: { minWidth: 28, height: 24, paddingHorizontal: 8, borderRadius: 999, backgroundColor: "#E9ECE4", alignItems: "center", justifyContent: "center" },
  sectionPillText: { color: "#173B35", fontSize: 12, fontWeight: "800" },
  sectionList: { gap: 8 },
  compactCard: {
    minHeight: 52,
    borderRadius: 16,
    borderWidth: 1,
    borderColor: "#E8E2D5",
    backgroundColor: "#FFF",
    paddingHorizontal: 14,
    paddingVertical: 12,
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between"
  },
  compactCopy: { flex: 1, paddingRight: 10, gap: 2 },
  compactTitle: { color: "#173B35", fontSize: 15, fontWeight: "700" },
  compactMeta: { color: "#6E756F", fontSize: 12, fontWeight: "600" },
  projectCard: {
    borderRadius: 18,
    backgroundColor: "#FFF",
    borderWidth: 1,
    borderColor: "#E7E0D3",
    padding: 14,
    gap: 10
  },
  projectTop: { flexDirection: "row", alignItems: "flex-start", justifyContent: "space-between", gap: 12 },
  projectCopy: { flex: 1, gap: 4 },
  projectMeta: { color: "#6E756F", fontSize: 12, fontWeight: "600", lineHeight: 16 },
  projectStats: { flexDirection: "row", flexWrap: "wrap", gap: 6 },
  phaseRow: { gap: 8, paddingRight: 4 },
  phaseStep: {
    width: 132,
    borderRadius: 14,
    paddingHorizontal: 10,
    paddingVertical: 10,
    gap: 4,
    borderWidth: 1,
    borderColor: "#DAD4C8"
  },
  phaseStepLabel: { color: "#173B35", fontSize: 13, fontWeight: "800" },
  phaseStepDate: { color: "#173B35", fontSize: 11, fontWeight: "600" },
  metaChip: { paddingHorizontal: 10, paddingVertical: 6, borderRadius: 999, backgroundColor: "#EEF1E8" },
  metaChipText: { color: "#173B35", fontSize: 11, fontWeight: "800" },
  jobsTop: { paddingHorizontal: 14, paddingTop: 10, paddingBottom: 10, gap: 12 },
  segmentedControl: {
    flexDirection: "row",
    padding: 4,
    borderRadius: 16,
    backgroundColor: "#E8E2D4"
  },
  segment: { flex: 1, minHeight: 40, borderRadius: 12, alignItems: "center", justifyContent: "center" },
  segmentActive: { backgroundColor: "#173B35" },
  segmentText: { color: "#6E756F", fontSize: 13, fontWeight: "800" },
  segmentTextActive: { color: "#FFFFFF" },
  scheduleStrip: { flexDirection: "row", gap: 8 },
  dayTile: {
    flex: 1,
    minHeight: 70,
    paddingVertical: 9,
    paddingHorizontal: 8,
    borderRadius: 16,
    backgroundColor: "#FFFDF7",
    borderWidth: 1,
    borderColor: "#E3DDCF",
    alignItems: "center",
    justifyContent: "center"
  },
  dayTileActive: { backgroundColor: "#173B35", borderColor: "#173B35" },
  dayLabel: { color: "#737B75", fontSize: 11, fontWeight: "800" },
  dayLabelActive: { color: "#D9E4DB" },
  dayNumber: { color: "#173B35", fontSize: 16, fontWeight: "800", marginTop: 2 },
  dayNumberActive: { color: "#FFFFFF" },
  dayCount: { color: "#6A7A72", fontSize: 10, fontWeight: "700", marginTop: 3 },
  dayCountActive: { color: "#BFD4C6" },
  searchShell: {
    height: 48,
    flexDirection: "row",
    alignItems: "center",
    gap: 8,
    paddingHorizontal: 14,
    borderRadius: 16,
    borderWidth: 1,
    borderColor: "#DDD7C8",
    backgroundColor: "#FFFDF7"
  },
  card: {
    marginHorizontal: 14,
    marginBottom: 10,
    padding: 14,
    borderRadius: 18,
    backgroundColor: "#FFFDF7",
    borderWidth: 1,
    borderColor: "#E5DFD2"
  },
  cardTop: { flexDirection: "row", alignItems: "flex-start", justifyContent: "space-between", gap: 12 },
  cardTitleWrap: { flex: 1, gap: 5 },
  cardMeta: { color: "#6E756F", fontSize: 12, fontWeight: "500", lineHeight: 16 },
  row: {
    minHeight: 68,
    paddingHorizontal: 16,
    paddingVertical: 12,
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: "#DDD8CC",
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
    backgroundColor: "#FFFDF7"
  },
  rowLeft: { flex: 1, flexDirection: "row", alignItems: "center", gap: 12, paddingRight: 10 },
  rowCopy: { flex: 1, gap: 2 },
  avatar: {
    width: 40,
    height: 40,
    borderRadius: 14,
    backgroundColor: "#D9E7D2",
    alignItems: "center",
    justifyContent: "center"
  },
  avatarText: { color: "#173B35", fontSize: 14, fontWeight: "800" },
  rowTitle: { flex: 1, fontSize: 16, color: "#173B35", fontWeight: "800", lineHeight: 20 },
  meta: { color: "#6E756F", fontSize: 12, fontWeight: "500" },
  status: { paddingHorizontal: 9, paddingVertical: 5, backgroundColor: "#E9ECE4", borderRadius: 999 },
  statusBlocked: { backgroundColor: "#F3D5CD" },
  statusDone: { backgroundColor: "#DCEBC8" },
  statusText: { color: "#173B35", fontSize: 11, fontWeight: "800", textTransform: "capitalize" },
  statusStrip: { flexDirection: "row", flexWrap: "wrap", gap: 6, marginTop: 12 },
  statusButton: { paddingHorizontal: 10, paddingVertical: 7, borderRadius: 999, backgroundColor: "#F1EEE5" },
  statusButtonActive: { backgroundColor: "#173B35" },
  statusButtonText: { fontSize: 11, fontWeight: "700", color: "#173B35", textTransform: "capitalize" },
  statusButtonTextActive: { color: "#FFFFFF" },
  badge: { minWidth: 26, height: 26, borderRadius: 13, backgroundColor: "#D9F08C", alignItems: "center", justifyContent: "center", paddingHorizontal: 8 },
  badgeText: { color: "#173B35", fontWeight: "900" },
  teamLoading: { paddingVertical: 24 },
  sheetBackdrop: { flex: 1, justifyContent: "flex-end", backgroundColor: "rgba(15, 20, 18, 0.42)" },
  sheetScrim: { ...StyleSheet.absoluteFill },
  sheetWrap: { width: "100%", maxHeight: "92%" },
  sheet: {
    flexGrow: 0,
    backgroundColor: "#FFFDF7",
    borderTopLeftRadius: 28,
    borderTopRightRadius: 28,
    padding: 18,
    borderTopWidth: 1,
    borderColor: "#E5DFD2"
  },
  sheetHandle: {
    width: 46,
    height: 5,
    borderRadius: 999,
    backgroundColor: "#D5CFC0",
    alignSelf: "center",
    marginBottom: 14
  },
  sheetHeader: { flexDirection: "row", alignItems: "flex-start", justifyContent: "space-between", gap: 12 },
  sheetHeaderCopy: { flex: 1, gap: 3 },
  sheetTitle: { color: "#173B35", fontSize: 22, lineHeight: 26, fontWeight: "800" },
  sheetSubtitle: { color: "#6E756F", fontSize: 13, fontWeight: "600" },
  sheetClose: { width: 32, height: 32, borderRadius: 16, backgroundColor: "#E9ECE4", alignItems: "center", justifyContent: "center" },
  sheetScroll: { gap: 14, paddingBottom: 10 },
  sheetMetaRow: { flexDirection: "row", flexWrap: "wrap", gap: 8 },
  metaAction: {
    flexDirection: "row",
    alignItems: "center",
    gap: 6,
    borderRadius: 999,
    backgroundColor: "#EDF2EA",
    paddingHorizontal: 10,
    paddingVertical: 6
  },
  metaActionText: { color: "#173B35", fontSize: 11, fontWeight: "800" },
  infoBand: { gap: 6, padding: 12, borderRadius: 16, backgroundColor: "#F3F1EA" },
  infoText: { color: "#173B35", fontSize: 13, lineHeight: 18, fontWeight: "600" },
  sectionStack: { gap: 8 },
  sheetSectionHeader: { flexDirection: "row", alignItems: "center", justifyContent: "space-between" },
  sheetSectionTitle: { color: "#173B35", fontSize: 14, fontWeight: "800" },
  photoRow: { gap: 8, paddingRight: 4 },
  photoCard: { width: 132, minHeight: 96, borderRadius: 14, backgroundColor: "#EEF1E8", padding: 12, gap: 8 },
  photoCaption: { color: "#173B35", fontSize: 12, fontWeight: "700", lineHeight: 16 },
  parentJobCard: {
    padding: 12,
    borderRadius: 16,
    backgroundColor: "#E8ECE4",
    gap: 4
  },
  parentJobLabel: { color: "#6E756F", fontSize: 11, fontWeight: "800", textTransform: "uppercase" },
  parentJobTitle: { color: "#173B35", fontSize: 15, fontWeight: "800" },
  commentCard: {
    flexDirection: "row",
    gap: 10,
    padding: 12,
    borderRadius: 14,
    backgroundColor: "#F3F1EA"
  },
  commentAvatar: {
    width: 34,
    height: 34,
    borderRadius: 12,
    backgroundColor: "#D9E7D2",
    alignItems: "center",
    justifyContent: "center"
  },
  commentAvatarText: { color: "#173B35", fontSize: 12, fontWeight: "800" },
  commentBody: { flex: 1, gap: 3 },
  commentAuthor: { color: "#173B35", fontSize: 13, fontWeight: "800" },
  commentText: { color: "#173B35", fontSize: 13, lineHeight: 18, fontWeight: "500" },
  commentInput: {
    minHeight: 88,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: "#DDD7C8",
    backgroundColor: "#FFF",
    padding: 12,
    color: "#173B35",
    textAlignVertical: "top"
  },
  primaryButtonDisabled: { opacity: 0.7 },
  activityRow: { padding: 10, borderRadius: 12, backgroundColor: "#F3F1EA" },
  activityText: { color: "#173B35", fontSize: 12, fontWeight: "700", lineHeight: 16 },
  sheetList: { marginTop: 14, gap: 8 },
  sheetTaskRow: { minHeight: 44, flexDirection: "row", alignItems: "center", gap: 10, paddingHorizontal: 12, borderRadius: 14, backgroundColor: "#F3F1EA" },
  sheetTaskTitle: { flex: 1, color: "#173B35", fontSize: 14, fontWeight: "700" },
  sheetActions: { marginTop: 14 },
  empty: { textAlign: "center", color: "#78817C", marginTop: 32, fontWeight: "700" }
});
