import { useEffect, useMemo, useState } from "react";
import { createPortal } from "react-dom";
import { Command } from "cmdk";
import { SECTION_TITLES, rememberRecentAction, useActionSearch } from "./useActionSearch.js";

function safeTarget(element) {
  return element instanceof HTMLElement ? element : null;
}

export function GlobalCommandMenu({ placeholder, contextLabel, baseQuery }) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  const [refreshToken, setRefreshToken] = useState(0);

  const { groups, results } = useActionSearch({
    query,
    refreshToken,
    baseQuery,
  });

  const hasResults = results.length > 0;

  const openMenu = (nextQuery = "", { focus = true } = {}) => {
    setOpen(true);
    setQuery(String(nextQuery || ""));
    setRefreshToken((value) => value + 1);

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

  const closeMenu = () => {
    setOpen(false);
  };

  useEffect(() => {
    const handleKeydown = (event) => {
      const key = String(event.key || "").toLowerCase();
      const isShortcut = (event.metaKey || event.ctrlKey) && key === "k";
      if (isShortcut) {
        event.preventDefault();
        openMenu(query, { focus: true });
        return;
      }

      if (key === "escape" && open) {
        event.preventDefault();
        closeMenu();
      }
    };

    document.addEventListener("keydown", handleKeydown);

    return () => {
      document.removeEventListener("keydown", handleKeydown);
    };
  }, [open, query]);

  useEffect(() => {
    const handleClick = (event) => {
      const target = safeTarget(event.target);
      if (!target) {
        return;
      }

      const trigger = target.closest("[data-command-trigger]");
      if (trigger) {
        const field = document.querySelector("[data-command-field]");
        const fieldValue = field instanceof HTMLInputElement ? field.value : "";
        openMenu(fieldValue, { focus: true });
      }
    };

    const handleFocusIn = (event) => {
      const target = safeTarget(event.target);
      if (!(target instanceof HTMLInputElement) || !target.matches("[data-command-field]")) {
        return;
      }

      openMenu(target.value, { focus: true });
    };

    const handleInput = (event) => {
      const target = safeTarget(event.target);
      if (!(target instanceof HTMLInputElement) || !target.matches("[data-command-field]")) {
        return;
      }

      openMenu(target.value, { focus: false });
    };

    const handleCustomOpen = (event) => {
      const detail = event instanceof CustomEvent ? event.detail || {} : {};
      openMenu(detail.query || "", { focus: detail.focus !== false });
    };

    document.addEventListener("click", handleClick);
    document.addEventListener("focusin", handleFocusIn);
    document.addEventListener("input", handleInput);
    document.addEventListener("app-command-palette:open", handleCustomOpen);

    return () => {
      document.removeEventListener("click", handleClick);
      document.removeEventListener("focusin", handleFocusIn);
      document.removeEventListener("input", handleInput);
      document.removeEventListener("app-command-palette:open", handleCustomOpen);
    };
  }, []);

  const heading = useMemo(() => {
    const label = String(contextLabel || "").trim();
    return label === "" ? "Backstage" : label;
  }, [contextLabel]);

  const executeDocument = (document) => {
    if (!document || typeof document.execute !== "function") {
      return;
    }

    Promise.resolve(document.execute())
      .then(() => {
        rememberRecentAction(document.id);
      })
      .finally(() => {
        closeMenu();
      });
  };

  if (typeof document === "undefined") {
    return null;
  }

  return createPortal(
    <div className={open ? "" : "hidden"} data-shopify-command-menu>
      <div className="fixed inset-0 z-[78] fb-overlay-subtle" onClick={closeMenu} />
      <div className="fixed inset-x-0 top-[8vh] z-[79] px-4" aria-hidden={!open}>
        <div className="mx-auto w-full max-w-3xl overflow-hidden rounded-[1.75rem] border border-zinc-200 bg-white shadow-[0_26px_60px_-38px_rgba(15,23,42,0.35)]">
          <Command
            shouldFilter={false}
            loop
            value={query}
            onValueChange={setQuery}
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
                  onClick={closeMenu}
                >
                  Close
                </button>
              </div>
              <div className="mt-4">
                <Command.Input
                  data-shopify-command-input
                  value={query}
                  onValueChange={setQuery}
                  autoComplete="off"
                  placeholder={placeholder}
                  className="w-full rounded-2xl border border-zinc-300 bg-zinc-50 px-4 py-3 text-sm text-zinc-900 placeholder:text-zinc-500 focus:border-emerald-700 focus:outline-none"
                />
              </div>
            </div>

            <Command.List className="max-h-[60vh] overflow-y-auto px-4 py-4">
              {!hasResults ? (
                <Command.Empty className="rounded-2xl border border-dashed border-zinc-300 bg-zinc-50 px-4 py-6">
                  <div className="text-sm font-semibold text-zinc-900">No exact match yet</div>
                  <div className="mt-2 text-sm text-zinc-600">Try product, new product, orders, customer, discount, shipping, or settings.</div>
                </Command.Empty>
              ) : null}

              {groups.map((group) => (
                <Command.Group key={group.section} heading={SECTION_TITLES[group.section] || group.section} className="mb-4 last:mb-0">
                  <div className="mb-2 px-2 text-[11px] uppercase tracking-[0.24em] text-zinc-500">
                    {SECTION_TITLES[group.section] || group.section}
                  </div>
                  <div className="space-y-2">
                    {group.items.map((item) => (
                      <Command.Item
                        key={item.id}
                        value={`${item.title} ${item.subtitle || ""}`}
                        onSelect={() => executeDocument(item)}
                        className="block cursor-pointer rounded-2xl border border-zinc-200 bg-white px-4 py-3 transition hover:border-emerald-700/35 hover:bg-emerald-50/55"
                      >
                        <div className="flex items-start justify-between gap-3">
                          <div className="min-w-0">
                            <div className="text-sm font-semibold text-zinc-900">{item.title}</div>
                            {item.subtitle ? <div className="mt-1 text-xs text-zinc-500">{item.subtitle}</div> : null}
                          </div>
                          <span className="rounded-full border border-zinc-300 bg-zinc-50 px-2 py-1 text-[10px] uppercase tracking-[0.2em] text-zinc-500">
                            {SECTION_TITLES[item.section] || "Action"}
                          </span>
                        </div>
                      </Command.Item>
                    ))}
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
