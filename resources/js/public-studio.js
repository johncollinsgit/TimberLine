const workflowMoments = {
    inbox: { label: "Customer question", headline: "“Can we get this ready for the fall market?”", name: "Maple & Reed", subtitle: "Wholesale buyer · first order", oneLabel: "REQUEST", one: "Line sheet + delivery question", oneMeta: "Received just now", twoLabel: "NEXT STEP", two: "Reply with current collection", twoMeta: "Assigned to Jordan", activity: "Customer, context, and next step are already in the same place." },
    work: { label: "Today’s work", headline: "Monroe Avenue is ready to schedule.", name: "Monroe Avenue", subtitle: "Active customer · service visit", oneLabel: "WORK", one: "Panel upgrade, 2 hours on site", oneMeta: "Materials confirmed", twoLabel: "OWNER", two: "Jordan will lead the visit", twoMeta: "Thursday · 10:00 AM", activity: "The team sees the same plan before they leave the office." },
    followup: { label: "Follow-up ready", headline: "A good handoff becomes a reason to come back.", name: "Maple & Reed", subtitle: "Completed order · 4 days ago", oneLabel: "MILESTONE", one: "Delivery confirmed and documented", oneMeta: "Order #1048", twoLabel: "FOLLOW-UP", two: "Send care notes and a thank-you", twoMeta: "Ready for review", activity: "The relationship stays useful after the work is done." },
};

function setText(root, selector, value) {
    const element = root.querySelector(selector);
    if (element) element.textContent = value;
}

function mountFilm(root) {
    const dialog = document.querySelector("[data-studio-film]");
    const opener = root.querySelector("[data-studio-film-open]");
    const closeButton = dialog?.querySelector("[data-studio-film-close]");
    const video = dialog?.querySelector("[data-studio-film-video]");
    const source = video?.querySelector("[data-studio-film-src]");
    if (!dialog || !opener || !closeButton) return;

    let restoreFocusTo = null;
    opener.addEventListener("click", () => {
        restoreFocusTo = opener;
        dialog.showModal();
        closeButton.focus();
        if (source?.dataset.studioFilmSrc && !source.getAttribute("src")) {
            source.setAttribute("src", source.dataset.studioFilmSrc);
            video.load();
        }
        video?.play().catch(() => {});
    });
    closeButton.addEventListener("click", () => dialog.close());
    dialog.addEventListener("click", (event) => {
        if (event.target === dialog) dialog.close();
    });
    dialog.addEventListener("close", () => {
        video?.pause();
        restoreFocusTo?.focus();
    });
}

function mountHeroSceneRotation(reducedMotion) {
    const hero = document.querySelector("[data-studio-hero]");
    const slides = hero ? [...hero.querySelectorAll("[data-studio-hero-slide]")] : [];
    if (!hero || slides.length < 2) return;

    let activeIndex = slides.findIndex((slide) => slide.classList.contains("is-active"));
    activeIndex = activeIndex >= 0 ? activeIndex : 0;
    const activate = (index) => slides.forEach((slide, slideIndex) => slide.classList.toggle("is-active", slideIndex === index));

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

function mountCapabilityGuide() {
    document.querySelectorAll('[data-capability-guide]').forEach((root) => {
        if (root.dataset.guideMounted === 'true') return;
        const tabs = [...root.querySelectorAll('[data-guide-tab]')];
        const panels = [...root.querySelectorAll('[data-guide-panel]')];
        if (!tabs.length || !panels.length) return;
        root.dataset.guideMounted = 'true';
        root.querySelector('.eb-guide__tabs')?.setAttribute('role', 'tablist');
        const activate = (key, updateHash = false) => {
            const selected = tabs.find((tab) => tab.dataset.guideTab === key) || tabs[0];
            tabs.forEach((tab) => {
                const active = tab === selected;
                tab.setAttribute('role', 'tab');
                tab.setAttribute('aria-controls', `guide-panel-${tab.dataset.guideTab}`);
                tab.setAttribute('aria-selected', String(active));
                tab.tabIndex = active ? 0 : -1;
            });
            panels.forEach((panel) => {
                const active = panel.dataset.guidePanel === selected.dataset.guideTab;
                panel.setAttribute('role', 'tabpanel');
                panel.tabIndex = 0;
                panel.hidden = !active;
                panel.classList.toggle('is-entering', active);
            });
            if (updateHash) window.history.replaceState(null, '', `#${selected.id}`);
        };
        const fromHash = () => tabs.find((tab) => [`#${tab.id}`, `#guide-panel-${tab.dataset.guideTab}`].includes(window.location.hash));
        tabs.forEach((tab, index) => {
            tab.addEventListener('click', (event) => {
                event.preventDefault();
                activate(tab.dataset.guideTab, true);
            });
            tab.addEventListener('keydown', (event) => {
                if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
                event.preventDefault();
                let next = index;
                if (event.key === 'ArrowLeft') next = (index - 1 + tabs.length) % tabs.length;
                if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
                if (event.key === 'Home') next = 0;
                if (event.key === 'End') next = tabs.length - 1;
                activate(tabs[next].dataset.guideTab, true);
                tabs[next].focus();
            });
        });
        window.addEventListener('hashchange', () => { const tab = fromHash(); if (tab) activate(tab.dataset.guideTab); });
        activate(fromHash()?.dataset.guideTab || tabs[0].dataset.guideTab);
    });
}

function mountDemoGuide() {
    const root = document.querySelector('[data-demo-guide]');
    if (!root || root.dataset.demoMounted === 'true') return;
    const tabs = [...root.querySelectorAll('[data-demo-tab]')];
    const panels = [...root.querySelectorAll('[data-demo-panel]')];
    root.dataset.demoMounted = 'true';
    root.querySelector('.eb-guide__tabs')?.setAttribute('role', 'tablist');
    const select = (tab) => {
        tabs.forEach((item) => {
            item.setAttribute('role', 'tab');
            item.setAttribute('aria-controls', `demo-panel-${item.dataset.demoTab}`);
            item.setAttribute('aria-selected', String(item === tab));
            item.tabIndex = item === tab ? 0 : -1;
        });
        panels.forEach((panel) => {
            panel.setAttribute('role', 'tabpanel');
            panel.tabIndex = 0;
            panel.hidden = panel.dataset.demoPanel !== tab.dataset.demoTab;
        });
    };
    tabs.forEach((tab, index) => {
        tab.addEventListener('click', (event) => { event.preventDefault(); select(tab); });
        tab.addEventListener('keydown', (event) => {
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (index + (event.key === 'ArrowLeft' ? -1 : 1) + tabs.length) % tabs.length;
            select(tabs[next]);
            tabs[next].focus();
        });
    });
    if (tabs[0]) select(tabs[0]);
}

export async function mountPublicStudioNow() {
    const root = document.querySelector("[data-studio-story]");
    if (!root || root.dataset.studioMounted === "true") return;
    root.dataset.studioMounted = "true";

    mountCapabilityGuide();
    mountDemoGuide();

    const frame = root.querySelector("[data-studio-frame]");
    const triggers = [...root.querySelectorAll("[data-studio-step]")];
    const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    mountHeroSceneRotation(reducedMotion);
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
        if (!animate) return paint();

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
