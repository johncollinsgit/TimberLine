import {
  useEffect,
  useMemo,
  useRef,
  useState,
} from "react";
import { createPortal } from "react-dom";
import { Command } from "cmdk";
import {
  buildQueryTelemetry,
  trackCommandMenuEvent,
} from "./commandMenuTelemetry.js";
import { normalizeText } from "./queryNormalization.js";
import {
  SECTION_TITLES,
  rememberRecentAction,
  useActionSearch,
} from "./useActionSearch.js";

const COMMAND_PANEL_ID = "shopify-global-command-menu-panel";

function safeTarget(element) {
  return element instanceof HTMLElement ? element : null;
}

function badgeForItem(item) {
  if (item.section === "pages") {
    return {
      label: "Page",
      className: "border-sky-200 bg-sky-50 text-sky-700",
    };
  }

  if (item.section === "current-view") {
    return {
      label: "Current",
      className: "border-emerald-200 bg-emerald-50 text-emerald-700",
    };
  }

  if (item.section === "recent") {
    return {
      label: "Recent",
      className: "border-zinc-300 bg-zinc-100 text-zinc-700",
    };
  }

  return {
    label: "Action",
    className: "border-violet-200 bg-violet-50 text-violet-700",
  };
}

function highlightText(text, query) {
  const source = String(text || "");
  const tokens = normalizeText(query)
    .split(" ")
    .filter((token) => token.length >= 2)
    .slice(0, 3);

  if (source === "" || tokens.length === 0) {
    return source;
  }

  const lower = source.toLowerCase();
  const firstToken = tokens.find((token) => lower.includes(token));
  if (!firstToken) {
    return source;
  }

  const start = lower.indexOf(firstToken);
  const end = start + firstToken.length;
  return (
    <>
      {source.slice(0, start)}
      <mark className="rounded bg-amber-100 px-0.5 text-zinc-900">{source.slice(start, end)}</mark>
      {source.slice(end)}
    </>
  );
}

