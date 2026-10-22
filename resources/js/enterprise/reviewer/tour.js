/* Shared presentation only. This story never calls a transport or writes sample records. */
(() => {
    "use strict";
    const opening =
        "Everbranch Enterprise is your campus operating system. It's designed to help the campus operate efficiently through automating tasks and tracking all the moving parts of campus life. It is your single source of truth for campus management.";
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

    function mount(root, actions = {}) {
        if (!root || root.dataset.tourMounted) return null;
        root.dataset.tourMounted = "true";
        const get = (name) => root.querySelector(`[data-tour-${name}]`);
        const dialog = get("dialog"),
            email = get("email"),
            clock = new StoryClock();
        const media = globalThis.matchMedia("(prefers-reduced-motion: reduce)");
        let returnFocus = null,
            emailFocus = null,
            frame = null,
            lastTime = null,
            previousIndex = -1;
        const text = (name, value) => {
            get(name).textContent = value;
        };
        function paint() {
            const scene = scenes[clock.index];
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
            get("try").hidden = clock.index !== scenes.length - 1;
            get("try").disabled = typeof actions.onTryIt !== "function";
            get("try").textContent =
                typeof actions.onTryIt === "function"
                    ? "Try it out"
                    : "Controlled trial connection pending";
            get("trial-status").hidden =
                clock.index !== scenes.length - 1 ||
                typeof actions.onTryIt === "function";
            get("email-open").hidden = ![1, 4, 5].includes(clock.index);
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
                actions.onNavigate?.(scenes[clock.index].section);
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
            destroy() {
                close();
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
    });
})();
