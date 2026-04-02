@props([
    'searchEndpoint',
    'placeholder' => 'Search the workspace',
    'contextLabel' => 'Workspace search',
])

<div
    data-app-command-palette
    data-search-endpoint="{{ $searchEndpoint }}"
    data-placeholder="{{ $placeholder }}"
    class="hidden"
>
    <div class="fixed inset-0 z-[70] hidden bg-slate-900/20 backdrop-blur-[2px]" data-command-overlay></div>
    <div class="fixed inset-x-0 top-[8vh] z-[71] hidden px-4" data-command-panel>
        <div class="mx-auto w-full max-w-3xl overflow-hidden rounded-[1.75rem] border border-zinc-200 bg-white shadow-[0_26px_60px_-38px_rgba(15,23,42,0.35)]">
            <div class="border-b border-zinc-200 px-5 py-4">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <div class="text-[11px] uppercase tracking-[0.28em] text-zinc-500">{{ $contextLabel }}</div>
                        <div class="mt-2 text-sm text-zinc-600">Search customers, orders, modules, imports, and actions.</div>
                    </div>
                    <button type="button" class="rounded-full border border-zinc-300 bg-zinc-50 px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-100" data-command-close>Esc</button>
                </div>
                <div class="mt-4">
                    <input
                        type="search"
                        autocomplete="off"
                        placeholder="{{ $placeholder }}"
                        class="w-full rounded-2xl border border-zinc-300 bg-zinc-50 px-4 py-3 text-sm text-zinc-900 placeholder:text-zinc-500 focus:border-emerald-700 focus:outline-none"
                        data-command-input
                    />
                </div>
            </div>

            <div class="max-h-[60vh] overflow-y-auto px-4 py-4" data-command-results>
                <div class="rounded-2xl border border-dashed border-zinc-300 bg-zinc-50 px-4 py-6 text-sm text-zinc-600">
                    Start typing to search the workspace.
                </div>
            </div>
        </div>
    </div>
</div>