export function GlobalCommandMenu({ placeholder, contextLabel, baseQuery }) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  const [refreshToken, setRefreshToken] = useState(0);
  const [pendingSubmit, setPendingSubmit] = useState(null);
  const openRef = useRef(false);
  const selectedDuringSessionRef = useRef(false);
  const zeroResultTrackedRef = useRef("");
  const panelRef = useRef(null);

  const {
    groups,
    results,
    queryContext,
  } = useActionSearch({
    query,
    refreshToken,
    baseQuery,
  });

  const resultEntries = useMemo(
    () => results.map((document, position) => ({ document, position })),
    [results]
  );

  const resultEntryById = useMemo(() => {
    const lookup = new Map();
    resultEntries.forEach((entry) => {
      lookup.set(entry.document.id, entry);
    });

    return lookup;
  }, [resultEntries]);

  const hasResults = resultEntries.length > 0;
  const topResultEntry = resultEntries[0] || null;

  useEffect(() => {
    openRef.current = open;
  }, [open]);

  useEffect(() => {
    const expanded = open ? "true" : "false";
    document.querySelectorAll("[data-command-field], [data-command-trigger]").forEach((element) => {
      if (!(element instanceof HTMLElement)) {
        return;
      }

      element.setAttribute("aria-expanded", expanded);
      element.setAttribute("aria-controls", COMMAND_PANEL_ID);
      element.setAttribute("aria-haspopup", "dialog");
    });
  }, [open]);

  const heading = useMemo(() => {
    const label = String(contextLabel || "").trim();
    return label === "" ? "Backstage" : label;
  }, [contextLabel]);

  const openMenu = (nextQuery = "", {
    focus = true,
    refresh = false,
    source = "unknown",
  } = {}) => {
    const alreadyOpen = openRef.current;
    if (!alreadyOpen || refresh) {
      setRefreshToken((value) => value + 1);
    }

    if (!alreadyOpen) {
      selectedDuringSessionRef.current = false;
      trackCommandMenuEvent("command_menu_opened", {
        source,
        ...buildQueryTelemetry(nextQuery),
      });
    }

    setOpen(true);
    setQuery(String(nextQuery || ""));

    if (focus) {
      window.requestAnimationFrame(() => {
        const input = document.querySelector("[data-shopify-command-input]");
        if (input instanceof HTMLInputElement) {
          input.focus();
          input.setSelectionRange(input.value.length, input.value.length);
        }
      });
    }
  };

  const closeMenu = (reason = "dismiss") => {
    const queryMeta = buildQueryTelemetry(query);
    if (!selectedDuringSessionRef.current && queryMeta.queryLength > 0) {
      trackCommandMenuEvent("command_menu_query_abandoned", {
        reason,
        ...queryMeta,
      });
    }

    setPendingSubmit(null);
    setOpen(false);
  };

  const executeDocument = (document, meta = {}) => {
    if (!document || typeof document.execute !== "function") {
      return;
    }

    selectedDuringSessionRef.current = true;
    const queryMeta = buildQueryTelemetry(meta.query ?? query);
    trackCommandMenuEvent("command_menu_result_selected", {
      resultId: document.id,
      section: document.section,
      entityType: document.entityType,
      source: document.source || "unknown",
      rankPosition: Number.isFinite(meta.position) ? meta.position : null,
      ...queryMeta,
    });

    Promise.resolve(document.execute())
      .then(() => {
        rememberRecentAction(document);
      })
      .catch(() => {
        trackCommandMenuEvent("command_menu_action_execution_failed", {
          resultId: document.id,
          section: document.section,
          entityType: document.entityType,
          ...queryMeta,
        });
      })
      .finally(() => {
        closeMenu("selection");
      });
  };

  const executeHighlightedDocument = ({ source = "unknown", queryOverride } = {}) => {
    let selectedEntry = topResultEntry;
    const panel = panelRef.current;
    if (panel instanceof HTMLElement) {
      const selectedItem = panel.querySelector('[cmdk-item][aria-selected="true"][data-result-id]');
      if (selectedItem instanceof HTMLElement) {
        const selectedId = String(selectedItem.dataset.resultId || "").trim();
        if (selectedId !== "" && resultEntryById.has(selectedId)) {
          selectedEntry = resultEntryById.get(selectedId) || selectedEntry;
        }
      }
    }

    const effectiveQuery = queryOverride ?? query;

    if (!selectedEntry?.document) {
      trackCommandMenuEvent("command_menu_submit_no_results", {
        source,
        ...buildQueryTelemetry(effectiveQuery),
      });
      return false;
    }

    executeDocument(selectedEntry.document, {
      position: selectedEntry.position,
      query: effectiveQuery,
      source,
    });

    return true;
  };

  useEffect(() => {
    const handleGlobalKeydown = (event) => {
      const key = String(event.key || "").toLowerCase();
      const isShortcut = (event.metaKey || event.ctrlKey) && key === "k";
      if (isShortcut) {
        const active = document.activeElement;
        if (openRef.current && active instanceof HTMLElement && active.matches("[data-shopify-command-input]")) {
          return;
        }

        event.preventDefault();
        openMenu(query, { focus: true, refresh: !openRef.current, source: "shortcut" });
        return;
      }

      if (key === "escape" && openRef.current) {
        event.preventDefault();
        closeMenu("escape");
      }
    };

    document.addEventListener("keydown", handleGlobalKeydown);
    return () => {
      document.removeEventListener("keydown", handleGlobalKeydown);
    };
  }, [query]);

  useEffect(() => {
    const queueSubmit = (nextQuery, source) => {
      const normalized = normalizeText(nextQuery);
      if (normalized === "") {
        setPendingSubmit(null);
        openMenu("", { focus: true, refresh: !openRef.current, source });
        return;
      }

      setPendingSubmit({ query: nextQuery, source });
      openMenu(nextQuery, { focus: true, refresh: !openRef.current, source });
    };

    const handleClick = (event) => {
      const target = safeTarget(event.target);
      if (!target) {
        return;
      }

      const trigger = target.closest("[data-command-trigger]");
      if (!trigger) {
        return;
      }

      const field = document.querySelector("[data-command-field]");
      const fieldValue = field instanceof HTMLInputElement ? field.value : "";
      queueSubmit(fieldValue, "trigger_click");
    };

    const handleSubmit = (event) => {
      const form = safeTarget(event.target);
      if (!form || !form.matches("[data-command-form]")) {
        return;
      }

      event.preventDefault();
      const field = form.querySelector("[data-command-field]");
      const fieldValue = field instanceof HTMLInputElement ? field.value : "";
      queueSubmit(fieldValue, "topbar_submit");
    };

    const handleFocusIn = (event) => {
      const target = safeTarget(event.target);
      if (!(target instanceof HTMLInputElement) || !target.matches("[data-command-field]")) {
        return;
      }

      openMenu(target.value, { focus: true, refresh: !openRef.current, source: "topbar_focus" });
    };

    const handleInput = (event) => {
      const target = safeTarget(event.target);
      if (!(target instanceof HTMLInputElement) || !target.matches("[data-command-field]")) {
        return;
      }

      openMenu(target.value, { focus: false, source: "topbar_input" });
    };

    const handleFieldEnter = (event) => {
      const target = safeTarget(event.target);
      if (!(target instanceof HTMLInputElement) || !target.matches("[data-command-field]")) {
        return;
      }

      if (String(event.key || "").toLowerCase() !== "enter") {
        return;
      }

      event.preventDefault();
      queueSubmit(target.value, "topbar_enter");
    };

    const handleCustomOpen = (event) => {
      const detail = event instanceof CustomEvent ? event.detail || {} : {};
      openMenu(detail.query || "", { focus: detail.focus !== false, refresh: true, source: "custom_event" });
    };

    document.addEventListener("click", handleClick);
    document.addEventListener("submit", handleSubmit);
    document.addEventListener("focusin", handleFocusIn);
    document.addEventListener("input", handleInput);
    document.addEventListener("keydown", handleFieldEnter);
    document.addEventListener("app-command-palette:open", handleCustomOpen);

    return () => {
      document.removeEventListener("click", handleClick);
      document.removeEventListener("submit", handleSubmit);
      document.removeEventListener("focusin", handleFocusIn);
      document.removeEventListener("input", handleInput);
      document.removeEventListener("keydown", handleFieldEnter);
      document.removeEventListener("app-command-palette:open", handleCustomOpen);
    };
  }, []);

  useEffect(() => {
    if (!open) {
      return;
    }

    const timer = window.setTimeout(() => {
      trackCommandMenuEvent("command_menu_query_changed", {
        ...buildQueryTelemetry(query),
      });
    }, 120);

    return () => {
      window.clearTimeout(timer);
    };
  }, [query, open]);

  useEffect(() => {
    if (!open) {
      zeroResultTrackedRef.current = "";
      return;
    }

    const normalized = normalizeText(query);
    if (normalized === "" || hasResults) {
      zeroResultTrackedRef.current = "";
      return;
    }

    if (zeroResultTrackedRef.current === normalized) {
      return;
    }

    zeroResultTrackedRef.current = normalized;
    trackCommandMenuEvent("command_menu_zero_result_query", {
      ...buildQueryTelemetry(query),
    });
  }, [open, hasResults, query]);

  useEffect(() => {
    if (!pendingSubmit || !open) {
      return;
    }

    const pendingQuery = normalizeText(pendingSubmit.query);
    const currentQuery = normalizeText(query);
    if (pendingQuery === "" || pendingQuery !== currentQuery) {
      return;
    }

    executeHighlightedDocument({
      source: pendingSubmit.source,
      queryOverride: pendingSubmit.query,
    });
    setPendingSubmit(null);
  }, [pendingSubmit, query, open, resultEntryById, topResultEntry]);

  if (typeof document === "undefined") {
    return null;
  }

  return createPortal(
    <div className={open ? "" : "hidden"} data-shopify-command-menu>
      <div className="fixed inset-0 z-[78] fb-overlay-subtle" onClick={() => closeMenu("overlay")} />
      <div
        id={COMMAND_PANEL_ID}
        ref={panelRef}
        className="fixed inset-x-0 top-[8vh] z-[79] px-4"
        role="dialog"
        aria-modal="true"
        aria-hidden={!open}
      >
        <div className="mx-auto w-full max-w-3xl overflow-hidden rounded-[1.75rem] border border-zinc-200 bg-white shadow-[0_26px_60px_-38px_rgba(15,23,42,0.35)]">
          <Command
            shouldFilter={false}
            loop
            label="Global command menu"
          >
            <div className="border-b border-zinc-200 px-5 py-4">
              <div className="flex items-center justify-between gap-3">
                <div>
                  <div className="text-[11px] uppercase tracking-[0.28em] text-zinc-500">{heading} command menu</div>
                  <div className="mt-2 text-sm text-zinc-600">Search actions, pages, and Shopify tools.</div>
                </div>
                <button
                  type="button"
                  className="rounded-full border border-zinc-300 bg-zinc-50 px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-100"
                  onClick={() => closeMenu("close_button")}
                >
                  Close
                </button>
              </div>
              <div className="mt-4">
                <Command.Input
                  data-shopify-command-input
                  value={query}
                  onValueChange={setQuery}
                  onKeyDown={(event) => {
                    if (String(event.key || "").toLowerCase() !== "enter") {
                      return;
                    }

                    event.preventDefault();
                    event.stopPropagation();
                    executeHighlightedDocument({ source: "menu_enter", queryOverride: query });
                  }}
                  autoComplete="off"
                  aria-autocomplete="list"
                  placeholder={placeholder}
                  className="w-full rounded-2xl border border-zinc-300 bg-zinc-50 px-4 py-3 text-sm text-zinc-900 placeholder:text-zinc-500 focus:border-emerald-700 focus:outline-none"
                />
              </div>
            </div>

            <Command.List className="max-h-[60vh] overflow-y-auto px-4 py-4">
              {!hasResults ? (
                <Command.Empty className="rounded-2xl border border-dashed border-zinc-300 bg-zinc-50 px-4 py-6">
                  <div className="text-sm font-semibold text-zinc-900">No match yet</div>
                  <div className="mt-2 text-sm text-zinc-600">Try: create product, go to orders, open customer 123, create discount, settings shipping, or prefs.</div>
                </Command.Empty>
              ) : null}

              {groups.map((group) => (
                <Command.Group key={group.section} heading={SECTION_TITLES[group.section] || group.section} className="mb-4 last:mb-0">
                  <div className="mb-2 px-2 text-[11px] uppercase tracking-[0.24em] text-zinc-500">
                    {SECTION_TITLES[group.section] || group.section}
                  </div>
                  <div className="space-y-2">
                    {group.items.map((item) => {
                      const badge = badgeForItem(item);
                      const breadcrumbs = Array.isArray(item.breadcrumbs) ? item.breadcrumbs.filter(Boolean) : [];
                      const position = resultEntryById.get(item.id)?.position ?? null;

                      return (
                        <Command.Item
                          key={item.id}
                          value={item.id}
                          data-result-id={item.id}
                          onSelect={() => executeDocument(item, { position })}
                          className="block cursor-pointer rounded-2xl border border-zinc-200 bg-white px-4 py-3 transition hover:border-emerald-700/35 hover:bg-emerald-50/55"
                        >
                          <div className="flex items-start justify-between gap-3">
                            <div className="min-w-0">
                              <div className="text-sm font-semibold text-zinc-900">
                                {highlightText(item.title, queryContext.normalizedQuery)}
                              </div>
                              {item.subtitle ? (
                                <div className="mt-1 text-xs text-zinc-500">
                                  {highlightText(item.subtitle, queryContext.normalizedQuery)}
                                </div>
                              ) : null}
                              {breadcrumbs.length > 0 ? (
                                <div className="mt-1 text-[11px] text-zinc-400">
                                  {breadcrumbs.join(" > ")}
                                </div>
                              ) : null}
                            </div>
                            <span className={`rounded-full border px-2 py-1 text-[10px] uppercase tracking-[0.2em] ${badge.className}`}>
                              {badge.label}
                            </span>
                          </div>
                        </Command.Item>
                      );
                    })}
                  </div>
                </Command.Group>
              ))}
            </Command.List>
          </Command>
        </div>
      </div>
    </div>,
    document.body
  );
}
