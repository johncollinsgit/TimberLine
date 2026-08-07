const workflowMoments = {
    inbox: {
        label: "Customer question",
        headline: "“Can we get this ready for the fall market?”",
        name: "Maple & Reed",
        subtitle: "Wholesale buyer · first order",
        oneLabel: "REQUEST",
        one: "Line sheet + delivery question",
        oneMeta: "Received just now",
        twoLabel: "NEXT STEP",
        two: "Reply with current collection",
        twoMeta: "Assigned to Jordan",
        activity: "Customer, context, and next step are already in the same place.",
    },
    work: {
        label: "Today’s work",
        headline: "Monroe Avenue is ready to schedule.",
        name: "Monroe Avenue",
        subtitle: "Active customer · service visit",
        oneLabel: "WORK",
        one: "Panel upgrade, 2 hours on site",
        oneMeta: "Materials confirmed",
        twoLabel: "OWNER",
        two: "Jordan will lead the visit",
        twoMeta: "Thursday · 10:00 AM",
        activity: "The team sees the same plan before they leave the office.",
    },
    followup: {
        label: "Follow-up ready",
        headline: "A good handoff becomes a reason to come back.",
        name: "Maple & Reed",
        subtitle: "Completed order · 4 days ago",
        oneLabel: "MILESTONE",
        one: "Delivery confirmed and documented",
        oneMeta: "Order #1048",
        twoLabel: "FOLLOW-UP",
        two: "Send care notes and a thank-you",
        twoMeta: "Ready for review",
        activity: "The relationship stays useful after the work is done.",
    },
};

