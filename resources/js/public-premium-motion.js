const prefersReducedMotionQuery = window.matchMedia("(prefers-reduced-motion: reduce)");
const coarsePointerQuery = window.matchMedia("(pointer: coarse)");
const finePointerQuery = window.matchMedia("(pointer: fine)");

function enablePublicPremiumMotion() {
    const body = document.body;
    if (!body || body.dataset.premiumMotion !== "public") {
        return;
    }

    const root = document.documentElement;
    const intro = document.getElementById("intro-logo");
    const ambient = document.getElementById("site-ambient");
    const revealNodes = Array.from(document.querySelectorAll("[data-reveal]"));
    const depthNodes = Array.from(document.querySelectorAll("[data-depth]"));
    const prefersReducedMotion = prefersReducedMotionQuery.matches;

    if (prefersReducedMotion) {
        intro?.remove();
        ambient?.remove();
        revealNodes.forEach((node) => node.classList.add("is-visible"));
        return;
    }

    body.classList.add("has-premium-motion");

    const introSeen = window.sessionStorage.getItem("fb_intro_seen") === "1";
    if (intro && !introSeen) {
        window.addEventListener("load", () => {
            window.sessionStorage.setItem("fb_intro_seen", "1");
            setTimeout(() => intro.classList.add("is-hidden"), 1200);
            setTimeout(() => intro.remove(), 2150);
        }, { once: true });
    } else {
        intro?.remove();
    }

    if ("IntersectionObserver" in window) {
        const observer = new IntersectionObserver(
            (entries) => {
                entries.forEach((entry) => {
                    if (!entry.isIntersecting) {
                        return;
                    }

                    entry.target.classList.add("is-visible");
                    observer.unobserve(entry.target);
                });
            },
            {
                threshold: 0.14,
                rootMargin: "0px 0px -8% 0px",
            }
        );

        revealNodes.forEach((node) => observer.observe(node));
    } else {
        revealNodes.forEach((node) => node.classList.add("is-visible"));
    }

    let currentX = window.innerWidth * 0.5;
    let currentY = window.innerHeight * 0.35;
    let targetX = currentX;
    let targetY = currentY;
    let rafId = null;

    const animate = () => {
        currentX += (targetX - currentX) * 0.08;
        currentY += (targetY - currentY) * 0.08;

        root.style.setProperty("--mx", `${currentX}px`);
        root.style.setProperty("--my", `${currentY}px`);

        depthNodes.forEach((node) => {
            const depth = Number.parseFloat(node.dataset.depth || "8");
            const deltaX = ((currentX / window.innerWidth) - 0.5) * depth;
            const deltaY = ((currentY / window.innerHeight) - 0.5) * depth;
            node.style.transform = `translate3d(${deltaX}px, ${deltaY}px, 0)`;
        });

        rafId = window.requestAnimationFrame(animate);
    };

    const enableAmbient = finePointerQuery.matches;
    if (enableAmbient) {
        rafId = window.requestAnimationFrame(animate);

        window.addEventListener(
            "pointermove",
            (event) => {
                targetX = event.clientX;
                targetY = event.clientY;
                body.classList.add("is-pointer-active");
            },
            { passive: true }
        );

        window.addEventListener(
            "pointerleave",
            () => {
                body.classList.remove("is-pointer-active");
                targetX = window.innerWidth * 0.5;
                targetY = window.innerHeight * 0.35;
            },
            { passive: true }
        );
    }

    window.addEventListener(
        "pointerdown",
        (event) => {
            if (!coarsePointerQuery.matches) {
                return;
            }

            const ripple = document.createElement("span");
            ripple.className = "touch-ripple";
            ripple.style.left = `${event.clientX}px`;
            ripple.style.top = `${event.clientY}px`;
            document.body.appendChild(ripple);
            window.setTimeout(() => ripple.remove(), 760);
        },
        { passive: true }
    );

    window.addEventListener(
        "beforeunload",
        () => {
            if (rafId) {
                window.cancelAnimationFrame(rafId);
            }
        },
        { once: true }
    );
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", enablePublicPremiumMotion, { once: true });
} else {
    enablePublicPremiumMotion();
}