@once
    <script>
        (function () {
            if (window.__fbCommandPaletteBound) {
                return;
            }
            window.__fbCommandPaletteBound = true;

            function normalizeGroups(payload) {
                const groups = payload && typeof payload === "object" ? (payload.groups || {}) : {};
                return Object.entries(groups);
            }

            function renderResultRow(row) {
                const href = row.url || "#";
                const badge = row.badge ? `<span class="rounded-full border border-zinc-300 bg-zinc-50 px-2 py-1 text-[10px] uppercase tracking-[0.2em] text-zinc-500">${row.badge}</span>` : "";
                const meta = row.subtitle ? `<div class="mt-1 text-xs text-zinc-500">${row.subtitle}</div>` : "";

                return `
                    <a href="${href}" class="block rounded-2xl border border-zinc-200 bg-white px-4 py-3 transition hover:border-emerald-700/35 hover:bg-emerald-50/55" data-command-result data-command-action="${row.action || ""}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="text-sm font-semibold text-zinc-900">${row.title || "Result"}</div>
                                ${meta}
                            </div>
                            ${badge}
                        </div>
                    </a>
                `;
            }

            function renderResults(root, payload) {
                const container = root.querySelector("[data-command-results]");
                if (!container) return;

                const groups = normalizeGroups(payload);
                if (!groups.length) {
                    const empty = payload && payload.empty_state ? payload.empty_state : {
                        title: "No exact match yet",
                        subtitle: "Try a customer name, order number, module, import type, or workflow."
                    };
                    container.innerHTML = `
                        <div class="rounded-2xl border border-dashed border-zinc-300 bg-zinc-50 px-4 py-6">
                            <div class="text-sm font-semibold text-zinc-900">${empty.title}</div>
                            <div class="mt-2 text-sm text-zinc-600">${empty.subtitle}</div>
                        </div>
                    `;
                    return;
                }

                container.innerHTML = groups.map(([group, rows]) => `
                    <section class="mb-4 last:mb-0">
                        <div class="mb-2 px-2 text-[11px] uppercase tracking-[0.24em] text-zinc-500">${group}</div>
                        <div class="space-y-2">
                            ${(rows || []).map(renderResultRow).join("")}
                        </div>
                    </section>
                `).join("");
            }

            function bindPalette(root) {
                if (root.dataset.commandPaletteBound === "1") {
                    return;
                }
                root.dataset.commandPaletteBound = "1";

                const overlay = root.querySelector("[data-command-overlay]");
                const panel = root.querySelector("[data-command-panel]");
                const input = root.querySelector("[data-command-input]");
                const closeBtn = root.querySelector("[data-command-close]");
                const endpoint = root.dataset.searchEndpoint || "";

                if (!overlay || !panel || !input || endpoint === "") {
                    return;
                }

                let debounceId = null;
                let requestId = 0;

                const open = () => {
                    overlay.classList.remove("hidden");
                    panel.classList.remove("hidden");
                    input.focus();
                    input.select();
                };

                const close = () => {
                    overlay.classList.add("hidden");
                    panel.classList.add("hidden");
                };

                const executeSearch = () => {
                    const value = input.value.trim();
                    const currentId = ++requestId;
                    const url = new URL(endpoint, window.location.origin);
                    if (value !== "") {
                        url.searchParams.set("q", value);
                    }

                    fetch(url.toString(), {
                        headers: {
                            "X-Requested-With": "XMLHttpRequest",
                            "Accept": "application/json"
                        },
                        credentials: "same-origin"
                    })
                        .then((response) => response.ok ? response.json() : Promise.reject(response))
                        .then((payload) => {
                            if (currentId !== requestId) return;
                            renderResults(root, payload);
                        })
                        .catch(() => {
                            if (currentId !== requestId) return;
                            renderResults(root, {
                                empty_state: {
                                    title: "Search is unavailable right now",
                                    subtitle: "Try reloading the page or jump directly to the workspace section you need."
                                }
                            });
                        });
                };

                const debouncedSearch = () => {
                    clearTimeout(debounceId);
                    debounceId = setTimeout(executeSearch, 160);
                };

                document.addEventListener("keydown", (event) => {
                    const isCommandKey = (event.metaKey || event.ctrlKey) && String(event.key).toLowerCase() === "k";
                    if (isCommandKey) {
                        event.preventDefault();
                        open();
                        return;
                    }

                    if (event.key === "Escape" && !panel.classList.contains("hidden")) {
                        event.preventDefault();
                        close();
                    }

                    if (event.key === "Enter" && document.activeElement === input && !panel.classList.contains("hidden")) {
                        const first = root.querySelector("[data-command-result]");
                        if (first instanceof HTMLAnchorElement) {
                            const action = first.dataset.commandAction || "";
                            if (action === "open-command") {
                                return;
                            }

                            window.location.assign(first.href);
                        }
                    }
                });

                root.addEventListener("click", (event) => {
                    const target = event.target;
                    if (!(target instanceof HTMLElement)) return;

                    const resultLink = target.closest("[data-command-result]");
                    if (resultLink instanceof HTMLAnchorElement && (resultLink.dataset.commandAction || "") === "open-command") {
                        event.preventDefault();
                        input.focus();
                        return;
                    }

                    if (target.closest("[data-command-close]") || target.closest("[data-command-overlay]")) {
                        close();
                        return;
                    }

                    if (target.closest("[data-command-trigger]")) {
                        open();
                    }
                });

                document.addEventListener("app-command-palette:open", open);
                input.addEventListener("input", debouncedSearch);
                closeBtn.addEventListener("click", close);
                overlay.addEventListener("click", close);
            }

            function initPalettes() {
                document.querySelectorAll("[data-app-command-palette]").forEach(bindPalette);
            }

            initPalettes();
            document.addEventListener("livewire:navigated", initPalettes);
        })();
    </script>
@endonce