const industryDemos = {
    retail: {
        title: "See a product brand from storefront to follow-up.",
        site: { brand: "Juniper & Wick", kicker: "Hand-poured goods for slow rituals", title: "A collection your customers can feel before it arrives.", copy: "A warm, focused storefront for seasonal candles, wholesale accounts, and thoughtful reorders.", action: "Request the wholesale line sheet", proof: "A public collection, a clear wholesale path, and customer context that carries through." },
        fifth: "Follow-up",
        modules: {
            inbox: ["Wholesale inbox", "The shop’s first question already has the order context.", "BUYER QUESTION", "Can you hold 24 jars for the fall market?", "Received from Maple & Reed", "NEXT STEP", "Share current line sheet and delivery window", "Owner review today", "A reply is ready with the collection, inventory note, and next best action.", "Draft a helpful reply"],
            customers: ["Customer record", "A buyer relationship lives beyond a single order.", "BUYER", "Maple & Reed · first wholesale order", "Warm lead", "CONTEXT", "Fall market, local pickup preferred", "Last note added today", "The customer, order history, and preference sit in one quiet record.", "Add a customer note"],
            work: ["Order prep", "The next order is clear for the studio team.", "ORDER", "Autumn market assortment · 24 jars", "Packing begins Thursday", "OWNER", "Mara · collection prep", "Materials confirmed", "Work moves forward without a separate handoff thread.", "View order checklist"],
            messages: ["Buyer message", "A useful follow-up sounds like the brand, not a robot.", "THREAD", "Maple & Reed · wholesale", "2 messages today", "DRAFT", "The line sheet is ready whenever you are.", "Ready to review", "The relationship remains personal while the details stay organized.", "Send draft for review"],
            marketing: ["Marketing", "Email and text follow-up stay as personal as the products.", "EMAIL", "Autumn market collection preview", "Ready for 42 opted-in buyers", "TEXT", "Care notes for a completed candle order", "Consent confirmed", "Email and text are prepared from the same customer context, never sent from a guess.", "Prepare campaign"],
            followup: ["Follow-up", "A completed order becomes a thoughtful next conversation.", "MILESTONE", "Delivery confirmed this morning", "Order #1048", "NEXT STEP", "Send care notes and a thank-you", "Suggested for tomorrow", "Everbranch keeps the reason to come back close at hand.", "Create follow-up"],
        },
    },
    field: {
        title: "See a service business from request to completed work.",
        site: { brand: "Current & Air", kicker: "Electrical and HVAC service", title: "Clear help for the systems your day depends on.", copy: "A practical service website that makes it easy to request work, understand the team, and hear back quickly.", action: "Request service", proof: "A focused service site that turns a new request into shared office and field context." },
        fifth: "Schedule",
        modules: {
            inbox: ["Service inbox", "A homeowner request lands with the right context already attached.", "NEW REQUEST", "Panel inspection and cooling check", "Monroe Avenue", "NEXT STEP", "Confirm preferred window", "Office review today", "The request has a customer, an address, and a clean next step from the first click.", "Draft a response"],
            customers: ["Customer record", "The office sees the house, history, and preferences together.", "CUSTOMER", "Monroe Avenue household", "Active service customer", "LAST VISIT", "Spring tune-up · notes attached", "Technician Jordan", "The person calling never has to repeat their story.", "Add service note"],
            work: ["Job board", "The field team gets a plan before leaving the office.", "TODAY", "Monroe Avenue · panel upgrade", "10:00 AM to noon", "MATERIALS", "Breaker kit and surge protection", "Confirmed", "The job, materials, and customer update stay in the same view.", "Open job plan"],
            messages: ["Customer message", "A quick update keeps the customer informed without extra chasing.", "THREAD", "Monroe Avenue", "Service visit today", "DRAFT", "Jordan is on the way and has your notes.", "Ready to send", "The office can make a human update in the same place it manages the work.", "Send customer update"],
            marketing: ["Marketing", "Useful service reminders can meet customers by email or text.", "EMAIL", "Seasonal HVAC tune-up reminder", "Ready for opted-in homeowners", "TEXT", "Your visit is confirmed for Thursday", "Consent confirmed", "The team can prepare a practical email or text without losing the service context.", "Prepare service campaign"],
            followup: ["Schedule", "A new request becomes a real place on the team’s day.", "OPEN WINDOW", "Thursday · 10:00 AM", "Jordan is available", "CONFIRM", "Offer the homeowner this arrival window", "One click to send", "Scheduling stays connected to the work and the customer.", "Offer time window"],
        },
    },
    projects: {
        title: "See project work from a first inquiry to a clean handoff.",
        site: { brand: "Northline Build", kicker: "Thoughtful spaces, built to last", title: "A project site that makes the first conversation feel considered.", copy: "Introduce the work clearly, invite the right inquiry, and make the path from interest to project feel calm.", action: "Start a project conversation", proof: "A clear public front door, connected to approvals, materials, and the people moving the project." },
        fifth: "Projects",
        modules: {
            inbox: ["Project inbox", "A new renovation inquiry arrives ready for the first review.", "NEW INQUIRY", "Kitchen and mudroom renovation", "Cedar Lane", "NEXT STEP", "Confirm project fit and consultation", "Owner review today", "The opening conversation starts with useful context instead of a loose email.", "Draft a project reply"],
            customers: ["Client record", "A client, a home, and the project decisions stay connected.", "CLIENT", "Cedar Lane family", "Consultation confirmed", "PREFERENCE", "Natural oak and warm stone", "Notes shared with team", "The decisions that matter are easy to find when the work begins.", "Add client context"],
            work: ["Project work", "The next handoff is visible before it becomes a bottleneck.", "CURRENT PHASE", "Materials approval", "Due Friday", "OWNER", "Leah · project lead", "Samples ready", "The team can see what is decided, waiting, and moving next.", "Open project board"],
            messages: ["Client message", "A project update gives the client confidence without another status meeting.", "THREAD", "Cedar Lane renovation", "3 teammates included", "DRAFT", "The material samples are ready for your review.", "Ready to review", "Approvals and conversation remain connected to the project record.", "Send project update"],
            marketing: ["Marketing", "Project stories can become thoughtful email and text touchpoints.", "EMAIL", "Before-and-after project journal", "Ready for past clients", "TEXT", "New project update available", "Consent confirmed", "Long-form email and concise text updates draw from the same approved project story.", "Prepare project campaign"],
            followup: ["Projects", "A clear decision keeps the next phase moving.", "APPROVAL", "Kitchen material palette", "Waiting on client", "NEXT STEP", "Share the selected samples", "Prepared for review", "The handoff is visible to everyone responsible for the next move.", "Request approval"],
        },
    },
    studio: {
        title: "See an independent studio from portfolio to client relationship.",
        site: { brand: "Field Notes Studio", kicker: "Identity and spaces with a sense of place", title: "A studio website that leaves room for the work to speak.", copy: "Show a point of view, invite the right kind of inquiry, and keep the business behind the craft connected.", action: "Start an inquiry", proof: "A portfolio-led public site with a calm path into the client work that follows." },
        fifth: "Pipeline",
        modules: {
            inbox: ["Studio inbox", "The first inquiry carries the context needed to respond thoughtfully.", "NEW INQUIRY", "Brand refresh for a neighborhood hotel", "Referred by a past client", "NEXT STEP", "Share availability and discovery details", "Owner review today", "A good relationship begins with the details captured in the first moment.", "Draft a studio reply"],
            customers: ["Client record", "The history behind a client is present when the next ask arrives.", "CLIENT", "Harbor House", "Discovery call booked", "CONTEXT", "Hospitality brand · fall opening", "Referral source saved", "The relationship can feel personal without living in someone’s memory.", "Add client note"],
            work: ["Studio work", "The work has a next step without flattening the creative process.", "CURRENT WORK", "Discovery deck and project scope", "Due Tuesday", "OWNER", "Avery · creative direction", "References collected", "Tasks, files, and the client context sit beside one another.", "Open work plan"],
            messages: ["Client message", "A short, clear note keeps a creative project moving.", "THREAD", "Harbor House", "Discovery phase", "DRAFT", "We pulled together a few directions to discuss.", "Ready to review", "The message is tied to the work, the client, and what needs to happen next.", "Share update"],
            marketing: ["Marketing", "Email and text can extend the studio’s point of view with care.", "EMAIL", "New work and a short studio note", "Ready for subscribed readers", "TEXT", "Your discovery recap is ready", "Consent confirmed", "Campaigns and personal project messages stay distinct while sharing a useful client record.", "Prepare studio campaign"],
            followup: ["Pipeline", "A useful follow-up keeps the next opportunity close.", "FOLLOW-UP", "Check in after discovery call", "Tomorrow morning", "NEXT STEP", "Send a concise recap and scope", "Prepared for review", "The studio keeps momentum without turning relationship-building into a spreadsheet.", "Create follow-up"],
        },
    },
};

