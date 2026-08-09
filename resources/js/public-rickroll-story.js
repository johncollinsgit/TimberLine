export function mountRickrollStoryNow() {
    const root = document.querySelector("[data-rickroll-story]");
    const video = root?.querySelector("[data-rickroll-intro]");
    const handoff = root?.querySelector("[data-rickroll-handoff]");
    const iframe = handoff?.querySelector("iframe[data-rickroll-src]");
    if (!root || !video || !handoff || !iframe || root.dataset.rickrollMounted === "true") return;
    root.dataset.rickrollMounted = "true";

    const reveal = () => {
        if (handoff.hidden) {
            video.pause();
            iframe.src = iframe.dataset.rickrollSrc;
            handoff.hidden = false;
        }
    };

    video.addEventListener("timeupdate", () => {
        if (video.currentTime >= 3.8) reveal();
    });
    video.addEventListener("ended", reveal);
}
