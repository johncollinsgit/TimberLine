@props([
    'searchEndpoint',
    'placeholder' => 'Search the workspace',
    'contextLabel' => 'Workspace search',
    'description' => 'Search authorized records, modules, actions, and destinations.',
])

<div
    data-app-command-palette
    data-search-endpoint="{{ $searchEndpoint }}"
    data-placeholder="{{ $placeholder }}"
>
    <div class="fixed inset-0 z-[70] hidden fb-overlay-subtle" data-command-overlay></div>
    <div class="fixed inset-x-0 top-[5vh] z-[71] hidden px-3 sm:top-[8vh] sm:px-4" data-command-panel>
        <section
            class="mx-auto w-full max-w-3xl overflow-hidden rounded-[1.4rem] border border-zinc-200 bg-white shadow-[0_30px_80px_-35px_rgba(15,23,42,0.45)] sm:rounded-[1.75rem]"
            role="dialog"
            aria-modal="true"
            aria-labelledby="app-command-title"
            aria-describedby="app-command-description"
        >
            <div class="border-b border-zinc-200 px-4 py-4 sm:px-5">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div id="app-command-title" class="text-[11px] font-semibold uppercase tracking-[0.24em] text-zinc-500">{{ $contextLabel }}</div>
                        <div id="app-command-description" class="mt-1.5 text-xs leading-5 text-zinc-600 sm:text-sm">{{ $description }}</div>
                    </div>
                    <button
                        type="button"
                        class="min-h-9 shrink-0 rounded-full border border-zinc-300 bg-zinc-50 px-3 text-xs font-semibold text-zinc-700 hover:bg-zinc-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-700"
                        data-command-close
                    >Esc</button>
                </div>
                <div class="relative mt-4">
                    <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-zinc-400" aria-hidden="true">⌕</span>
                    <input
                        type="search"
                        autocomplete="off"
                        placeholder="{{ $placeholder }}"
                        class="w-full rounded-2xl border border-zinc-300 bg-zinc-50 py-3 pl-10 pr-4 text-sm text-zinc-900 placeholder:text-zinc-500 focus:border-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-700/15"
                        role="combobox"
                        aria-autocomplete="list"
                        aria-expanded="false"
                        aria-controls="app-command-results"
                        data-command-input
                    />
                </div>
                <div class="mt-2 flex items-center justify-between text-[10px] text-zinc-500">
                    <span>Type to search · ↑↓ to move · Enter to open</span>
                    <span>⌘K</span>
                </div>
            </div>

            <div
                id="app-command-results"
                class="max-h-[66vh] min-h-40 overflow-y-auto px-3 py-3 sm:max-h-[60vh] sm:px-4 sm:py-4"
                role="listbox"
                aria-live="polite"
                data-command-results
            ></div>
        </section>
    </div>
</div>