function setText(root, selector, value) {
    const element = root.querySelector(selector);
    if (element) element.textContent = value;
}

function mountFilm(root) {
    const dialog = document.querySelector("[data-studio-film]");
    const opener = root.querySelector("[data-studio-film-open]");
    const closeButton = dialog?.querySelector("[data-studio-film-close]");
    if (!dialog || !opener || !closeButton) return;

    let restoreFocusTo = null;
    opener.addEventListener("click", () => {
        restoreFocusTo = opener;
        dialog.showModal();
        closeButton.focus();
    });
    closeButton.addEventListener("click", () => dialog.close());
    dialog.addEventListener("click", (event) => {
        if (event.target === dialog) dialog.close();
    });
    dialog.addEventListener("close", () => restoreFocusTo?.focus());
}

function mountHeroSceneRotation(reducedMotion) {
    const hero = document.querySelector("[data-studio-hero]");
    const slides = hero ? [...hero.querySelectorAll("[data-studio-hero-slide]")] : [];
    if (!hero || slides.length < 2) return;

    let activeIndex = slides.findIndex((slide) => slide.classList.contains("is-active"));
    activeIndex = activeIndex >= 0 ? activeIndex : 0;
    const activate = (index) => {
        slides.forEach((slide, slideIndex) => slide.classList.toggle("is-active", slideIndex === index));
    };

    activate(activeIndex);
    hero.dataset.studioHeroRotation = reducedMotion ? "reduced" : "active";
    if (reducedMotion) return;

    const advance = () => {
        activeIndex = (activeIndex + 1) % slides.length;
        activate(activeIndex);
    };

    let interval = window.setInterval(advance, 7000);
    document.addEventListener("visibilitychange", () => {
        window.clearInterval(interval);
        if (!document.hidden) interval = window.setInterval(advance, 7000);
    });
}

