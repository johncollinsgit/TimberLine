export function mountRickrollStoryNow() {
    const root = document.querySelector("[data-rickroll-story]");
    const video = root?.querySelector("[data-rickroll-intro]");
    const handoff = root?.querySelector("[data-rickroll-handoff]");
    const iframe = handoff?.querySelector("iframe[data-rickroll-src]");
    const soundButton = handoff?.querySelector("[data-rickroll-sound]");
    const gate = root?.querySelector("[data-rickroll-gate]");
    const startButton = gate?.querySelector("[data-rickroll-start]");
    if (!root || !video || !handoff || !iframe || !soundButton || !gate || !startButton || root.dataset.rickrollMounted === "true") return;
    root.dataset.rickrollMounted = "true";

    const reveal = () => {
        if (handoff.hidden) {
            video.pause();
            iframe.src = iframe.dataset.rickrollSrc;
            handoff.hidden = false;
        }
    };

    startButton.addEventListener("click", () => {
        gate.hidden = true;
        video.muted = false;
        video.play().catch(() => {
            video.muted = true;
            video.play();
        });
    }, { once: true });

    soundButton.addEventListener("click", () => {
        iframe.contentWindow?.postMessage(JSON.stringify({ event: "command", func: "unMute", args: [] }), "https://www.youtube-nocookie.com");
        iframe.contentWindow?.postMessage(JSON.stringify({ event: "command", func: "playVideo", args: [] }), "https://www.youtube-nocookie.com");
        soundButton.hidden = true;
    });

    video.addEventListener("timeupdate", () => {
        if (video.currentTime >= 3.8) reveal();
    });
    video.addEventListener("ended", reveal);
}
