export function mountRickrollStoryNow() {
    const root = document.querySelector("[data-rickroll-story]");
    const video = root?.querySelector("[data-rickroll-intro]");
    const handoff = root?.querySelector("[data-rickroll-handoff]");
    const iframe = handoff?.querySelector("iframe[data-rickroll-src]");
    const soundButton = handoff?.querySelector("[data-rickroll-sound]");
    if (!root || !video || !handoff || !iframe || !soundButton || root.dataset.rickrollMounted === "true") return;
    root.dataset.rickrollMounted = "true";

    const reveal = () => {
        if (handoff.hidden) {
            video.pause();
            iframe.src = iframe.dataset.rickrollSrc;
            handoff.hidden = false;
        }
    };

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