function mountIndustryDemo(reducedMotion) {
    const demo = document.querySelector("[data-industry-demo]");
    const options = [...document.querySelectorAll("[data-industry-option]")];
    if (!demo || options.length === 0) return;

    const title = demo.querySelector("[data-industry-demo-title]");
    const status = demo.querySelector("[data-industry-status]");
    const frame = demo.querySelector("[data-industry-frame]");
    const siteImage = demo.querySelector("[data-industry-site-image]");
    const panes = [...demo.querySelectorAll("[data-industry-pane]")];
    const viewButtons = [...demo.querySelectorAll("[data-industry-view]")];
    const workspaceButtons = [...demo.querySelectorAll("[data-industry-workspace-nav]")];
    const fifthButton = demo.querySelector("[data-industry-workspace-fifth]");
    const siteAction = demo.querySelector("[data-industry-site-action]");
    const adminButton = demo.querySelector("[data-industry-admin]");
    const messageAction = demo.querySelector("[data-industry-message-action]");
    const siteNavigation = [...demo.querySelectorAll("[data-industry-site-nav]")];
    const timers = new Set();
    let selectedKey = null;
    let selectedModule = "inbox";

    const cancelHandoff = () => {
        timers.forEach((timer) => window.clearTimeout(timer));
        timers.clear();
        frame.classList.remove("is-handoff");
    };
    const schedule = (callback, delay) => {
        const timer = window.setTimeout(() => {
            timers.delete(timer);
            callback();
        }, delay);
        timers.add(timer);
    };
    const setText = (selector, value) => {
        const element = demo.querySelector(selector);
        if (element) element.textContent = value;
    };
    const setView = (view, announce = true) => {
        panes.forEach((pane) => {
            const active = pane.dataset.industryPane === view;
            pane.classList.toggle("is-active", active);
            pane.hidden = !active;
        });
        viewButtons.forEach((button) => button.setAttribute("aria-selected", String(button.dataset.industryView === view)));
        if (announce) status.textContent = view === "website" ? "Showing the public website example." : "Showing the fictional Everbranch workspace example.";
    };
    const renderWorkspace = (moduleKey) => {
        const current = industryDemos[selectedKey];
        const values = current?.modules[moduleKey];
        if (!values) return;
        selectedModule = moduleKey;
        workspaceButtons.forEach((button) => button.setAttribute("aria-pressed", String(button.dataset.industryWorkspaceNav === moduleKey)));
        const [label, heading, firstLabel, first, firstMeta, secondLabel, second, secondMeta, message, action] = values;
        setText("[data-industry-workspace-label]", label);
        setText("[data-industry-workspace-title]", heading);
        setText("[data-industry-card-one-label]", firstLabel);
        setText("[data-industry-card-one]", first);
        setText("[data-industry-card-one-meta]", firstMeta);
        setText("[data-industry-card-two-label]", secondLabel);
        setText("[data-industry-card-two]", second);
        setText("[data-industry-card-two-meta]", secondMeta);
        setText("[data-industry-message]", message);
        setText("[data-industry-message-action]", action);
        demo.querySelector("[data-industry-workspace-message]")?.classList.remove("is-replied");
    };
    const render = (key, option) => {
        const current = industryDemos[key];
        if (!current) return;
        selectedKey = key;
        cancelHandoff();
        options.forEach((card) => {
            const active = card === option;
            card.classList.toggle("is-selected", active);
            card.setAttribute("aria-pressed", String(active));
        });
        demo.hidden = false;
        title.textContent = current.title;
        setText("[data-industry-site-brand]", current.site.brand);
        setText("[data-industry-site-kicker]", current.site.kicker);
        setText("[data-industry-site-title]", current.site.title);
        setText("[data-industry-site-copy]", current.site.copy);
        setText("[data-industry-site-action]", current.site.action);
        setText("[data-industry-site-proof]", current.site.proof);
        setText("[data-industry-site-result]", "Fictional website example");
        fifthButton.textContent = current.fifth;
        if (siteImage) siteImage.src = option.querySelector("img")?.currentSrc || option.querySelector("img")?.src || "";
        renderWorkspace("inbox");
        setView("website", false);
        status.textContent = `${current.site.brand} website example selected. Use the Website and Everbranch workspace tabs to explore.`;
        demo.scrollIntoView({ behavior: reducedMotion ? "auto" : "smooth", block: "nearest" });
        if (reducedMotion) return;
        schedule(() => {
            frame.classList.add("is-handoff");
            schedule(() => {
                frame.classList.remove("is-handoff");
                setView("workspace", false);
                status.textContent = "The example website is now connected to its Everbranch workspace. Explore the workspace navigation.";
            }, 1250);
        }, 750);
    };

    options.forEach((option) => option.addEventListener("click", () => render(option.dataset.industryOption, option)));
    viewButtons.forEach((button) => button.addEventListener("click", () => {
        cancelHandoff();
        setView(button.dataset.industryView);
    }));
    workspaceButtons.forEach((button) => button.addEventListener("click", () => renderWorkspace(button.dataset.industryWorkspaceNav)));
    adminButton?.addEventListener("click", () => {
        cancelHandoff();
        setView("workspace");
        status.textContent = "Opening the fictional Everbranch workspace behind this website.";
    });
    siteAction?.addEventListener("click", () => {
        setText("[data-industry-site-result]", "Request received. The office can now see it in Everbranch.");
        status.textContent = "The fictional website request is ready in the workspace example.";
    });
    siteNavigation.forEach((button) => button.addEventListener("click", () => {
        setText("[data-industry-site-result]", "This interactive example keeps visitors on the public site.");
    }));
    messageAction?.addEventListener("click", () => {
        demo.querySelector("[data-industry-workspace-message]")?.classList.add("is-replied");
        setText("[data-industry-message]", "Reply prepared. The next useful update is ready for a human review.");
        setText("[data-industry-message-action]", "Reply prepared");
        status.textContent = "A fictional message reply has been prepared for review.";
    });
}

