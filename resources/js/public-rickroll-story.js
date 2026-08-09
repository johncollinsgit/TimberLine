export function mountRickrollStoryNow() {
    const root = document.querySelector("[data-rickroll-story]");
    const video = root?.querySelector("[data-rickroll-intro]");
    const handoff = root?.querySelector("[data-rickroll-handoff]");
    if (!root || !video || !handoff || root.dataset.rickrollMounted === "true") return;
    root.dataset.rickrollMounted = "true";

    const reveal = () => {
        if (handoff.hidden) {
            video.pause();
            handoff.hidden = false;
        }
    };

    video.addEventListener("timeupdate", () => {
        if (video.currentTime >= 9.8) reveal();
    });
    video.addEventListener("ended", reveal);
}