@once
    <script>
        (() => {
            if (window.__fbCommandPaletteBooted) return;
            window.__fbCommandPaletteBooted = true;

            const RECENT_KEY = "everbranch.command-palette.recents.v1";

            const text = value => String(value ?? "");
            const safeHref = value => {
                try {
                    const url = new URL(text(value || "#"), window.location.origin);
                    return ["http:", "https:"].includes(url.protocol) ? url.href : "#";
                } catch {
                    return "#";
                }
            };
            const titleCase = value => text(value)
                .replace(/[_-]+/g, " ")
                .replace(/\b\w/g, letter => letter.toUpperCase());

            const readRecents = () => {
                try {
                    const parsed = JSON.parse(window.localStorage.getItem(RECENT_KEY) || "[]");
                    return Array.isArray(parsed) ? parsed.filter(row => row && row.url && row.title).slice(0, 5) : [];
                } catch {
                    return [];
                }
            };

            const saveRecent = row => {
                if (!row || !row.url || !row.title) return;
                const normalized = {
                    title: text(row.title),
                    subtitle: text(row.subtitle),
                    url: safeHref(row.url),
                    badge: text(row.badge || "Recent"),
                    type: "recent",
                    action: text(row.action),
                };
                const next = [normalized, ...readRecents().filter(item => item.url !== normalized.url)].slice(0, 5);
                try {
                    window.localStorage.setItem(RECENT_KEY, JSON.stringify(next));
                } catch {
                    // Storage can be unavailable in private or hardened browser sessions.
                }
            };

            const element = (tag, className, content) => {
                const node = document.createElement(tag);
                if (className) node.className = className;
                if (content !== undefined) node.textContent = text(content);
                return node;
            };

            const renderState = (root, title, subtitle, variant = "empty") => {
                const container = root.querySelector("[data-command-results]");
                if (!container) return;
                container.replaceChildren();
                const state = element("div", `rounded-2xl border px-4 py-6 ${variant === "loading" ? "border-zinc-200 bg-white" : "border-dashed border-zinc-300 bg-zinc-50"}`);
                const heading = element("div", "text-sm font-semibold text-zinc-900", title);
                const detail = element("div", "mt-2 text-sm leading-6 text-zinc-600", subtitle);
                state.append(heading, detail);
                container.append(state);
            };

            const groupEntries = payload => {
                const groups = payload && typeof payload === "object" && payload.groups && typeof payload.groups === "object"
                    ? Object.entries(payload.groups)
                    : [];
                return groups.filter(([, rows]) => Array.isArray(rows) && rows.length);
            };

            const renderResult = (root, row, index, query) => {
                const link = element("a", "group block rounded-xl border border-transparent bg-white px-3 py-3 outline-none transition hover:border-emerald-700/25 hover:bg-emerald-50/55 focus-visible:border-emerald-700/40 focus-visible:bg-emerald-50/70 sm:px-4");
                link.href = safeHref(row.url);
                link.id = `app-command-result-${index}`;
                link.setAttribute("role", "option");
                link.setAttribute("aria-selected", "false");
                link.dataset.commandResult = "";
                link.dataset.commandIndex = String(index);
                link.dataset.commandAction = text(row.action);
                link.dataset.commandTitle = text(row.title);
                link.dataset.commandSubtitle = text(row.subtitle);
                link.dataset.commandBadge = text(row.badge);

                const layout = element("div", "flex items-start justify-between gap-3");
                const copy = element("div", "min-w-0");
                const heading = element("div", "text-sm font-semibold text-zinc-900", row.title || "Result");
                if (query && text(row.title).toLowerCase().includes(query.toLowerCase())) {
                    heading.dataset.match = query;
                }
                copy.append(heading);
                if (row.subtitle) copy.append(element("div", "mt-1 line-clamp-2 text-xs leading-5 text-zinc-500", row.subtitle));
                layout.append(copy);
                if (row.badge) layout.append(element("span", "shrink-0 rounded-full border border-zinc-300 bg-zinc-50 px-2 py-1 text-[9px] font-semibold uppercase tracking-[0.14em] text-zinc-500", row.badge));
                link.append(layout);
                return link;
            };

            const renderResults = (root, payload, query = "") => {
                const container = root.querySelector("[data-command-results]");
                if (!container) return;
                container.replaceChildren();

                let groups = groupEntries(payload);
                if (query === "") {
                    const recents = readRecents();
                    if (recents.length) groups = [["recent", recents], ...groups.filter(([name]) => name !== "recent")];
                }

                if (!groups.length) {
                    const empty = payload && payload.empty_state ? payload.empty_state : {
                        title: "No exact match yet",
                        subtitle: "Try a name, record number, module, action, or destination.",
                    };
                    renderState(root, empty.title, empty.subtitle);
                    return;
                }

                let resultIndex = 0;
                groups.forEach(([group, rows]) => {
                    const section = element("section", "mb-3 last:mb-0");
                    section.append(element("div", "mb-1.5 px-2 text-[10px] font-semibold uppercase tracking-[0.2em] text-zinc-500", titleCase(group)));
                    const list = element("div", "space-y-1");
                    rows.forEach(row => list.append(renderResult(root, row, resultIndex++, query)));
                    section.append(list);
                    container.append(section);
                });
            };

            const bindPalette = root => {
                if (typeof window.__appCommandPaletteCleanup === "function") window.__appCommandPaletteCleanup();

                const overlay = root.querySelector("[data-command-overlay]");
                const panel = root.querySelector("[data-command-panel]");
                const input = root.querySelector("[data-command-input]");
                const closeButton = root.querySelector("[data-command-close]");
                const endpoint = root.dataset.searchEndpoint || "";
                if (!overlay || !panel || !input || !closeButton || !endpoint) return;

                let debounceTimer;
                let activeIndex = -1;
                let requestSequence = 0;
                let abortController;
                let returnFocus = null;

                const isOpen = () => !panel.classList.contains("hidden");
                const results = () => Array.from(root.querySelectorAll("[data-command-result]"));
                const externalFields = () => Array.from(document.querySelectorAll("[data-command-field]")).filter(field => field instanceof HTMLInputElement);

                const selectIndex = index => {
                    const rows = results();
                    if (!rows.length) {
                        activeIndex = -1;
                        input.removeAttribute("aria-activedescendant");
                        return;
                    }
                    if (index < 0) {
                        activeIndex = -1;
                        input.removeAttribute("aria-activedescendant");
                        rows.forEach(row => {
                            row.setAttribute("aria-selected", "false");
                            row.classList.remove("border-emerald-700/40", "bg-emerald-50/70");
                        });
                        return;
                    }
                    activeIndex = (index + rows.length) % rows.length;
                    rows.forEach((row, position) => {
                        const selected = position === activeIndex;
                        row.setAttribute("aria-selected", selected ? "true" : "false");
                        row.classList.toggle("border-emerald-700/40", selected);
                        row.classList.toggle("bg-emerald-50/70", selected);
                    });
                    const active = rows[activeIndex];
                    input.setAttribute("aria-activedescendant", active.id);
                    active.scrollIntoView({block: "nearest"});
                };

                const executeSearch = () => {
                    const query = input.value.trim();
                    const sequence = ++requestSequence;
                    abortController?.abort();
                    abortController = new AbortController();
                    renderState(root, query ? "Searching…" : "Loading suggestions…", "Everbranch is checking only what you are allowed to access.", "loading");

                    const url = new URL(endpoint, window.location.origin);
                    if (query) url.searchParams.set("q", query);

                    fetch(url, {
                        headers: {"X-Requested-With": "XMLHttpRequest", "Accept": "application/json"},
                        credentials: "same-origin",
                        signal: abortController.signal,
                    })
                        .then(response => response.ok ? response.json() : Promise.reject(response))
                        .then(payload => {
                            if (sequence !== requestSequence) return;
                            renderResults(root, payload, query);
                            selectIndex(-1);
                        })
                        .catch(error => {
                            if (error && error.name === "AbortError") return;
                            if (sequence !== requestSequence) return;
                            renderState(root, "Search is unavailable right now", "Try reloading or use the navigation directly.");
                        });
                };

                const open = (options = {}) => {
                    const detail = options && typeof options === "object" ? options : {};
                    const query = typeof detail.query === "string" ? detail.query : input.value;
                    returnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
                    input.value = query;
                    externalFields().forEach(field => { field.value = query; });
                    overlay.classList.remove("hidden");
                    panel.classList.remove("hidden");
                    input.setAttribute("aria-expanded", "true");
                    document.documentElement.classList.add("overflow-hidden");
                    executeSearch();
                    window.requestAnimationFrame(() => {
                        input.focus();
                        input.setSelectionRange(query.length, query.length);
                    });
                };

                const close = () => {
                    if (!isOpen()) return;
                    abortController?.abort();
                    overlay.classList.add("hidden");
                    panel.classList.add("hidden");
                    input.setAttribute("aria-expanded", "false");
                    input.removeAttribute("aria-activedescendant");
                    document.documentElement.classList.remove("overflow-hidden");
                    activeIndex = -1;
                    if (returnFocus && document.contains(returnFocus)) returnFocus.focus();
                };

                const navigate = row => {
                    if (!(row instanceof HTMLAnchorElement)) return;
                    saveRecent({
                        title: row.dataset.commandTitle,
                        subtitle: row.dataset.commandSubtitle,
                        badge: row.dataset.commandBadge,
                        action: row.dataset.commandAction,
                        url: row.href,
                    });
                    if ((row.dataset.commandAction || "") !== "open-command") window.location.assign(row.href);
                };

                const onKeydown = event => {
                    const commandKey = (event.metaKey || event.ctrlKey) && String(event.key).toLowerCase() === "k";
                    if (commandKey) {
                        event.preventDefault();
                        isOpen() ? close() : open();
                        return;
                    }
                    if (!isOpen()) return;

                    if (event.key === "Escape") {
                        event.preventDefault();
                        close();
                    } else if (event.key === "ArrowDown") {
                        event.preventDefault();
                        selectIndex(activeIndex < 0 ? 0 : activeIndex + 1);
                    } else if (event.key === "ArrowUp") {
                        event.preventDefault();
                        selectIndex(activeIndex < 0 ? results().length - 1 : activeIndex - 1);
                    } else if (event.key === "Enter" && document.activeElement === input) {
                        event.preventDefault();
                        const row = results()[activeIndex >= 0 ? activeIndex : 0];
                        navigate(row);
                    } else if (event.key === "Tab") {
                        const focusable = [input, closeButton, ...results()];
                        const current = focusable.indexOf(document.activeElement);
                        if (event.shiftKey && current <= 0) {
                            event.preventDefault();
                            focusable[focusable.length - 1]?.focus();
                        } else if (!event.shiftKey && current === focusable.length - 1) {
                            event.preventDefault();
                            input.focus();
                        }
                    }
                };

                const onDocumentClick = event => {
                    const target = event.target;
                    if (!(target instanceof HTMLElement)) return;
                    const trigger = target.closest("[data-command-trigger]");
                    if (trigger) {
                        event.preventDefault();
                        open();
                        return;
                    }
                    const row = target.closest("[data-command-result]");
                    if (row instanceof HTMLAnchorElement) {
                        saveRecent({
                            title: row.dataset.commandTitle,
                            subtitle: row.dataset.commandSubtitle,
                            badge: row.dataset.commandBadge,
                            action: row.dataset.commandAction,
                            url: row.href,
                        });
                    }
                };

                const onInput = () => {
                    externalFields().forEach(field => { field.value = input.value; });
                    clearTimeout(debounceTimer);
                    debounceTimer = window.setTimeout(executeSearch, 170);
                };

                const onCustomOpen = event => open(event instanceof CustomEvent ? event.detail : {});

                document.addEventListener("keydown", onKeydown);
                document.addEventListener("click", onDocumentClick);
                document.addEventListener("app-command-palette:open", onCustomOpen);
                input.addEventListener("input", onInput);
                closeButton.addEventListener("click", close);
                overlay.addEventListener("click", close);
                root.dataset.commandPaletteBound = "1";

                window.__appCommandPaletteCleanup = () => {
                    abortController?.abort();
                    clearTimeout(debounceTimer);
                    document.removeEventListener("keydown", onKeydown);
                    document.removeEventListener("click", onDocumentClick);
                    document.removeEventListener("app-command-palette:open", onCustomOpen);
                    input.removeEventListener("input", onInput);
                    closeButton.removeEventListener("click", close);
                    overlay.removeEventListener("click", close);
                    delete root.dataset.commandPaletteBound;
                };
            };

            const init = () => {
                const root = document.querySelector("[data-app-command-palette]");
                if (!root) return;
                if (root.dataset.commandPaletteBound !== "1") bindPalette(root);
            };

            init();
            document.addEventListener("livewire:navigated", init);
        })();
    </script>
@endonce