export async function mountPublicStudioNow() {
    const root = document.querySelector("[data-studio-story]");
    if (!root || root.dataset.studioMounted === "true") return;
    root.dataset.studioMounted = "true";

    const frame = root.querySelector("[data-studio-frame]");
    const triggers = [...root.querySelectorAll("[data-studio-step]")];
    const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    mountHeroSceneRotation(reducedMotion);
    mountIndustryDemo(reducedMotion);

    const applyMoment = async (key, animate = !reducedMotion) => {
        const moment = workflowMoments[key];
        if (!moment || !frame) return;
        triggers.forEach((trigger) => {
            const active = trigger.dataset.studioStep === key;
            trigger.classList.toggle("is-active", active);
            trigger.setAttribute("aria-pressed", String(active));
        });

        const paint = () => {
            setText(frame, "[data-studio-frame-label]", moment.label);
            setText(frame, "[data-studio-frame-headline]", moment.headline);
            setText(frame, "[data-studio-frame-name]", moment.name);
            setText(frame, "[data-studio-frame-subtitle]", moment.subtitle);
            setText(frame, "[data-studio-card-one-label]", moment.oneLabel);
            setText(frame, "[data-studio-card-one]", moment.one);
            setText(frame, "[data-studio-card-one-meta]", moment.oneMeta);
            setText(frame, "[data-studio-card-two-label]", moment.twoLabel);
            setText(frame, "[data-studio-card-two]", moment.two);
            setText(frame, "[data-studio-card-two-meta]", moment.twoMeta);
            setText(frame, "[data-studio-frame-activity]", moment.activity);
            setText(root, "[data-studio-step-status]", `Showing ${moment.label.toLowerCase()}.`);
        };

        if (!animate) {
            paint();
            return;
        }

        const { gsap } = await import("gsap");
        const targets = frame.querySelectorAll("[data-studio-frame-label], [data-studio-frame-headline], .eb-studio-product-frame__person, .eb-studio-product-frame__cards article, [data-studio-frame-activity]");
        gsap.to(targets, { opacity: 0, y: 8, duration: 0.13, stagger: 0.015, onComplete: () => {
            paint();
            gsap.to(targets, { opacity: 1, y: 0, duration: 0.32, stagger: 0.03, ease: "power2.out" });
        } });
    };

    triggers.forEach((trigger) => trigger.addEventListener("click", () => applyMoment(trigger.dataset.studioStep)));
    mountFilm(document);

    if (reducedMotion) return;

    const [{ gsap }, { ScrollTrigger }] = await Promise.all([import("gsap"), import("gsap/ScrollTrigger")]);
    gsap.registerPlugin(ScrollTrigger);
    const media = gsap.matchMedia();
    media.add("(min-width: 841px)", () => {
        gsap.fromTo("[data-studio-reveal] > *", { opacity: 0, y: 24 }, { opacity: 1, y: 0, duration: 0.72, stagger: 0.1, ease: "power2.out", delay: 0.12 });
        gsap.fromTo(frame, { opacity: 0, y: 32, rotate: 1.2 }, { opacity: 1, y: 0, rotate: 0, duration: 0.8, ease: "power2.out", scrollTrigger: { trigger: root, start: "top 70%", once: true } });
    });
}
