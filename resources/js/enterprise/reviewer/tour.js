/* Shared presentation only. This story never calls a transport or writes sample records. */
(() => {
    "use strict";
    const opening =
        "Everbranch Enterprise is your campus operating system. It's designed to help the campus operate efficiently through automating tasks and tracking all the moving parts of campus life. It is your single source of truth for campus management.";
    // Attributed Gemini Quick Notes identify these needs; Andrew has not yet
    // validated this proposed solution map. No record or provider action lives here.
    const priorities = Object.freeze([
        {id: "visibility", title: "Shared visibility", outcome: "Bring paper assignments and separate spreadsheets into one permission-controlled view of ownership, status and updates across relevant teams.", procedure: "One accountable owner, a clear next action and a checked handoff.", section: "projects", scene: 1, target: "request-list", selector: "[data-open]", icon: "M4 5h16v14H4z M8 9h8 M8 13h4"},
        {id: "crews", title: "Shared Roll Call tickets", outcome: "Keep printed technician handouts connected to shared assigned work, with owners, status and retained checkpoints.", procedure: "Review the daily queue; explicitly resume interrupted work and print a dated one-page summary when useful.", section: "roll_call", scene: 3, target: "roll-scope", icon: "M8 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6 M3 20v-3a5 5 0 0 1 10 0v3 M16 6h5 M18.5 3.5v5 M16 15h5 M16 19h5"},
        {id: "schedules", title: "Fewer scheduling surprises", outcome: "Check work windows against recorded classroom, athletics and equipment commitments.", procedure: "A coordinator records the conflict and confirms the resolution.", section: "calendar", scene: 4, target: "res-query", icon: "M4 5h16v15H4z M4 10h16 M8 3v4 M16 3v4 M8 14h3 M8 17h6"},
        {id: "reports", title: "Reporting with a clear source", outcome: "Export authorized project activity for a selected quarter or date window with source references.", procedure: "Agree the report fields, reconcile the facts and retain the reviewed export.", section: "roll_call", scene: 5, target: "quarterly-preview", icon: "M6 3h9l4 4v14H6z M14 3v5h5 M9 17v-3 M12 17v-6 M15 17v-4"},
        {id: "certificates", title: "Contractor renewal oversight", outcome: "See accepted certificate expiry, pending replacements and records needing attention.", procedure: "Name a renewal contact; review replacement evidence before changing the accepted record.", section: "people", scene: 4, target: "certificate-new", icon: "M12 3l8 3v6c0 5-8 9-8 9s-8-4-8-9V6z M8 12l3 3 5-6"},
        {id: "invoices", title: "Clear invoice handoffs", outcome: "Keep the invoice reference, current approver and next action together as the packet moves through review.", procedure: "Record the decision and handoff; Finance keeps payment execution.", section: "finance", scene: 2, target: "finance-list", selector: "[data-finance-record]", icon: "M6 3h12v18l-3-2-3 2-3-2-3 2z M9 8h6 M9 12h6 M9 16h3"},
        {id: "funds", title: "Traceable fund references", outcome: "Keep the approved budget code and restriction reference beside the project approval packet.", procedure: "Use Finance-approved references and reconcile to the authoritative ledger.", section: "finance", scene: 2, target: "finance-list", selector: "[data-finance-record]", icon: "M3 8l9-5 9 5 M4 9h16 M6 10v8 M12 10v8 M18 10v8 M3 21h18 M4 18h16"},
    ].map(Object.freeze));
    const executivePriorities = Object.freeze([
        Object.freeze({id:'executive',title:'Executive oversight',outcome:'Inspect configured workspaces, evidence-based work queues and proposed software rollout costs in one view.',procedure:'Open a supporting record; distinguish simulated workspace stages and uncommitted software costs from campus job budgets.',section:'home',scene:0,target:'executive-network',icon:'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z'}),
        ...priorities,
    ]);
    const scenes = Object.freeze(
        [
            {
                title: "One campus. A clear picture.",
                section: "home",
                duration: 30000,
                role: "Workers · Security · Administration",
                text: opening,
                detail: "Search connects authorized work, locations and accountable departments. The official location directory keeps uncertain placements visibly unverified.",
                cards: [
                    ["Search campus work", "Bryan Gym · sample event setup"],
                    [
                        "Accountable workspace",
                        "Operations, with Events and Athletics",
                    ],
                    [
                        "Source-supported directory",
                        "68 map entries · coordinates unverified",
                    ],
                ],
            },
            {
                title: "A request starts the story.",
                section: "requests",
                duration: 30000,
                role: "Student requester · IT · Events · Maintenance",
                text: "A student reports that event audio needs attention. The request stays traceable as the department triages it, assigns a worker and shares public progress.",
                detail: "Requester updates show the next step. Internal notes, student details and restricted attachments stay with the people permitted to see them.",
                cards: [
                    ["Sample request R-101", "Event audio needs a check"],
                    ["Accountable owner", "Operations coordinator"],
                    ["Next action", "Check the PA system before the gym event"],
                ],
            },
            {
                title: "Design the work, then review it.",
                section: "workflows",
                duration: 30000,
                role: "Department manager · IT · assigned worker",
                text: "Open the complete workflow workspace. Connect a form, condition, assignment and approval; preview the draft before activating it.",
                detail: "The same canvas, form and run-history presentation supports the sample experience. Sample execution is explicitly separate from the permission-controlled server engine.",
                cards: [
                    ["Form → Condition", "Capture issue and urgency"],
                    ["Assignment → Approval", "Named worker and manager"],
                    ["Run history", "A recorded checkpoint at each handoff"],
                ],
            },
            {
                title: "Emergencies interrupt work. They do not erase it.",
                section: "roll_call",
                duration: 30000,
                role: "Security · workers · Events · department managers",
                text: "An urgent water issue needs the worker now. The original audio check becomes Interrupted, retaining its owner, progress, notes and last checkpoint.",
                detail: "The resumption queue keeps it in Roll Call until someone explicitly resumes, reassigns, reschedules or closes it with a reason. Managers review schedule impacts.",
                cards: [
                    ["Emergency", "Water issue needs immediate attention"],
                    ["Interrupted: R-101", "Audio check · checkpoint retained"],
                    [
                        "Next action",
                        "Resolve emergency, then explicitly resume audio check",
                    ],
                ],
            },
            {
                title: "Partners and equipment stay connected.",
                section: "assets",
                duration: 30000,
                role: "Contractor · Operations · Athletics",
                text: "A contractor acknowledges a limited update link and provides an ETA and evidence. Staff locate the PA system through its custody record for the gym event.",
                detail: "An update link permits only its reviewed actions. Asset history shows holder, last recorded location, expected return and condition; it is not a live tracker.",
                cards: [
                    ["Contractor handoff", "Acknowledgment → ETA → evidence"],
                    [
                        "PA system · sample custody",
                        "Athletics · Bryan Gym event",
                    ],
                    ["Expected return", "After the event · condition recorded"],
                ],
            },
            {
                title: "Bring the right work into the meeting.",
                section: "roll_call",
                duration: 30000,
                role: "Administration · department managers · campus teams",
                text: "Roll Call brings together emergencies, interrupted work, overdue commitments and conflicts. Leaders record decisions and confirm commitments before changing tasks.",
                detail: "The fresh one-page meeting PDF includes scope, timestamp and source links. Completion and contractor quality are separate measures. A saved PDF remains a dated snapshot.",
                cards: [
                    [
                        "Completion",
                        "3.9 / awaiting verification · 4.0 requires approval",
                    ],
                    [
                        "Contractor quality",
                        "Separate manager review · Unrated until reviewed",
                    ],
                    [
                        "Meeting Roll Call",
                        "Priorities, decisions, owners and dates",
                    ],
                ],
            },
        ].map((scene) => Object.freeze(scene)),
    );

    class StoryClock {
        constructor() {
            this.elapsed = 0;
            this.playing = false;
        }
        get duration() {
            return scenes.reduce((sum, scene) => sum + scene.duration, 0);
        }
        get index() {
            return Math.min(
                scenes.length - 1,
                Math.floor(this.elapsed / 30000),
            );
        }
        tick(milliseconds) {
            if (this.playing)
                this.elapsed = Math.min(
                    this.duration,
                    this.elapsed + Math.max(0, milliseconds),
                );
            if (this.elapsed === this.duration) this.playing = false;
            return this.index;
        }
        seek(index) {
            this.elapsed =
                Math.max(0, Math.min(scenes.length - 1, index)) * 30000;
        }
        restart() {
            this.elapsed = 0;
            this.playing = false;
        }
    }

    function mountPriorities(root, actions = {}, pause = () => {}) {
        const priorities = actions.priorityItems || globalThis.EverbranchReviewerTour.priorities;
        const get = (name) => root.querySelector(`[data-priorities-${name}]`),
            dialog = get("dialog");
        if (!dialog) return null;
        const key = actions.priorityStorageKey || "everbranch-priorities-sample-v1";
        let selected = 0, active = false, visible = true, returnFocus = null, anchor = null, timer = null, reveal = false;
        function restore() {
            try {
                const saved = JSON.parse(sessionStorage.getItem(key) || "null");
                if (saved && Number.isInteger(saved.selected) && saved.selected >= 0 && saved.selected < priorities.length) {
                    selected = saved.selected;
                    active = saved.active === true;
                    visible = saved.visible === true;
                }
            } catch { /* Storage is optional; no work draft is stored in this key. */ }
        }
        restore();
        const state = (item) => actions.priorityStatus?.(item) || {
            available: typeof actions.onNavigate === "function" && !["finance"].includes(item.section),
            status: item.section === "finance" ? "Working feature in preparation" : "Sample workflow · not live",
        };
        function save() {
            try { sessionStorage.setItem(key, JSON.stringify({selected, active, visible})); } catch { /* Browsing still works without storage. */ }
        }
        function clearSpotlight() {
            get("highlight").hidden = true;
            get("tip").hidden = true;
            anchor = null;
        }
        function position() {
            if (!active || !visible || dialog.open || !anchor || !anchor.isConnected) return clearSpotlight();
            const rect = anchor.getBoundingClientRect(),
                barBottom = get("bar").getBoundingClientRect().bottom + 10;
            if (rect.width < 1 || rect.height < 1 || rect.bottom < barBottom || rect.top > innerHeight - 30) return clearSpotlight();
            const top = Math.max(barBottom, rect.top - 6), left = Math.max(7, rect.left - 6),
                width = Math.min(innerWidth - left - 7, rect.width + 12),
                height = Math.min(innerHeight - top - 7, Math.max(34, rect.bottom - top + 6));
            Object.assign(get("highlight").style, {top: `${top}px`, left: `${left}px`, width: `${width}px`, height: `${height}px`});
            get("highlight").hidden = false;
            const tip = get("tip");
            tip.hidden = false;
            const tipHeight = tip.getBoundingClientRect().height,
                below = top + height + 12,
                tipTop = below + tipHeight < innerHeight - 12 ? below : Math.max(barBottom, top - tipHeight - 12);
            Object.assign(tip.style, {top: `${Math.min(innerHeight - tipHeight - 10, tipTop)}px`, left: `${Math.max(12, Math.min(innerWidth - tip.getBoundingClientRect().width - 12, left))}px`});
        }
        function spotlight() {
            clearSpotlight();
            if (!active || !visible || dialog.open) return;
            const item = priorities[selected], container = document.getElementById(item.target), target = item.selector ? container?.querySelector(item.selector) : container;
            if (!target || target.closest("[hidden]") || !target.getClientRects().length) {
                get("status").textContent = "This feature is not visible on the current page. Choose Priorities to open an available screen, or hide the tour and keep working.";
                return;
            }
            anchor = target;
            get("status").textContent = "";
            if (reveal) {
                target.scrollIntoView({block: "center", behavior: "instant"});
                reveal = false;
            }
            get("tip-title").textContent = item.title;
            get("tip-text").textContent = item.procedure;
            position();
        }
        function paintBar() {
            get("bar").hidden = !active;
            document.body.classList.toggle("erp-tour-active", active);
            get("current").textContent = priorities[selected].title;
            get("progress").textContent = `Priority ${selected + 1} of ${priorities.length} · ${visible ? "Guided exploration" : "Explore freely; your step is saved"}`;
            get("toggle").textContent = visible ? "Hide tour" : "Show tour";
            get("toggle").setAttribute("aria-pressed", String(visible));
            get("continue").textContent = selected === priorities.length - 1 ? "Review priorities" : "Continue";
            save();
        }
        function paintCards() {
            const focusedPriority = dialog.contains(document.activeElement) ? document.activeElement?.dataset.priorityOpen : null;
            get("grid").replaceChildren(...priorities.map((item, index) => {
                const current = state(item), card = document.createElement("article"), top = document.createElement("div"), icon = document.createElement("span"),
                    svg = document.createElementNS("http://www.w3.org/2000/svg", "svg"), path = document.createElementNS("http://www.w3.org/2000/svg", "path"),
                    number = document.createElement("span"), heading = document.createElement("h3"), outcome = document.createElement("p"), procedure = document.createElement("p"),
                    footer = document.createElement("div"), status = document.createElement("span"), button = document.createElement("button");
                card.className = "erp-card"; card.dataset.priorityCard = item.id; card.setAttribute("aria-current", index === selected ? "step" : "false");
                top.className = "erp-card-top"; icon.className = "erp-icon"; icon.setAttribute("aria-hidden", "true"); svg.setAttribute("viewBox", "0 0 24 24"); path.setAttribute("d", item.icon); svg.append(path); icon.append(svg);
                number.className = "erp-index"; number.textContent = String(index + 1).padStart(2, "0"); top.append(icon, number);
                heading.textContent = item.title; outcome.textContent = item.outcome; procedure.className = "erp-procedure"; procedure.textContent = item.procedure;
                footer.className = "erp-card-footer"; status.className = "erp-state"; status.textContent = current.status;
                button.type = "button"; button.className = "ert-button"; button.dataset.priorityOpen = item.id; button.disabled = !current.available;
                button.textContent = current.available ? "Explore this workflow" : "Unavailable in this view"; button.setAttribute("aria-label", `${button.textContent}: ${item.title}`);
                const arrow = document.createElement("span"); arrow.textContent = "↗"; arrow.setAttribute("aria-hidden", "true"); button.append(arrow);
                footer.append(status, button); card.append(top, heading, outcome, procedure, footer); return card;
            }));
            if (focusedPriority) get("grid").querySelector(`[data-priority-open="${focusedPriority}"]:not(:disabled)`)?.focus({preventScroll: true});
        }
        function open(trigger = get("open")) {
            pause(); clearSpotlight(); returnFocus = trigger; paintCards();
            if (!dialog.open) dialog.showModal();
            document.getElementById("erp-title").focus();
        }
        function close() {
            if (dialog.open) dialog.close();
            returnFocus?.focus();
            spotlight();
        }
        function navigate(id) {
            const index = priorities.findIndex(item => item.id === id), item = priorities[index];
            if (!item || !state(item).available) return;
            const result = actions.onPriority ? actions.onPriority(item) : actions.onNavigate?.(item.section, item.scene);
            if (result?.available === false) { get("status").textContent = result.message || "This feature is unavailable in the current workspace."; paintCards(); return; }
            selected = index; active = true; visible = true; reveal = true; paintBar(); close();
            clearTimeout(timer); timer = setTimeout(spotlight, 250);
        }
        function click(event) {
            const button = event.target.closest("button");
            if (!button || !root.contains(button)) return;
            if (button.hasAttribute("data-priorities-open") || button.hasAttribute("data-priorities-overview")) open(button);
            else if (button.hasAttribute("data-priorities-close")) close();
            else if (button.hasAttribute("data-priority-open")) navigate(button.dataset.priorityOpen);
            else if (button.hasAttribute("data-priorities-toggle") || button.hasAttribute("data-priorities-explore")) {
                visible = button.hasAttribute("data-priorities-explore") ? false : !visible;
                if (visible && state(priorities[selected]).available) navigate(priorities[selected].id);
                else { paintBar(); spotlight(); }
            } else if (button.hasAttribute("data-priorities-continue")) {
                selected = selected === priorities.length - 1 ? 0 : selected + 1; save(); paintBar(); open(button);
                get("grid").querySelector(`[data-priority-card="${priorities[selected].id}"]`)?.scrollIntoView({block: "nearest", behavior: "instant"});
            } else if (button.hasAttribute("data-priorities-restart")) {
                selected = 0; active = true; visible = true; save(); paintBar(); paintCards(); get("grid").scrollIntoView({block: "start", behavior: "instant"});
            } else if (button.hasAttribute("data-priorities-end")) {
                active = false; save(); paintBar(); clearSpotlight(); get("open").focus();
            }
        }
        const cancel = (event) => { event.preventDefault(); close(); };
        const trap = (event) => {
            if (event.key !== "Tab") return;
            const items = [...dialog.querySelectorAll("button:not(:disabled),[href],input,select,textarea,[tabindex='0']")].filter(element => element.getClientRects().length);
            if (!items.length) return;
            const first = items[0], last = items.at(-1);
            if (event.shiftKey && (document.activeElement === first || document.activeElement === document.getElementById("erp-title"))) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        };
        const refresh = () => { if (dialog.open) paintCards(); clearTimeout(timer); timer = setTimeout(spotlight, 180); };
        const pageShow = () => { restore(); paintBar(); refresh(); };
        root.addEventListener("click", click); dialog.addEventListener("cancel", cancel); dialog.addEventListener("keydown", trap);
        globalThis.addEventListener("resize", position); document.addEventListener("scroll", position, true);
        globalThis.addEventListener("pageshow", pageShow);
        document.addEventListener("enterprise:render", refresh); document.addEventListener("enterprise:tour-context", refresh);
        paintBar();
        return Object.freeze({open, close, refresh, get selected() {return selected;}, destroy() {
            active = false; clearTimeout(timer); clearSpotlight(); if (dialog.open) dialog.close(); paintBar();
            root.removeEventListener("click", click); dialog.removeEventListener("cancel", cancel); dialog.removeEventListener("keydown", trap);
            globalThis.removeEventListener("resize", position); document.removeEventListener("scroll", position, true);
            globalThis.removeEventListener("pageshow", pageShow);
            document.removeEventListener("enterprise:render", refresh); document.removeEventListener("enterprise:tour-context", refresh);
        }});
    }

    function mount(root, actions = {}) {
        if (!root || root.dataset.tourMounted) return null;
        root.dataset.tourMounted = "true";
        const get = (name) => root.querySelector(`[data-tour-${name}]`);
        const dialog = get("dialog"),
            email = get("email"),
            clock = new StoryClock();
        const media = globalThis.matchMedia("(prefers-reduced-motion: reduce)");
        const priorityTour = mountPriorities(root, actions, () => setPlaying(false));
        let returnFocus = null,
            emailFocus = null,
            frame = null,
            lastTime = null,
            previousIndex = -1;
        const text = (name, value) => {
            get(name).textContent = value;
        };
        function paint() {
            const scene = {
                ...scenes[clock.index],
                ...(actions.describeScene?.(clock.index, scenes[clock.index]) ||
                    {}),
            };
            root.dataset.tourScene = String(clock.index);
            root.dataset.tourPlaying = String(clock.playing);
            text("count", `Scene ${clock.index + 1} of ${scenes.length}`);
            text("role", scene.role);
            text("title", scene.title);
            text("text", scene.text);
            text("detail", scene.detail);
            text("play", clock.playing ? "Pause tour" : "Play tour");
            get("play").setAttribute("aria-pressed", String(clock.playing));
            get("back").disabled = clock.index === 0;
            text(
                "next",
                clock.index === scenes.length - 1
                    ? "Replay from start"
                    : "Next scene",
            );
            get("try").hidden =
                actions.hideTrial === true || clock.index !== scenes.length - 1;
            get("try").disabled = typeof actions.onTryIt !== "function";
            get("try").textContent =
                typeof actions.onTryIt === "function"
                    ? "Try it out"
                    : "Controlled trial connection pending";
            get("trial-status").hidden =
                actions.hideTrial === true ||
                clock.index !== scenes.length - 1 ||
                typeof actions.onTryIt === "function";
            get("email-open").hidden =
                actions.emailPreview === false ||
                ![1, 4, 5].includes(clock.index);
            get("screen").disabled =
                actions.canNavigate?.(clock.index) === false;
            get("screen").dataset.screen = scene.section;
            get("progress").value = clock.elapsed;
            get("progress").setAttribute(
                "aria-valuetext",
                `${Math.floor(clock.elapsed / 1000)} of 180 seconds of active playback`,
            );
            const seconds = Math.floor(clock.elapsed / 1000);
            text(
                "time",
                `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, "0")} / 3:00`,
            );
            root.querySelectorAll("[data-tour-scene]").forEach((button) =>
                button.setAttribute(
                    "aria-current",
                    Number(button.dataset.tourScene) === clock.index
                        ? "step"
                        : "false",
                ),
            );
            if (previousIndex !== clock.index) {
                const holder = get("cards");
                holder.replaceChildren(
                    ...scene.cards.map(([title, body], index) => {
                        const article = document.createElement("article");
                        article.className = "ert-story-card";
                        const number = document.createElement("span");
                        number.className = "ert-card-number";
                        number.textContent = String(index + 1).padStart(2, "0");
                        const heading = document.createElement("h3");
                        heading.textContent = title;
                        const copy = document.createElement("p");
                        copy.textContent = body;
                        article.append(number, heading, copy);
                        return article;
                    }),
                );
                previousIndex = clock.index;
            }
            actions.onScene?.(clock.index, scene);
        }
        function stopFrame() {
            if (frame !== null) cancelAnimationFrame(frame);
            frame = null;
            lastTime = null;
        }
        function loop(time) {
            if (!dialog.open || !clock.playing) {
                stopFrame();
                return;
            }
            if (lastTime !== null)
                clock.tick(Math.min(1000, Math.max(0, time - lastTime)));
            lastTime = time;
            paint();
            frame = requestAnimationFrame(loop);
        }
        function setPlaying(playing) {
            clock.playing =
                playing &&
                dialog.open &&
                !email.open &&
                !document.hidden &&
                clock.elapsed < clock.duration;
            stopFrame();
            paint();
            if (clock.playing) frame = requestAnimationFrame(loop);
        }
        function close() {
            if (email.open) email.close();
            setPlaying(false);
            if (dialog.open) dialog.close();
            returnFocus?.focus();
        }
        function open(trigger = get("open")) {
            if (dialog.open) return;
            returnFocus = trigger;
            clock.restart();
            previousIndex = -1;
            dialog.showModal();
            paint();
            get("title").focus();
            setPlaying(!media.matches);
        }
        function seek(index) {
            clock.seek(index);
            paint();
            get("title").focus();
        }
        function showEmail(trigger) {
            setPlaying(false);
            emailFocus = trigger;
            email.showModal();
            get("email-title").focus();
        }
        function closeEmail() {
            email.close();
            emailFocus?.focus();
        }
        root.addEventListener("click", (event) => {
            const button = event.target.closest("button");
            if (!button || !root.contains(button)) return;
            if (button.hasAttribute("data-tour-open")) open(button);
            else if (button.hasAttribute("data-tour-close")) close();
            else if (button.hasAttribute("data-tour-play"))
                setPlaying(!clock.playing);
            else if (button.hasAttribute("data-tour-back"))
                seek(clock.index - 1);
            else if (button.hasAttribute("data-tour-next")) {
                if (clock.index === scenes.length - 1) {
                    clock.restart();
                    paint();
                    get("title").focus();
                } else seek(clock.index + 1);
            } else if (button.hasAttribute("data-tour-restart")) {
                clock.restart();
                paint();
                get("title").focus();
            } else if (button.hasAttribute("data-tour-scene")) {
                setPlaying(false);
                seek(Number(button.dataset.tourScene));
            } else if (button.hasAttribute("data-tour-email-open"))
                showEmail(button);
            else if (button.hasAttribute("data-tour-email-close")) closeEmail();
            else if (button.hasAttribute("data-tour-email-cta")) {
                closeEmail();
                close();
                if (typeof actions.onSampleRequest === "function")
                    actions.onSampleRequest("sample-request-audio");
                else
                    text(
                        "status",
                        "Sample request R-101: audio check, owned by Operations. This preview did not send a message or change a record.",
                    );
            } else if (button.hasAttribute("data-tour-screen")) {
                close();
                actions.onNavigate?.(scenes[clock.index].section, clock.index);
            } else if (button.hasAttribute("data-tour-try")) {
                close();
                actions.onTryIt?.();
            }
        });
        dialog.addEventListener("cancel", (event) => {
            event.preventDefault();
            close();
        });
        email.addEventListener("cancel", (event) => {
            event.preventDefault();
            closeEmail();
        });
        dialog.addEventListener("keydown", (event) => {
            if (email.open || event.altKey || event.ctrlKey || event.metaKey)
                return;
            if (event.key === "ArrowRight") {
                event.preventDefault();
                seek(clock.index + 1);
            }
            if (event.key === "ArrowLeft") {
                event.preventDefault();
                seek(clock.index - 1);
            }
        });
        const visibility = () => {
            if (document.hidden) setPlaying(false);
        };
        const motion = () => {
            if (media.matches) setPlaying(false);
        };
        document.addEventListener("visibilitychange", visibility);
        media.addEventListener("change", motion);
        paint();
        return Object.freeze({
            open,
            close,
            clock,
            priorities: priorityTour,
            refresh() {
                previousIndex = -1;
                paint();
            },
            destroy() {
                close();
                priorityTour?.destroy();
                document.removeEventListener("visibilitychange", visibility);
                media.removeEventListener("change", motion);
                root.dataset.tourMounted = "";
            },
        });
    }
    globalThis.EverbranchReviewerTour = Object.freeze({
        mount,
        StoryClock,
        scenes,
        opening,
        priorities,
        executivePriorities,
        mountPriorities,
    });
})();
