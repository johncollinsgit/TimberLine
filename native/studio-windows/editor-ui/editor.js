"use strict";
const $ = (id) => document.getElementById(id);
const esc = (value) =>
    String(value ?? "").replace(
        /[&<>"']/g,
        (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c],
    );
const num = (v, n = 0) => (Number.isFinite(Number(v)) ? Number(v) : n);
const clamp = (v, a, b) => Math.min(Math.max(v, a), b);
const fmt = (s) => Number(s || 0).toFixed(2);
const clock = (s) => {
    s = Math.max(0, Math.floor(s || 0));
    return Math.floor(s / 60) + ":" + String(s % 60).padStart(2, "0");
};
const tc = (s) => {
    const f = Math.max(0, Math.round((s || 0) * 30));
    return [Math.floor(f / 108000), Math.floor(f / 1800) % 60, Math.floor(f / 30) % 60, f % 30]
        .map((v) => String(v).padStart(2, "0"))
        .join(":");
};
const BUTTON = (label, fn, symbol = "", cls = "secondary") =>
    '<button type="button" class="' +
    cls +
    '" data-fn="' +
    esc(fn) +
    '">' +
    (symbol ? icon(symbol) : "") +
    esc(label) +
    "</button>";
let state = { project: null, media_urls: {}, revisions: [] },
    p = null,
    tab = "media",
    work = "edit",
    inspect = "episode",
    selectedSource = "",
    selectedCut = -1,
    selectedSegment = -1,
    selectedCue = -1;
let pos = 0,
    mode = "sequence",
    sourcePos = 0,
    playing = false,
    snap = true,
    tool = "pointer",
    zoom = 1,
    muted = false,
    showCaptions = true,
    filter = "",
    pending = false,
    loadedKey = "",
    nextSeek = null,
    lastSnapshot = "",
    polling = false;
let activeMenu = [],
    menuFocus = null,
    modalSubmit = null,
    drag = null,
    menuTrack = null,
    modalType = "",
    gapChecks = [],
    gapPreviewEnd = null;
const waveCache = new Map(),
    audioNodes = new Map();
let ctx = null,
    videoMeter = null;
const video = $("video"),
    shell = document.querySelector(".workspace");
const rail = [
    ["media", "Library", "grid"],
    ["captions", "Captions", "captions"],
    ["cuts", "Cuts", "cuts"],
    ["products", "Products", "tag"],
    ["sponsors", "Sponsors", "mic"],
];
const source = (id) => p?.sources?.find((s) => s.id === id);
function segments() {
    if (!p) return [];
    if (p.sequence?.length) return p.sequence.map((s) => ({ ...s }));
    const e = p.edit || {},
        t = source(e.camera);
    if (!t || e.end == null) return [];
    const cuts = (e.switches || [])
            .filter((x) => x.at > e.start && x.at < e.end)
            .sort((x, y) => x.at - y.at),
        out = [];
    let at = e.start,
        cam = e.camera;
    for (const x of cuts) {
        out.push({ camera: cam, start: at, end: x.at });
        at = x.at;
        cam = x.camera;
    }
    out.push({ camera: cam, start: at, end: e.end });
    return out;
}
const duration = () => segments().reduce((sum, s) => sum + Math.max(0, s.end - s.start), 0);
const starts = () => {
    let at = 0;
    return segments().map((s) => {
        const v = at;
        at += s.end - s.start;
        return v;
    });
};
function atProgram(t) {
    const seq = segments();
    let before = 0;
    for (let i = 0; i < seq.length; i++) {
        const s = seq[i],
            d = s.end - s.start;
        if (t < before + d - 0.00001 || i === seq.length - 1)
            return { ...s, i, before, local: s.start + clamp(t - before, 0, d), track: source(s.camera) };
        before += d;
    }
    return null;
}
function mapped(a, b) {
    let t = 0;
    const out = [];
    for (const s of segments()) {
        const x = Math.max(a, s.start),
            y = Math.min(b, s.end);
        if (y > x) out.push([t + x - s.start, t + y - s.start]);
        t += s.end - s.start;
    }
    return out;
}
const videoUrl = (id) => state.media_urls?.[id] || "/api/media/" + encodeURIComponent(id);
const thumb = (id) => state.thumbnail_urls?.[id] || "";
function toast(message) {
    if (!message) return;
    const el = document.createElement("div");
    el.className = "notification";
    el.textContent = message;
    $("toasts").append(el);
    setTimeout(() => el.remove(), 4200);
}
function setStatus(text) {
    $("status").textContent = text;
    document.body.classList.toggle("saving", Boolean(state.busy || pending));
}
async function command(action, body = {}, options = {}) {
    if (pending || state.busy) {
        toast("A Studio operation is still running.");
        return null;
    }
    closeMenu();
    pending = true;
    setStatus("Working…");
    try {
        const response = await fetch("/api/command", {
            method: "POST",
            headers: { "Content-Type": "application/json", "X-Studio-Request": "1" },
            body: JSON.stringify({ action, version: state.version, ...body }),
        });
        const data = await response.json();
        if (!response.ok || data.error) throw Error(data.error || "Studio could not apply the change.");
        if (data.state || Object.hasOwn(data, "project")) apply(data.state || data);
        else await refresh(true);
        if (options.message) toast(options.message);
        return data;
    } catch (e) {
        toast(e.message);
        setStatus(e.message);
        return null;
    } finally {
        pending = false;
        setStatus(state.status || "Ready");
    }
}
async function refresh(force = false) {
    if (polling && !force) return;
    polling = true;
    try {
        const response = await fetch("/api/state", { cache: "no-store" });
        if (!response.ok) throw Error("Studio connection unavailable");
        const data = await response.json();
        apply(data.state || data);
    } catch (e) {
        setStatus(e.message);
    } finally {
        polling = false;
    }
}
function apply(data) {
    const before = p?.episode_id;
    state = data;
    p = data.project || null;
    if (before !== p?.episode_id) {
        pause();
        pos = sourcePos = 0;
        selectedSegment = -1;
        selectedSource = p?.edit?.camera || p?.sources?.[0]?.id || "";
        selectedCut = -1;
        selectedCue = -1;
        inspect = "episode";
        mode = "sequence";
        filter = "";
        loadedKey = "";
        audioNodes.forEach((a) => a.el.pause());
        audioNodes.clear();
    }
    if (p && !source(selectedSource)) selectedSource = p.edit?.camera || p.sources?.[0]?.id || "";
    const sig = JSON.stringify([
        p,
        data.revisions || [],
        data.gaps || null,
        data.thumbnail_urls || {},
        data.waveform_urls || {},
    ]);
    if (sig !== lastSnapshot) {
        lastSnapshot = sig;
        pos = clamp(pos, 0, duration());
        draw();
        if (modalType === "gaps") showGapResults();
    }
    drawRecord();
    setStatus(data.status || (p ? "Saved locally" : "Ready"));
    $("diskState").innerHTML = "<i></i>" + esc(data.busy ? "Working" : "Local studio");
    if (modalType === "gaps" && !data.busy && data.gaps) showGapResults();
}
function draw() {
    drawTop();
    drawBin();
    drawInspector();
    drawTimeline();
    showFrame(false);
}
function drawRecord() {
    $("record").disabled = !p || Boolean(state.busy);
    $("record").setAttribute("aria-pressed", Boolean(state.recording));
    $("record").innerHTML = '<i class="record-dot"></i>' + (state.recording ? "Stop recording" : "Record");
}
function drawTop() {
    $("projectTitle").textContent = p?.title || "Workspace";
    $("welcome").hidden = !!p;
    $("monitorEmpty").hidden = !p || !!p.sources?.some((s) => s.video);
    $("record").disabled = !p;
    $("export").disabled = !p;
    $("play").disabled = !p || !p.sources?.some((s) => s.video);
    $("export").innerHTML = icon("export") + "Export";
    drawRecord();
    document
        .querySelectorAll("[data-work]")
        .forEach((b) => b.classList.toggle("selected", b.dataset.work === work));
    $("railItems").innerHTML = rail
        .map(
            ([id, label, ic]) =>
                '<button data-panel="' +
                id +
                '" class="' +
                (tab === id ? "active" : "") +
                '" data-tip="' +
                label +
                '" aria-label="' +
                label +
                '">' +
                icon(ic, 19) +
                "<span>" +
                label +
                "</span></button>",
        )
        .join("");
    const drive = String(state.library || "").match(/^[A-Za-z]:/);
    $("footerRight").innerHTML = icon("drive") + esc(drive ? "Local media · " + drive[0] : "Local media");
    $("footerRight").dataset.tip = state.library || "Local storage";
    $("monitorTitle").textContent = mode === "sequence" ? "Program monitor" : "Source monitor";
    $("monitorMode").innerHTML = esc(mode === "sequence" ? "Sequence" : "Source") + icon("chevron", 12);
}
const titles = {
    media: "Media library",
    captions: "Captions",
    cuts: "Social cuts",
    products: "Product tags",
    sponsors: "Sponsor rundown",
};
const items = () =>
    !p
        ? []
        : {
              media: p.sources,
              captions: p.captions,
              cuts: p.clips,
              products: p.product_tags,
              sponsors: p.sponsor_rundown,
          }[tab] || [];
function drawBin() {
    const rows = items();
    $("libraryTitle").textContent = titles[tab];
    $("binIcon").innerHTML = icon(rail.find((r) => r[0] === tab)[2]);
    $("binCount").textContent = rows.length;
    let toolbar = "";
    if (tab === "media")
        toolbar =
            '<div class="bin-bar media-actions">' +
            BUTTON("Import media", "import", "plus") +
            BUTTON("Social clip", "importSocial", "social") +
            BUTTON("", "importMenu", "chevron") +
            '</div><label class="search">' +
            icon("search", 13) +
            '<input id="search" aria-label="Search media" placeholder="Search media" value="' +
            esc(filter) +
            '"></label>';
    if (tab === "captions")
        toolbar =
            '<div class="bin-bar">' +
            BUTTON("Transcribe", "transcribe", "mic") +
            BUTTON("Add cue", "newCue", "plus") +
            BUTTON("Import", "importCaptions", "import") +
            "</div>";
    if (tab === "cuts")
        toolbar =
            '<div class="bin-bar">' +
            BUTTON("Suggest cuts", "suggest_clips", "spark") +
            BUTTON("Add cut", "newCut", "plus") +
            "</div>";
    if (tab === "products") toolbar = BUTTON("Suggest from catalog", "catalog_tags", "tag");
    if (tab === "sponsors") toolbar = BUTTON("Import rundown", "import_rundown", "import");
    $("binTools").innerHTML = toolbar;
    renderRows();
    $("binFoot").innerHTML =
        icon(tab === "media" ? "drive" : "layers", 13) +
        esc(
            tab === "media"
                ? rows.length + " project recording" + (rows.length === 1 ? "" : "s")
                : "Project timeline",
        );
    const importMore = $("binTools").querySelector('[data-fn="importMenu"]');
    if (importMore) {
        importMore.setAttribute("aria-label", "More import options");
        importMore.dataset.tip = "More import options";
    }
    if ($("search"))
        $("search").oninput = (e) => {
            filter = e.target.value;
            renderRows();
        };
}
function renderRows() {
    const host = $("binItems"),
        rows = items();
    host.replaceChildren();
    if (!rows.length) {
        host.innerHTML =
            tab === "media"
                ? '<div class="import-drop">' + icon("film", 22) + "<span>No media imported</span></div>"
                : '<div class="small-empty"><strong>' +
                  {
                      captions: "No caption cues",
                      cuts: "No social cuts",
                      products: "No product tags",
                      sponsors: "No sponsor bookings",
                  }[tab] +
                  "</strong>" +
                  esc(
                      {
                          captions: "Transcribe a voice track or add a timed cue.",
                          cuts: "Select an excerpt or generate suggestions from captions.",
                          products: "Import the maker catalog to find timed matches.",
                          sponsors: "Import the episode’s booked deliverables.",
                      }[tab],
                  ) +
                  "</div>";
        return;
    }
    rows.forEach((r, i) => {
        if (tab === "media" && !r.label.toLowerCase().includes(filter.toLowerCase())) return;
        const b = document.createElement("button");
        b.type = "button";
        b.dataset.row = i;
        if (tab === "media") {
            b.className = "source-row" + (selectedSource === r.id ? " selected" : "");
            b.draggable = true;
            b.dataset.source = r.id;
            const image = r.video ? thumb(r.id) : "";
            b.innerHTML =
                '<span class="source-thumb">' +
                icon(r.video ? "film" : r.kind === "music" ? "music" : "audio") +
                (image ? '<img loading="lazy" alt="" src="' + esc(image) + '">' : "") +
                '</span><span class="source-text"><strong>' +
                esc(r.label) +
                "</strong><small>" +
                esc(r.kind === "camera" ? "Video" : r.kind === "music" ? "Music" : "Voice") +
                " · " +
                clock(r.duration) +
                "</small></span>";
            b.addEventListener("dragstart", (e) =>
                e.dataTransfer.setData("application/x-studio-source", r.id),
            );
            b.ondblclick = () => {
                sourcePreview(r.id);
                if (r.video) togglePlay();
            };
            b.onclick = () => {
                selectedSource = r.id;
                inspect = "source";
                sourcePreview(r.id);
                drawBin();
                drawInspector();
            };
            b.oncontextmenu = (e) => {
                e.preventDefault();
                selectedSource = r.id;
                trackMenu(e, r);
            };
        } else {
            const label =
                tab === "captions"
                    ? r.text
                    : tab === "cuts"
                      ? r.label || r.kind
                      : tab === "products"
                        ? r.label || r.variant_id
                        : r.sponsor;
            const sub =
                tab === "sponsors"
                    ? r.kind + " · " + (r.placement == null ? "Unplaced" : clock(r.placement))
                    : clock(r.start) + " – " + clock(r.end);
            b.className =
                "list-row" +
                ((tab === "cuts" && selectedCut === i) || (tab === "captions" && selectedCue === i)
                    ? " selected"
                    : "");
            b.innerHTML =
                icon(
                    tab === "sponsors"
                        ? "mic"
                        : tab === "products"
                          ? "tag"
                          : tab === "cuts"
                            ? "cuts"
                            : "captions",
                    14,
                ) +
                '<span class="source-text"><strong>' +
                esc(label) +
                "</strong><small>" +
                esc(sub) +
                "</small></span>";
            b.onclick = () => {
                if (tab === "cuts") {
                    selectedCut = i;
                    inspect = "cut";
                    const s = source(r.camera);
                    if (s) {
                        selectedSource = s.id;
                        sourcePreview(s.id, r.start - (r.timeline === "source" ? 0 : s.offset));
                    }
                } else if (tab === "captions") {
                    selectedCue = i;
                    inspect = "cue";
                    const spans = mapped(r.start, r.end);
                    if (spans.length) seek(spans[0][0]);
                } else {
                    inspect = tab;
                    menuTrack = i;
                }
                drawInspector();
                renderRows();
            };
        }
        host.append(b);
    });
}
function sourcePreview(id, at = 0) {
    pause();
    selectedSource = id;
    mode = "source";
    sourcePos = Math.max(0, at);
    drawTop();
    showFrame(true);
}
function field(label, key, value, type = "text", extra = "") {
    return (
        '<label class="field"><span>' +
        esc(label) +
        '</span><input data-edit="' +
        key +
        '" type="' +
        type +
        '" value="' +
        esc(value ?? "") +
        '" ' +
        extra +
        "></label>"
    );
}
function selectField(label, key, value, opts) {
    return (
        '<label class="field"><span>' +
        esc(label) +
        '</span><select data-edit="' +
        key +
        '">' +
        opts
            .map(
                (x) =>
                    "<option " +
                    (x === value ? "selected" : "") +
                    ' value="' +
                    esc(x) +
                    '">' +
                    esc(x) +
                    "</option>",
            )
            .join("") +
        "</select></label>"
    );
}
function section(title, body, ic = "") {
    return (
        '<section class="section"><h3>' +
        (ic ? icon(ic, 13) : "") +
        esc(title) +
        "</h3>" +
        body +
        "</section>"
    );
}
function head(title, sub, ic) {
    return (
        '<div class="inspect-head"><div class="asset-badge">' +
        icon(ic, 17) +
        "</div><div><h3>" +
        esc(title) +
        "</h3><small>" +
        esc(sub) +
        "</small></div></div>"
    );
}
function drawInspector() {
    $("inspectTab").classList.toggle("selected", inspect !== "mixer");
    $("mixerTab").classList.toggle("selected", inspect === "mixer");
    const host = $("inspectorBody");
    let html = "";
    if (!p) {
        html =
            head("No episode open", "Project inspector", "clapper") +
            section(
                "Episode",
                "<p>Create or open an episode to edit its media, sequence and delivery settings.</p>" +
                    BUTTON("New episode", "new", "plus"),
            );
        host.innerHTML = html;
        return;
    }
    if (inspect === "mixer") {
        html = head("Audio mixer", "Project tracks", "sliders");
        const tracks = p.sources.filter((s) => s.audio);
        if (!tracks.length)
            html += section("Audio tracks", "<p>Import a recording with audio to mix it here.</p>");
        html += tracks
            .map(
                (t) =>
                    '<div class="mix-row" data-track="' +
                    esc(t.id) +
                    '"><header><span>' +
                    icon(t.kind === "music" ? "music" : "audio", 15) +
                    esc(t.label) +
                    '</span><button class="' +
                    (!t.muted ? "" : "muted") +
                    '" data-mute="' +
                    esc(t.id) +
                    '" aria-label="Toggle ' +
                    esc(t.label) +
                    '">' +
                    icon(!t.muted ? "volume" : "muted", 14) +
                    '</button></header><div class="meter"><i data-meter="' +
                    esc(t.id) +
                    '"></i></div><input type="range" min="-60" max="24" step=".5" value="' +
                    num(t.gain_db) +
                    '" data-gain="' +
                    esc(t.id) +
                    '" aria-label="' +
                    esc(t.label) +
                    ' gain"><output>' +
                    num(t.gain_db).toFixed(1) +
                    " dB</output></div>",
            )
            .join("");
    } else if (inspect === "deliver") {
        html =
            head("Delivery", "Approve and export", "export") +
            section(
                "Episode package",
                "<p>Render the master video, podcast audio, captions, trailer and shorts.</p>" +
                    BUTTON("Render revision", "render", "export"),
            );
        const revisions = state.revisions || [];
        html += section(
            "Revisions",
            revisions.length
                ? revisions
                      .map(
                          (r) =>
                              '<div class="revision"><strong>' +
                              esc(r.title || p.title) +
                              "</strong><span>" +
                              icon(r.approval === "approved" ? "check" : "clock", 12) +
                              esc(r.approval || "draft") +
                              "</span> <small>" +
                              esc(String(r.revision || r.id || "").slice(0, 8)) +
                              '</small><div class="inspect-actions"><button data-rev="' +
                              esc(r.revision || r.id) +
                              '" data-rev-action="review">Review</button><button data-rev="' +
                              esc(r.revision || r.id) +
                              '" data-rev-action="open_revision">Files</button><button data-rev="' +
                              esc(r.revision || r.id) +
                              '" data-rev-action="approve">Approve</button>' +
                              (r.approval === "approved" && r.visibility === "public"
                                  ? '<button data-rev="' +
                                    esc(r.revision || r.id) +
                                    '" data-rev-action="publish_frh">Publish to FRH</button>'
                                  : "") +
                              "</div></div>",
                      )
                      .join("")
                : "<p>No revisions rendered.</p>",
        );
    } else if (inspect === "segment" && segments()[selectedSegment]) {
        const s = segments()[selectedSegment],
            t = source(s.camera);
        html =
            head(t?.label || "Video segment", "Sequence segment " + (selectedSegment + 1), "timeline") +
            section(
                "Source trim",
                field("In (s)", "segmentIn", fmt(s.start), "number", 'step=".033"') +
                    field("Out (s)", "segmentOut", fmt(s.end), "number", 'step=".033"') +
                    '<div class="inspect-actions">' +
                    BUTTON("Set in", "trimIn", "markIn") +
                    BUTTON("Set out", "trimOut", "markOut") +
                    "</div>",
            ) +
            section(
                "Sequence",
                '<div class="inspect-actions">' +
                    BUTTON("Split", "split", "split") +
                    BUTTON("Duplicate", "duplicate", "copy") +
                    BUTTON("Remove", "deleteSegment", "trash") +
                    "</div>",
            );
    } else if (inspect === "source" && source(selectedSource)) {
        const t = source(selectedSource);
        html =
            head(
                t.label,
                (t.kind === "camera" ? "Video recording" : t.kind) + " · " + clock(t.duration),
                t.video ? "film" : "audio",
            ) +
            section(
                "Recording",
                field("Name", "sourceName", t.label) +
                    field("Sync (s)", "offset", t.offset, "number", 'step=".01"') +
                    field("Gain (dB)", "gain", t.gain_db, "number", 'step=".5" min="-60" max="24"') +
                    '<div class="inspect-actions">' +
                    BUTTON(
                        !t.muted ? "Mute track" : "Unmute track",
                        "muteTrack",
                        !t.muted ? "volume" : "muted",
                    ) +
                    (t.video ? BUTTON("Append", "append", "plus") : "") +
                    "</div>",
            );
        if (t.origin)
            html += section(
                "Source",
                "<p>" + esc(t.origin.platform) + " · " + esc(t.origin.filename) + "</p>",
            );
    } else if (inspect === "cut" && p.clips?.[selectedCut]) {
        const c = p.clips[selectedCut];
        html =
            head(c.label || "Social cut", c.kind, "cuts") +
            section(
                "Clip settings",
                field("Name", "cutLabel", c.label) +
                    field("In (s)", "cutIn", c.start, "number", 'step=".033"') +
                    field("Out (s)", "cutOut", c.end, "number", 'step=".033"') +
                    selectField("Format", "cutKind", c.kind, ["short", "trailer"]) +
                    selectField("Frame", "cutFrame", c.framing, [
                        "wide",
                        "left",
                        "center",
                        "right",
                        "fit",
                    ]) +
                    '<div class="inspect-actions">' +
                    BUTTON("Delete cut", "deleteCut", "trash") +
                    "</div>",
            );
    } else if (inspect === "cue" && p.captions?.[selectedCue]) {
        const c = p.captions[selectedCue];
        html =
            head("Caption cue " + (selectedCue + 1), "Episode transcript", "captions") +
            section(
                "Timed cue",
                field("Start (s)", "cueIn", c.start, "number", 'step=".01"') +
                    field("End (s)", "cueOut", c.end, "number", 'step=".01"') +
                    '<label class="form-field"><span>Text</span><textarea data-edit="cueText">' +
                    esc(c.text) +
                    '</textarea></label><div class="inspect-actions">' +
                    BUTTON("Remove cue", "deleteCue", "trash") +
                    "</div>",
            );
    } else if (inspect === "products" || inspect === "sponsors") {
        const r = items()[menuTrack];
        html =
            head(
                inspect === "products" ? "Product tag" : "Sponsor deliverable",
                r?.label || r?.sponsor || "",
                inspect === "products" ? "tag" : "mic",
            ) +
            section(
                "Details",
                "<p>" +
                    esc(r?.brief || r?.variant_id || "") +
                    "</p>" +
                    BUTTON("Edit in advanced workspace", "advanced", "edit"),
            );
    } else {
        html =
            head(p.title || "Episode", "Episode settings", "clapper") +
            section(
                "Episode",
                field("Title", "title", p.title) +
                    selectField("Visibility", "visibility", p.visibility || "private", [
                        "private",
                        "public",
                        "members",
                    ]) +
                    '<label class="form-field"><span>Description</span><textarea data-edit="description">' +
                    esc(p.description || "") +
                    "</textarea></label>",
            ) +
            section(
                "Opening title",
                field("Text", "intro", p.edit.intro || "") +
                    field(
                        "Length (s)",
                        "introSeconds",
                        p.edit.intro_seconds || 2.5,
                        "number",
                        'step=".25" min=".25" max="30"',
                    ),
            );
    }
    host.innerHTML = html;
    host.querySelectorAll("[data-edit]").forEach(
        (el) => (el.onchange = () => saveInspector(el.dataset.edit, el.value)),
    );
    host.querySelectorAll("[data-gain]").forEach((el) => {
        el.oninput = () => {
            el.nextElementSibling.textContent = num(el.value).toFixed(1) + " dB";
            setVolume(el.dataset.gain, num(el.value));
        };
        el.onchange = () => updateTrack(el.dataset.gain, { gain_db: num(el.value) });
    });
    host.querySelectorAll("[data-mute]").forEach(
        (b) =>
            (b.onclick = () => {
                const t = source(b.dataset.mute);
                if (t) updateTrack(t.id, { muted: !t.muted });
            }),
    );
    host.querySelectorAll("[data-rev-action]").forEach(
        (b) =>
            (b.onclick = () =>
                b.dataset.revAction === "publish_frh"
                    ? confirmPublish(b.dataset.rev)
                    : command(b.dataset.revAction, { revision: b.dataset.rev })),
    );
}
function updateTrack(id, change) {
    const t = source(id);
    return (
        t &&
        command("save_track", {
            id,
            enabled: t.enabled,
            gain_db: t.gain_db,
            offset: t.offset,
            label: t.label,
            ...change,
        })
    );
}
function saveInspector(k, v) {
    if (!p) return;
    const e = { ...p.edit },
        c = { ...p.clips?.[selectedCut] },
        cue = { ...p.captions?.[selectedCue] };
    if (["title", "visibility", "description", "intro", "introSeconds"].includes(k)) {
        const values = { edit: e, title: p.title, visibility: p.visibility, description: p.description };
        if (k === "intro") e.intro = v;
        else if (k === "introSeconds") e.intro_seconds = num(v);
        else values[k] = v;
        return command("save_edit", values);
    }
    if (k.startsWith("segment")) {
        const seq = segments(),
            s = seq[selectedSegment];
        if (!s) return;
        s[k === "segmentIn" ? "start" : "end"] = num(v);
        return saveSeq(seq, selectedSegment);
    }
    if (["sourceName", "offset", "gain"].includes(k))
        return updateTrack(selectedSource, {
            [{ sourceName: "label", offset: "offset", gain: "gain_db" }[k]]:
                k === "sourceName" ? v : num(v),
        });
    if (k.startsWith("cut")) {
        const prop = {
            cutIn: "start",
            cutOut: "end",
            cutFrame: "framing",
            cutKind: "kind",
            cutLabel: "label",
        }[k];
        c[prop] = ["start", "end"].includes(prop) ? num(v) : v;
        return command("save_clip", { index: selectedCut, clip: c });
    }
    if (k.startsWith("cue")) {
        cue[{ cueIn: "start", cueOut: "end", cueText: "text" }[k]] = k === "cueText" ? v : num(v);
        const captions = p.captions.slice();
        captions[selectedCue] = cue;
        return command("save_captions", { captions });
    }
}

function axisDuration() {
    return duration() || 30;
}
function drawTimeline() {
    const seq = segments(),
        total = axisDuration(),
        positions = starts(),
        lane = $("lanes");
    $("canvas").style.width = zoom * 100 + "%";
    $("zoom").value = zoom;
    $("ruler").setAttribute("aria-valuemax", duration().toFixed(2));
    $("ruler").innerHTML = "";
    const width = Math.max(270, $("trackScroll").clientWidth * zoom),
        want = total / (width / 95),
        step =
            [0.1, 0.25, 0.5, 1, 2, 5, 10, 15, 30, 60, 120, 300, 600, 1200, 3600, 7200, 14400].find(
                (s) => s >= want,
            ) || 36000;
    for (let t = 0, i = 0; t <= total + 0.001; t += step / 4, i++) {
        const div = document.createElement("span");
        div.style.left = (100 * t) / total + "%";
        if (i % 4 === 0) {
            div.className = "major";
            const b = document.createElement("b");
            b.textContent = step < 1 ? fmt(t) : clock(t);
            div.append(b);
        }
        $("ruler").append(div);
    }
    const labels = $("trackLabels");
    labels.innerHTML = '<div class="label-ruler">TRACKS</div>';
    lane.replaceChildren();
    const row = (id, title, code, type, target) => {
        const l = document.createElement("div");
        l.className = "lane-label";
        l.dataset.lane = id;
        l.tabIndex = 0;
        l.innerHTML =
            '<i class="track-type ' +
            type +
            '">' +
            code +
            "</i><span>" +
            esc(title) +
            "</span>" +
            (target
                ? '<button aria-label="Toggle ' +
                  esc(title) +
                  '" data-tip="Mute or unmute track">' +
                  icon(!target.muted ? "volume" : "muted", 12) +
                  "</button>"
                : "");
        if (target) {
            l.classList.toggle("muted", Boolean(!target.enabled || target.muted));
            l.querySelector("button").onclick = () => updateTrack(target.id, { muted: !target.muted });
        }
        l.oncontextmenu = (e) => {
            e.preventDefault();
            target ? trackMenu(e, target) : sequenceMenu(e, selectedSegment);
        };
        l.onkeydown = (e) => {
            if (e.key === "F10" && e.shiftKey) {
                e.preventDefault();
                target ? trackMenu(e, target) : sequenceMenu(e, selectedSegment);
            }
        };
        labels.append(l);
        const el = document.createElement("div");
        el.className = "lane";
        el.dataset.lane = id;
        el.oncontextmenu = l.oncontextmenu;
        lane.append(el);
        return el;
    };
    const visual = row("video", "Video", "V1", "", null);
    if (!seq.length)
        visual.innerHTML =
            '<span class="timeline-empty">' +
            icon("timeline", 14) +
            "Add a recording to the sequence</span>";
    seq.forEach((s, i) => {
        const t = source(s.camera),
            b = block(
                visual,
                positions[i],
                positions[i] + s.end - s.start,
                total,
                "video",
                t?.label || "Recording",
            );
        b.dataset.segment = i;
        b.classList.toggle("active", selectedSegment === i);
        b.setAttribute("aria-label", "Segment " + (i + 1) + " " + (t?.label || ""));
        const turl = thumb(s.camera);
        if (turl) {
            const strip = document.createElement("i");
            strip.className = "strip";
            strip.style.backgroundImage = "url(" + JSON.stringify(turl) + ")";
            b.prepend(strip);
        }
        b.insertAdjacentHTML(
            "beforeend",
            '<i class="handle in-handle" data-handle="in"></i><i class="handle out-handle" data-handle="out"></i>',
        );
        b.onpointerdown = (e) => {
            if (e.button !== 0) return;
            if (e.target.dataset.handle) {
                startTrim(e, i, e.target.dataset.handle);
                return;
            }
            if (tool === "razor") {
                selectSeg(i, false);
                seek(xTime(e.clientX));
                splitAt(pos);
                return;
            }
            selectSeg(i, false);
            seek(xTime(e.clientX));
            b.focus();
        };
        b.oncontextmenu = (e) => {
            e.preventDefault();
            selectSeg(i, false);
            sequenceMenu(e, i);
        };
        b.onkeydown = (e) => {
            if (e.shiftKey && e.key === "F10") {
                e.preventDefault();
                sequenceMenu(e, i);
            } else if (e.key === "Enter") {
                selectSeg(i);
                seek(positions[i]);
            }
        };
    });
    const audios =
        p?.sources?.filter((t) => t.audio && (t.kind !== "camera" || seq.some((s) => s.camera === t.id))) ||
        [];
    if (!audios.length) row("audio", "Audio", "A1", "audio-type", null);
    audios.forEach((t, n) => {
        const rl = row("audio-" + t.id, t.label, "A" + (n + 1), "audio-type", t);
        let pg = 0;
        for (const s of seq) {
            if (t.kind === "camera" && s.camera !== t.id) {
                pg += s.end - s.start;
                continue;
            }
            const base = num(t.offset),
                a = Math.max(base, s.start),
                b = Math.min(base + t.duration, s.end);
            if (b > a) {
                const el = block(rl, pg + a - s.start, pg + b - s.start, total, "audio", t.label);
                el.dataset.audio = t.id;
                el.classList.toggle("muted", Boolean(!t.enabled || t.muted));
                wave(el, t, a - base, b - base);
                el.onclick = () => {
                    inspect = "mixer";
                    selectedSource = t.id;
                    drawInspector();
                };
                el.oncontextmenu = (e) => {
                    e.preventDefault();
                    trackMenu(e, t);
                };
                el.onkeydown = (e) => {
                    if (e.shiftKey && e.key === "F10") {
                        e.preventDefault();
                        trackMenu(e, t);
                    }
                };
            }
            pg += s.end - s.start;
        }
    });
    const cc = row("captions", "Captions", "CC", "cc-type", null);
    (p?.captions || []).forEach((c, i) =>
        mapped(c.start, c.end).forEach(([a, b]) => {
            const el = block(cc, a, b, total, "caption", c.text);
            el.dataset.cue = i;
            el.onclick = () => {
                tab = "captions";
                selectedCue = i;
                inspect = "cue";
                seek(a);
                drawTop();
                drawBin();
                drawInspector();
            };
            el.oncontextmenu = (e) => {
                e.preventDefault();
                selectedCue = i;
                openMenu(e, [
                    ["Edit caption", "edit", () => el.click()],
                    ["Remove cue", "trash", () => removeCue()],
                ]);
            };
        }),
    );
    $("playhead").style.left = (100 * pos) / total + "%";
    $("sequenceTitle").textContent = seq.length
        ? seq.length + " segment" + (seq.length === 1 ? "" : "s")
        : "Main sequence";
    $("pointer").classList.toggle("chosen", tool === "pointer");
    $("razor").classList.toggle("chosen", tool === "razor");
    $("snap").classList.toggle("chosen", snap);
    $("snap").setAttribute("aria-pressed", snap);
}
function block(lane, a, b, total, css, label) {
    const el = document.createElement("button");
    el.type = "button";
    el.className = "block " + css;
    el.style.left = (100 * a) / total + "%";
    el.style.width = (100 * (b - a)) / total + "%";
    el.classList.toggle("narrow", ((b - a) * $("canvas").getBoundingClientRect().width) / total < 36);
    el.setAttribute("aria-label", label);
    el.innerHTML = "<span>" + esc(label) + "</span>";
    lane.append(el);
    return el;
}
function wave(el, t, a, b) {
    const url = state.waveform_urls?.[t.id];
    if (!url) return;
    if (!waveCache.has(url)) {
        waveCache.set(
            url,
            fetch(url)
                .then((r) => (r.ok ? r.json() : null))
                .then((j) => j?.peaks || [])
                .catch(() => []),
        );
    }
    waveCache.get(url).then((peaks) => {
        if (!el.isConnected || !peaks.length) return;
        const w = 180,
            height = 30,
            start = clamp(a / t.duration, 0, 1),
            end = clamp(b / t.duration, 0, 1);
        let path = "";
        for (let i = 0; i < w; i += 2) {
            const value = clamp(
                Math.abs(
                    num(
                        peaks[
                            Math.min(
                                peaks.length - 1,
                                Math.floor((start + ((end - start) * i) / w) * peaks.length),
                            )
                        ],
                    ),
                ),
                0,
                1,
            );
            const h = Math.max(1, value * height);
            path += "M" + i + " " + ((height - h) / 2).toFixed(1) + "v" + h.toFixed(1);
        }
        el.insertAdjacentHTML(
            "afterbegin",
            '<svg class="wave" viewBox="0 0 ' +
                w +
                " " +
                height +
                '" preserveAspectRatio="none" aria-hidden="true"><path d="' +
                path +
                '" stroke="currentColor" fill="none" stroke-width="1.2"/></svg>',
        );
    });
}
function selectSeg(i, refresh = true) {
    selectedSegment = i;
    inspect = "segment";
    selectedSource = segments()[i]?.camera || selectedSource;
    document
        .querySelectorAll("[data-segment]")
        .forEach((el) => el.classList.toggle("active", Number(el.dataset.segment) === i));
    drawInspector();
    if (refresh) drawBin();
}
function xTime(x) {
    const rect = $("canvas").getBoundingClientRect();
    return clamp(((x - rect.left) / rect.width) * axisDuration(), 0, duration());
}
function snapped(t) {
    if (!snap) return t;
    const threshold = (axisDuration() / $("canvas").getBoundingClientRect().width) * 6;
    const choice = [0, ...starts(), duration()].find((s) => Math.abs(s - t) < threshold);
    return choice ?? t;
}
function startTrim(e, i, side) {
    e.preventDefault();
    e.stopPropagation();
    pause();
    selectSeg(i, false);
    const seg = segments()[i],
        block = e.currentTarget;
    drag = {
        mode: "trim",
        i,
        side,
        x: e.clientX,
        seq: segments(),
        el: block,
        start: seg.start,
        end: seg.end,
        width: $("canvas").getBoundingClientRect().width,
        total: axisDuration(),
        proposed: null,
    };
    $("timelineBody").classList.add("resizing");
    window.addEventListener("pointermove", moving);
    window.addEventListener("pointerup", release, { once: true });
}
function moving(e) {
    if (!drag) return;
    if (drag.mode === "scrub") {
        seek(snapped(xTime(e.clientX)));
        return;
    }
    const { i, side, x, seq, el, start, end, width, total } = drag,
        s = seq[i],
        track = source(s.camera),
        delta = ((e.clientX - x) * total) / width;
    const boundA = Math.max(0, num(track.offset)),
        boundB = num(track.offset) + track.duration;
    const v =
        side === "in"
            ? clamp(start + delta, boundA, end - 1 / 30)
            : clamp(end + delta, start + 1 / 30, boundB);
    drag.proposed = Math.round(v * 30) / 30;
    const len = side === "in" ? end - drag.proposed : drag.proposed - start;
    el.style.width = (100 * len) / total + "%";
    if (side === "in") el.style.left = (100 * (starts()[i] + drag.proposed - start)) / total + "%";
    setStatus((side === "in" ? "In " : "Out ") + tc(drag.proposed));
}
function release() {
    window.removeEventListener("pointermove", moving);
    const d = drag;
    drag = null;
    $("timelineBody").classList.remove("resizing");
    if (d?.mode === "trim" && d.proposed !== null) {
        d.seq[d.i][d.side === "in" ? "start" : "end"] = d.proposed;
        saveSeq(d.seq, d.i);
    }
}
function seek(t) {
    mode = "sequence";
    pos = clamp(t, 0, duration());
    showFrame(true);
    drawTop();
}
function showFrame(force) {
    let t,
        mediaAt = 0,
        programMedia = null;
    if (mode === "source") {
        t = source(selectedSource);
        mediaAt = clamp(sourcePos, 0, t?.duration || 0);
    } else {
        programMedia = atProgram(pos);
        t = programMedia?.track;
        mediaAt = t ? Math.max(0, programMedia.local - num(t.offset)) : 0;
    }
    const url = t ? videoUrl(t.id) : "",
        key = (t?.id || "") + ":" + url;
    if (url && key !== loadedKey) {
        loadedKey = key;
        nextSeek = mediaAt;
        video.src = url;
        video.load();
    } else if (url && force && Math.abs(num(video.currentTime) - mediaAt) > 0.019) {
        if (video.readyState > 0) {
            try {
                video.currentTime = mediaAt;
            } catch {
                nextSeek = mediaAt;
            }
        } else nextSeek = mediaAt;
    } else if (!url && loadedKey) {
        loadedKey = "";
        video.pause();
        video.removeAttribute("src");
        video.load();
    }
    const secs = mode === "source" ? mediaAt : pos,
        total = mode === "source" ? t?.duration || 0 : duration();
    $("playTime").textContent = tc(secs);
    $("totalTime").textContent = " / " + tc(total);
    $("playhead").style.left = (100 * pos) / axisDuration() + "%";
    $("ruler").setAttribute("aria-valuenow", pos.toFixed(2));
    const cueAt = mode === "source" ? mediaAt + (t?.offset || 0) : programMedia?.local;
    const cues =
        mode === "source" && t?.id !== p?.edit?.camera ? t?.source_captions || [] : p?.captions || [];
    const cue = showCaptions ? cues.find((c) => cueAt >= c.start && cueAt < c.end) : null;
    $("viewerCaption").innerHTML = cue ? "<span>" + esc(cue.text) + "</span>" : "";
    video.muted =
        muted || !t?.enabled || t?.muted || (mode === "sequence" && !p?.edit?.include_camera_audio);
    if (videoMeter) {
        video.volume = 1;
        videoMeter.gain.gain.value = 10 ** (num(t?.gain_db) / 20);
    } else video.volume = clamp(10 ** (num(t?.gain_db) / 20), 0, 1);
    syncAudio(programMedia?.local, force);
}
video.addEventListener("loadedmetadata", () => {
    if (nextSeek != null) {
        video.currentTime = clamp(nextSeek, 0, Number.isFinite(video.duration) ? video.duration : nextSeek);
        nextSeek = null;
    }
    if (playing) video.play().catch(playFailed);
});
video.addEventListener("error", () => {
    if (loadedKey && p) {
        pause();
        setStatus("This recording cannot play in the monitor. Open the source in the advanced workspace.");
    }
});
function playFailed(e) {
    if (e.name !== "AbortError") {
        pause();
        toast("Preview could not play. " + e.message);
    }
}
function pause() {
    playing = false;
    video.pause();
    audioNodes.forEach((a) => a.el.pause());
    $("play").innerHTML = icon("play", 14);
    $("play").setAttribute("aria-label", "Play");
}
function togglePlay() {
    if (playing) {
        pause();
        return;
    }
    if (!p || !loadedKey) return;
    if (mode === "sequence" && pos >= duration() - 0.035) seek(0);
    if (mode === "source" && sourcePos >= num(source(selectedSource)?.duration) - 0.035) {
        sourcePos = 0;
        showFrame(true);
    }
    playing = true;
    $("play").innerHTML = icon("pause", 14);
    $("play").setAttribute("aria-label", "Pause");
    if (!ctx && window.AudioContext) {
        try {
            ctx = new AudioContext();
        } catch {}
    }
    ctx?.resume().catch(() => {});
    if (ctx && !videoMeter) {
        try {
            const gain = ctx.createGain(),
                an = ctx.createAnalyser();
            an.fftSize = 256;
            ctx.createMediaElementSource(video).connect(gain).connect(an).connect(ctx.destination);
            videoMeter = { gain, an };
        } catch {}
    }
    video.play().catch(playFailed);
    requestAnimationFrame(tick);
}
let lastDraw = 0;
function tick(now) {
    if (!playing) return;
    const cur = video.currentTime;
    if (nextSeek === null && video.readyState >= 2 && !video.seeking) {
        if (mode === "sequence") {
            const s = atProgram(pos);
            if (s) {
                const v = cur + num(s.track?.offset);
                if ((v >= s.end - 0.026 || video.ended) && s.i < segments().length - 1) {
                    pos = s.before + s.end - s.start + 0.0001;
                    showFrame(true);
                } else if ((v >= s.end - 0.026 || video.ended) && s.i === segments().length - 1) {
                    pos = duration();
                    pause();
                    showFrame(false);
                    return;
                } else pos = clamp(s.before + v - s.start, s.before, s.before + s.end - s.start);
            }
        } else {
            sourcePos = cur;
            if (video.ended) {
                pause();
                return;
            }
        }
    }
    if (gapPreviewEnd != null && mode === "sequence" && pos >= gapPreviewEnd) {
        pause();
        gapPreviewEnd = null;
        return;
    }
    if (now - lastDraw >= 32) {
        showFrame(false);
        meters();
        lastDraw = now;
    }
    requestAnimationFrame(tick);
}
function setVolume(id, db) {
    const node = audioNodes.get(id);
    if (node?.gain) node.gain.gain.value = 10 ** ((db + (source(id)?.kind === "music" ? -23 : 0)) / 20);
    else if (node) node.el.volume = clamp(10 ** (db / 20), 0, 1);
}
function syncAudio(t, forced) {
    if (!p || mode === "source" || t == null) {
        audioNodes.forEach((a) => a.el.pause());
        return;
    }
    (p.sources || [])
        .filter((s) => s.kind !== "camera" && s.audio)
        .forEach((s) => {
            let a = audioNodes.get(s.id);
            if (!a) {
                if (!playing) return;
                const el = new Audio();
                el.preload = "auto";
                el.src = videoUrl(s.id);
                a = { el };
                if (ctx) {
                    try {
                        a.gain = ctx.createGain();
                        a.an = ctx.createAnalyser();
                        a.an.fftSize = 256;
                        ctx.createMediaElementSource(el)
                            .connect(a.gain)
                            .connect(a.an)
                            .connect(ctx.destination);
                    } catch {}
                }
                audioNodes.set(s.id, a);
            }
            const local = t - num(s.offset),
                on = playing && !muted && s.enabled && !s.muted && local >= 0 && local < s.duration;
            if (!on) {
                if (!a.el.paused) a.el.pause();
                return;
            }
            setVolume(s.id, num(s.gain_db));
            if (forced || Math.abs(a.el.currentTime - local) > 0.28) {
                try {
                    a.el.currentTime = Math.max(0, local);
                } catch {}
            }
            if (a.el.paused) a.el.play().catch(() => {});
        });
}
function meters() {
    document.querySelectorAll("[data-meter]").forEach((el) => {
        const id = el.dataset.meter,
            current = mode === "source" ? selectedSource : atProgram(pos)?.camera;
        const a = id === current && !video.muted ? videoMeter : audioNodes.get(id);
        if (!a?.an) {
            el.style.width = "0%";
            return;
        }
        const samples = new Uint8Array(a.an.fftSize);
        a.an.getByteTimeDomainData(samples);
        let peak = 0;
        for (const s of samples) peak = Math.max(peak, Math.abs(s - 128) / 128);
        el.style.width = clamp(((20 * Math.log10(Math.max(0.001, peak)) + 60) / 60) * 100, 0, 100) + "%";
    });
}
async function saveSeq(seq, sel) {
    if (!p) return;
    if (!seq.length) {
        toast("The sequence needs at least one segment.");
        return;
    }
    for (const s of seq) {
        const t = source(s.camera);
        if (
            !t ||
            s.end - s.start < 0.01 ||
            s.start < Math.max(0, num(t.offset)) - 0.005 ||
            s.end > num(t.offset) + t.duration + 0.005
        ) {
            toast("Choose trim points inside the source recording.");
            draw();
            return;
        }
    }
    pause();
    const ok = await command("save_sequence", {
        segments: seq.map((s) => ({ camera: s.camera, start: s.start, end: s.end })),
    });
    if (ok) {
        selectedSegment = clamp(sel, 0, seq.length - 1);
        inspect = "segment";
        draw();
    } else draw();
}
function splitAt(t = pos) {
    const hit = atProgram(t);
    if (!hit || hit.local <= hit.start + 0.033 || hit.local >= hit.end - 0.033) {
        toast("Move the playhead inside a segment to split it.");
        return;
    }
    const seq = segments();
    seq.splice(
        hit.i,
        1,
        { camera: hit.camera, start: hit.start, end: hit.local },
        { camera: hit.camera, start: hit.local, end: hit.end },
    );
    return saveSeq(seq, hit.i + 1);
}
function segIndex() {
    return selectedSegment >= 0 ? selectedSegment : (atProgram(pos)?.i ?? -1);
}
function duplicate() {
    const i = segIndex(),
        seq = segments();
    if (!seq[i]) return;
    seq.splice(i + 1, 0, { ...seq[i] });
    return saveSeq(seq, i + 1);
}
function removeSeg() {
    const i = segIndex(),
        seq = segments();
    if (!seq[i]) return;
    seq.splice(i, 1);
    return saveSeq(seq, Math.max(0, i - 1));
}
function reorder(delta) {
    const i = segIndex(),
        seq = segments(),
        n = i + delta;
    if (n < 0 || n >= seq.length) return;
    [seq[i], seq[n]] = [seq[n], seq[i]];
    return saveSeq(seq, n);
}
function trimAt(side) {
    const seq = segments(),
        hit = atProgram(pos),
        i = segIndex();
    if (!hit || hit.i !== i) {
        toast("Place the playhead inside the selected segment.");
        return;
    }
    seq[i][side === "in" ? "start" : "end"] = hit.local;
    return saveSeq(seq, i);
}
function appendSource(id) {
    const s = source(id);
    if (!s?.video) return;
    const seq = segments();
    const a = Math.max(0, num(s.offset)),
        b = num(s.offset) + s.duration;
    seq.push({ camera: id, start: a, end: b });
    return saveSeq(seq, seq.length - 1);
}

function closeMenu(restore = false) {
    const m = $("menu");
    if (m.hidden) return;
    m.hidden = true;
    m.replaceChildren();
    if (restore) menuFocus?.focus?.();
    menuFocus = null;
}
function openMenu(e, defs) {
    closeMenu();
    const m = $("menu");
    activeMenu = defs;
    menuFocus = document.activeElement;
    m.hidden = false;
    for (const item of defs) {
        if (!item) {
            m.append(document.createElement("hr"));
            continue;
        }
        const [title, ic, fn, shortcut = "", disabled = false] = item;
        const b = document.createElement("button");
        b.setAttribute("role", "menuitem");
        b.disabled = disabled;
        b.classList.toggle("danger", ic === "trash");
        b.innerHTML = icon(ic, 14) + esc(title) + (shortcut ? "<small>" + esc(shortcut) + "</small>" : "");
        b.onclick = () => {
            closeMenu();
            fn();
        };
        m.append(b);
    }
    const rect = (e.currentTarget || e.target).getBoundingClientRect(),
        keyboard = e.type === "keydown" || (!e.clientX && !e.clientY);
    const x = keyboard ? rect.left + 4 : e.clientX,
        y = keyboard ? rect.bottom : e.clientY;
    m.style.left = clamp(x, 4, innerWidth - m.offsetWidth - 5) + "px";
    m.style.top = clamp(y, 4, innerHeight - m.offsetHeight - 5) + "px";
    m.querySelector("button:enabled")?.focus();
}
function sequenceMenu(e, i) {
    if (i >= 0) selectSeg(i, false);
    const s = segments()[segIndex()],
        t = s && source(s.camera);
    openMenu(e, [
        ["Split at playhead", "split", () => splitAt(), "S", !s],
        ["Duplicate segment", "copy", duplicate, "Ctrl+D", !s],
        ["Ripple delete", "trash", removeSeg, "Del", segments().length < 2],
        null,
        ["Trim start to playhead", "markIn", () => trimAt("in"), "I", !s],
        ["Trim end to playhead", "markOut", () => trimAt("out"), "O", !s],
        ["Move earlier", "swap", () => reorder(-1), "", segIndex() < 1],
        ["Move later", "swap", () => reorder(1), "", segIndex() >= segments().length - 1],
        null,
        [
            t?.muted ? "Unmute track" : "Mute track",
            "volume",
            () => updateTrack(t.id, { muted: !t.muted }),
            "",
            !t,
        ],
        [
            "Inspect segment",
            "sliders",
            () => {
                selectSeg(segIndex());
                shell.classList.add("inspect-mobile");
            },
            "",
            !s,
        ],
        ["Smart Split…", "spark", gapDialog, "", !p],
    ]);
}
function trackMenu(e, t) {
    openMenu(e, [
        [
            t.muted ? "Unmute track" : "Mute track",
            t.muted ? "volume" : "muted",
            () => updateTrack(t.id, { muted: !t.muted }),
        ],
        ["Rename recording…", "edit", () => renameTrack(t)],
        [
            "Inspect recording",
            "sliders",
            () => {
                selectedSource = t.id;
                inspect = "source";
                drawInspector();
                shell.classList.add("inspect-mobile");
            },
        ],
        ["Open mixer", "sliders", () => switchWork("mix")],
        ...(t.video
            ? [
                  null,
                  ["Append to sequence", "plus", () => appendSource(t.id)],
                  ["Smart Split…", "spark", gapDialog],
              ]
            : []),
    ]);
}
$("menu").onkeydown = (e) => {
    const buttons = [...$("menu").querySelectorAll("button:enabled")],
        i = buttons.indexOf(document.activeElement);
    if (e.key === "Escape") {
        e.preventDefault();
        closeMenu(true);
    } else if (["ArrowDown", "ArrowUp", "Home", "End"].includes(e.key)) {
        e.preventDefault();
        buttons[
            e.key === "Home"
                ? 0
                : e.key === "End"
                  ? buttons.length - 1
                  : (i + (e.key === "ArrowDown" ? 1 : -1) + buttons.length) % buttons.length
        ]?.focus();
    }
};
document.addEventListener("pointerdown", (e) => {
    if (!e.target.closest("#menu") && !e.target.closest("[aria-haspopup=menu]")) closeMenu();
});
function modal(title, body, actions, submit, kind = "form") {
    closeMenu();
    const d = $("modal");
    modalType = kind;
    $("modalTitle").textContent = title;
    $("modalBody").innerHTML = body;
    $("modalActions").innerHTML = actions
        .map(
            ([label, id, cls]) =>
                '<button type="' +
                (id === "submit" ? "submit" : "button") +
                '" data-modal="' +
                id +
                '" class="' +
                (cls || "secondary") +
                '">' +
                esc(label) +
                "</button>",
        )
        .join("");
    modalSubmit = submit;
    if (!d.open) d.showModal();
    $("modalBody").querySelector("input,textarea,select")?.focus();
}
function dismiss() {
    if ($("modal").open) $("modal").close();
    if (gapPreviewEnd !== null) pause();
    gapPreviewEnd = null;
    modalType = "";
    modalSubmit = null;
}
$("modalClose").onclick = dismiss;
$("modal").addEventListener("cancel", () => {
    modalType = "";
    gapPreviewEnd = null;
    pause();
});
$("modalActions").onclick = (e) => {
    const b = e.target.closest("[data-modal]");
    if (!b) return;
    if (b.dataset.modal === "cancel") dismiss();
    if (b.dataset.modal === "analyze") analyzeGaps();
};
$("modalForm").onsubmit = (e) => {
    e.preventDefault();
    modalSubmit?.();
};
const input = (id, label, value, type = "text", attrs = "") =>
    '<label class="form-field"><span>' +
    esc(label) +
    '</span><input id="' +
    id +
    '" type="' +
    type +
    '" value="' +
    esc(value) +
    '" ' +
    attrs +
    "></label>";
const okActions = (label) => [
    ["Cancel", "cancel"],
    [label, "submit", "primary"],
];
function newProject(next) {
    modal(
        "New episode",
        input(
            "newTitle",
            "Episode title",
            "",
            "text",
            'required maxlength="160" placeholder="Episode title"',
        ),
        okActions("Create episode"),
        async () => {
            const title = $("newTitle").value.trim();
            if (!title) return;
            const r = await command("new_project", { title });
            if (r) {
                dismiss();
                if (next) next();
            }
        },
    );
}
function requireProject(fn) {
    if (!p) {
        newProject(fn);
        return;
    }
    fn();
}
function renameTrack(t) {
    modal(
        "Rename recording",
        input("newName", "Recording name", t.label, "text", "required"),
        okActions("Save"),
        async () => {
            if (await updateTrack(t.id, { label: $("newName").value.trim() })) dismiss();
        },
    );
}
function newCut() {
    if (!p) return;
    const s = atProgram(pos);
    if (!s) {
        toast("Import a video recording first.");
        return;
    }
    modal(
        "Create social cut",
        input("cutName", "Title", "") +
            input("startCut", "Source in (seconds)", fmt(s.local), "number", 'step=".01" min="0"') +
            input("endCut", "Source out (seconds)", fmt(s.end), "number", 'step=".01" min="0"') +
            '<label class="form-field"><span>Format</span><select id="newCutKind"><option value="short">Vertical short</option><option value="trailer">Widescreen trailer</option></select></label>',
        okActions("Create cut"),
        async () => {
            const kind = $("newCutKind").value,
                c = {
                    kind,
                    label: $("cutName").value.trim() || (kind === "short" ? "Short" : "Trailer"),
                    start: num($("startCut").value),
                    end: num($("endCut").value),
                    camera: s.camera,
                    framing: kind === "short" ? "center" : "wide",
                    burn_captions: true,
                };
            if (!(c.end > c.start)) {
                toast("Set an out point after the in point.");
                return;
            }
            if (await command("save_clip", { index: null, clip: c })) {
                dismiss();
                tab = "cuts";
                selectedCut = p.clips.length - 1;
                inspect = "cut";
                draw();
            }
        },
    );
}
function newCue() {
    const s = atProgram(pos);
    if (!s) return;
    modal(
        "Add caption",
        input("cueStart", "Start (seconds)", fmt(s.local), "number", 'step=".01"') +
            input("cueEnd", "End (seconds)", fmt(Math.min(s.end, s.local + 3)), "number", 'step=".01"') +
            '<label class="form-field"><span>Caption text</span><textarea id="textCue" required></textarea></label>',
        okActions("Add caption"),
        async () => {
            const c = {
                start: num($("cueStart").value),
                end: num($("cueEnd").value),
                text: $("textCue").value.trim(),
            };
            if (c.end <= c.start || !c.text) {
                toast("Add text and a valid cue range.");
                return;
            }
            const captions = [...p.captions, c].sort((a, b) => a.start - b.start);
            if (await command("save_captions", { captions })) {
                dismiss();
                selectedCue = captions.indexOf(c);
                inspect = "cue";
                draw();
            }
        },
    );
}
function removeCue() {
    if (!p || selectedCue < 0) return;
    const captions = p.captions.filter((_, i) => i !== selectedCue);
    selectedCue = -1;
    inspect = "episode";
    command("save_captions", { captions });
}
let gapSignature = "",
    analyzed = false;
function gapDialog() {
    if (!p || !duration()) {
        toast("Add a recording before analyzing gaps.");
        return;
    }
    gapSignature = "";
    analyzed = false;
    gapChecks = [];
    modal(
        "Smart Split",
        "<p>Analyze quiet intervals in the program audio, then audition the gaps you want to remove.</p>" +
            input("threshold", "Silence threshold (dB)", "-35", "number", 'min="-80" max="-10" step="1"') +
            input("minSilence", "Minimum pause (seconds)", ".8", "number", 'min=".1" max="20" step=".05"') +
            input("padding", "Keep at each edge (seconds)", ".18", "number", 'min="0" max="5" step=".01"') +
            '<div id="gapResults"></div>',
        [
            ["Close", "cancel"],
            ["Analyze gaps", "analyze"],
            ["Remove selected", "submit", "primary"],
        ],
        async () => {
            const ranges = (state.gaps || []).filter((_, i) => gapChecks[i]);
            if (!analyzed || !ranges.length) return;
            pause();
            if (await command("apply_gaps", { ranges })) {
                dismiss();
                pos = Math.min(pos, duration());
                draw();
                toast("Selected gaps removed. Undo restores the previous sequence.");
            }
        },
        "gaps",
    );
    $("modalActions").querySelector("[type=submit]").disabled = true;
}
async function analyzeGaps() {
    if (state.busy || pending) return;
    const fields = {
        threshold_db: num($("threshold").value),
        min_silence: num($("minSilence").value),
        padding: num($("padding").value),
    };
    gapSignature = "";
    gapChecks = [];
    analyzed = false;
    $("gapResults").textContent = "Listening to the program audio…";
    const r = await command("analyze_gaps", fields);
    if (r) {
        analyzed = true;
        showGapResults();
    }
}
function showGapResults() {
    const root = $("gapResults");
    if (!root || !analyzed || state.busy) return;
    const gaps = state.gaps || [],
        sig = JSON.stringify(gaps);
    if (gapSignature === sig) return;
    gapSignature = sig;
    gapChecks = gaps.map(() => true);
    root.innerHTML = gaps.length
        ? "<p>" +
          gaps.length +
          " quiet interval" +
          (gaps.length === 1 ? "" : "s") +
          " found.</p>" +
          gaps
              .map(
                  (g, i) =>
                      '<div class="gap-row"><label><input type="checkbox" checked data-gap="' +
                      i +
                      '"> ' +
                      clock(g.start) +
                      " – " +
                      clock(g.end) +
                      "</label><small>" +
                      fmt(g.end - g.start) +
                      ' s</small><button type="button" data-audition="' +
                      i +
                      '" aria-label="Preview gap ' +
                      (i + 1) +
                      '">' +
                      icon("play", 13) +
                      "Preview</button></div>",
              )
              .join("")
        : "<p>No gaps matched these settings.</p>";
    const button = $("modalActions").querySelector("[type=submit]");
    button.disabled = !gaps.length;
    root.querySelectorAll("[data-gap]").forEach(
        (el) =>
            (el.onchange = () => {
                gapChecks[el.dataset.gap] = el.checked;
                button.disabled = !gapChecks.some(Boolean);
            }),
    );
    root.querySelectorAll("[data-audition]").forEach(
        (el) =>
            (el.onclick = () => {
                pause();
                const g = gaps[el.dataset.audition];
                seek(Math.max(0, g.start - 0.45));
                gapPreviewEnd = Math.min(duration(), g.end + 0.45);
                togglePlay();
            }),
    );
}
function confirmPublish(revision) {
    const r = (state.revisions || []).find((r) => r.revision === revision);
    if (!r || r.approval !== "approved" || r.visibility !== "public") return;
    const title = r.title || p.title;
    modal(
        "Publish episode",
        "<p>Publish <strong>" +
            esc(title) +
            "</strong> to <strong>freerangehumanity.com</strong> and its public podcast feed.</p><p>Revision " +
            esc(revision) +
            "</p>" +
            input(
                "previousRevision",
                "Previous public revision UUID (only when replacing an episode)",
                "",
                "text",
            ),
        okActions("Publish episode"),
        async () => {
            const body = { revision, confirmed: true },
                prev = $("previousRevision").value.trim();
            if (prev) body.previous_revision = prev;
            if (await command("publish_frh", body)) dismiss();
        },
    );
}
function shortcuts() {
    const rows = [
        ["Play / pause", "Space"],
        ["Previous / next frame", "←  →"],
        ["Seek one second", "Shift + ← / →"],
        ["Move to start / end", "Home / End"],
        ["Select / blade tool", "V / B"],
        ["Split at playhead", "S"],
        ["Trim in / out to playhead", "I / O"],
        ["Duplicate / ripple delete", "Ctrl+D / Delete"],
        ["Toggle snap", "N"],
        ["Timeline zoom", "− / +"],
        ["Undo / redo", "Ctrl+Z / Ctrl+Y"],
        ["Track context menu", "Shift+F10"],
        ["Import media", "Ctrl+I"],
    ];
    modal(
        "Keyboard shortcuts",
        rows
            .map(
                ([name, key]) =>
                    '<div class="shortcut-row"><span>' +
                    esc(name) +
                    "</span><kbd>" +
                    esc(key) +
                    "</kbd></div>",
            )
            .join(""),
        [["Close", "cancel"]],
        null,
        "help",
    );
}
function switchWork(which) {
    work = which;
    inspect = which === "mix" ? "mixer" : which === "deliver" ? "deliver" : "episode";
    if (innerWidth <= 760) shell.classList.add("inspect-mobile");
    drawTop();
    drawInspector();
}
function panel(name) {
    tab = name;
    if (name === "captions" && p?.captions?.length) {
        selectedCue = Math.max(0, selectedCue);
        inspect = "cue";
    } else if (name === "cuts" && p?.clips?.length) {
        selectedCut = Math.max(0, selectedCut);
        inspect = "cut";
    } else if (name === "media") inspect = "source";
    drawTop();
    drawBin();
    drawInspector();
}
function actions(id, e) {
    const requireFn = (fn) => () => requireProject(fn);
    const map = {
        new: () => newProject(),
        import: requireFn(() => command("import_media", { kind: "camera" })),
        import_media: requireFn(() => command("import_media", { kind: "camera" })),
        importMenu: () =>
            openMenu(e, [
                ["Video files", "film", requireFn(() => command("import_media", { kind: "camera" }))],
                ["Voice tracks", "audio", requireFn(() => command("import_media", { kind: "voice" }))],
                ["Music files", "music", requireFn(() => command("import_media", { kind: "music" }))],
                null,
                ["Social clip or creator export…", "social", requireFn(() => command("import_social"))],
            ]),
        importSocial: requireFn(() => command("import_social")),
        importCaptions: requireFn(() => command("import_captions")),
        transcribe: requireFn(() => command("transcribe")),
        newCue: requireFn(newCue),
        newCut: requireFn(newCut),
        suggest_clips: requireFn(() => command("suggest_clips")),
        catalog_tags: requireFn(() => command("catalog_tags")),
        import_rundown: requireFn(() => command("import_rundown")),
        render: requireFn(() => command("render")),
        advanced: requireFn(() => command("advanced")),
        split: () => splitAt(),
        duplicate,
        deleteSegment: removeSeg,
        trimIn: () => trimAt("in"),
        trimOut: () => trimAt("out"),
        deleteCue: removeCue,
        deleteCut: () => {
            if (selectedCut >= 0) {
                const index = selectedCut;
                selectedCut = -1;
                inspect = "episode";
                command("delete_clip", { index });
            }
        },
        append: () => appendSource(selectedSource),
        muteTrack: () => {
            const t = source(selectedSource);
            if (t) updateTrack(t.id, { muted: !t.muted });
        },
    };
    map[id]?.();
}
document.addEventListener("click", (e) => {
    const b = e.target.closest("[data-fn],[data-action],[data-panel],[data-work]");
    if (!b) return;
    if (b.dataset.panel) panel(b.dataset.panel);
    else if (b.dataset.work) switchWork(b.dataset.work);
    else
        actions(b.dataset.fn || b.dataset.action, {
            ...e,
            clientX: e.clientX,
            clientY: e.clientY,
            target: b,
            currentTarget: b,
        });
});
$("projectMenu").onclick = (e) =>
    openMenu(e, [
        ["New episode…", "plus", () => newProject(), "Ctrl+N"],
        ["Open episode…", "folderOpen", () => command("open_project"), "Ctrl+O"],
        null,
        ["Import social clip…", "social", () => requireProject(() => command("import_social"))],
        ["Episode settings", "sliders", () => switchWork("edit"), "", !p],
    ]);
$("appMenu").onclick = (e) =>
    openMenu(e, [
        ["Episode settings", "sliders", () => switchWork("edit"), "", !p],
        ["Advanced workspace…", "settings", () => requireProject(() => command("advanced"))],
        ["Keyboard shortcuts", "keyboard", shortcuts, "?"],
        null,
        ["Undo", "undo", () => command("undo"), "Ctrl+Z", !state.can_undo],
        ["Redo", "repeat", () => command("redo"), "Ctrl+Y", !state.can_redo],
    ]);
$("welcomeNew").onclick = () => newProject();
$("welcomeOpen").onclick = () => command("open_project");
$("shortcuts").onclick = shortcuts;
$("record").onclick = () => requireProject(() => command(state.recording ? "stop_recording" : "record"));
$("export").onclick = () => switchWork("deliver");
$("inspectTab").onclick = () => {
    inspect = selectedSegment >= 0 ? "segment" : "episode";
    drawInspector();
};
$("mixerTab").onclick = () => switchWork("mix");
$("monitorMode").onclick = (e) =>
    openMenu(e, [
        [
            "Program sequence",
            "timeline",
            () => {
                pause();
                seek(pos);
            },
        ],
        ["Selected source", "film", () => sourcePreview(selectedSource), "", !selectedSource],
    ]);
$("full").onclick = () => {
    if (document.fullscreenElement) document.exitFullscreen();
    else
        $("monitorArea")
            .requestFullscreen()
            .catch(() => {});
};
$("play").onclick = togglePlay;
$("monitorMute").onclick = () => {
    muted = !muted;
    $("monitorMute").innerHTML = icon(muted ? "muted" : "volume", 14);
    showFrame(false);
};
$("ccToggle").onclick = () => {
    showCaptions = !showCaptions;
    $("ccToggle").setAttribute("aria-pressed", showCaptions);
    showFrame(false);
};
function offset(dt) {
    pause();
    if (mode === "source") {
        sourcePos = clamp(sourcePos + dt, 0, num(source(selectedSource)?.duration));
        showFrame(true);
    } else seek(pos + dt);
}
$("prevFrame").onclick = () => offset(-1 / 30);
$("nextFrame").onclick = () => offset(1 / 30);
$("begin").onclick = () => {
    pause();
    if (mode === "source") {
        sourcePos = 0;
        showFrame(true);
    } else seek(0);
};
$("end").onclick = () => {
    pause();
    if (mode === "source") {
        sourcePos = num(source(selectedSource)?.duration);
        showFrame(true);
    } else seek(duration());
};
$("pointer").onclick = () => {
    tool = "pointer";
    drawTimeline();
};
$("razor").onclick = () => {
    tool = "razor";
    drawTimeline();
};
$("split").onclick = () => splitAt();
$("gap").onclick = gapDialog;
$("undo").onclick = () => p && command("undo");
$("snap").onclick = () => {
    snap = !snap;
    drawTimeline();
};
function setZoom(v) {
    zoom = clamp(v, 1, 12);
    drawTimeline();
    const width = $("canvas").getBoundingClientRect().width,
        scroll = $("trackScroll"),
        px = (pos / axisDuration()) * width;
    if (px < scroll.scrollLeft || px > scroll.scrollLeft + scroll.clientWidth)
        scroll.scrollLeft = Math.max(0, px - scroll.clientWidth / 2);
}
$("zoom").oninput = (e) => setZoom(num(e.target.value));
$("zoomIn").onclick = () => setZoom(zoom + 0.5);
$("zoomOut").onclick = () => setZoom(zoom - 0.5);
$("ruler").onpointerdown = (e) => {
    if (e.button !== 0) return;
    e.preventDefault();
    pause();
    drag = { mode: "scrub" };
    seek(snapped(xTime(e.clientX)));
    window.addEventListener("pointermove", moving);
    window.addEventListener("pointerup", release, { once: true });
};
$("lanes").addEventListener("dragover", (e) => {
    if (e.dataTransfer.types.includes("application/x-studio-source")) {
        e.preventDefault();
        $("lanes").classList.add("drop-target");
    }
});
$("lanes").addEventListener("dragleave", () => $("lanes").classList.remove("drop-target"));
$("lanes").addEventListener("drop", (e) => {
    e.preventDefault();
    $("lanes").classList.remove("drop-target");
    const id = e.dataTransfer.getData("application/x-studio-source");
    if (source(id)) appendSource(id);
});
document.addEventListener("keydown", (e) => {
    if (e.key === "Escape") {
        closeMenu(true);
        shell.classList.remove("inspect-mobile");
        return;
    }
    if (
        e.target.closest("input,textarea,select,[contenteditable=true]") ||
        $("modal").open ||
        !$("menu").hidden
    )
        return;
    const k = e.key.toLowerCase(),
        ctrl = e.ctrlKey || e.metaKey;
    if (ctrl && k === "i") {
        e.preventDefault();
        actions("import");
        return;
    }
    if (ctrl && k === "n") {
        e.preventDefault();
        newProject();
        return;
    }
    if (ctrl && k === "o") {
        e.preventDefault();
        command("open_project");
        return;
    }
    if (k === "?") {
        shortcuts();
        return;
    }
    if (!p) return;
    if (ctrl && k === "z") {
        e.preventDefault();
        command(e.shiftKey ? "redo" : "undo");
        return;
    }
    if (ctrl && k === "y") {
        e.preventDefault();
        command("redo");
        return;
    }
    if (ctrl && k === "d") {
        e.preventDefault();
        duplicate();
        return;
    }
    if (ctrl || e.altKey) return;
    if (k === " ") {
        e.preventDefault();
        togglePlay();
    } else if (k === "arrowleft" || k === "arrowright") {
        e.preventDefault();
        offset((k === "arrowleft" ? -1 : 1) * (e.shiftKey ? 1 : 1 / 30));
    } else if (k === "home" || k === "end") {
        e.preventDefault();
        $(k === "home" ? "begin" : "end").click();
    } else if (k === "s") {
        splitAt();
    } else if (k === "b") $("razor").click();
    else if (k === "v") $("pointer").click();
    else if (k === "n") $("snap").click();
    else if (k === "i") trimAt("in");
    else if (k === "o") trimAt("out");
    else if (k === "delete" || k === "backspace") {
        e.preventDefault();
        removeSeg();
    } else if (k === "+" || k === "=") setZoom(zoom + 0.5);
    else if (k === "-") setZoom(zoom - 0.5);
});
let tipTimer = null;
function hideTip() {
    clearTimeout(tipTimer);
    $("tooltip").hidden = true;
}
document.addEventListener("pointerover", (e) => {
    const b = e.target.closest("[data-tip]");
    if (!b) return;
    hideTip();
    tipTimer = setTimeout(() => {
        if (!b.isConnected || !$("menu").hidden) return;
        const t = $("tooltip");
        t.textContent = b.dataset.tip;
        t.hidden = false;
        const r = b.getBoundingClientRect();
        t.style.left =
            clamp(r.left + (r.width - t.offsetWidth) / 2, 4, innerWidth - t.offsetWidth - 4) + "px";
        t.style.top = clamp(r.bottom + 6, 4, innerHeight - t.offsetHeight - 4) + "px";
    }, 480);
});
document.addEventListener("pointerout", (e) => {
    if (e.target.closest("[data-tip]")) hideTip();
});
document.addEventListener("pointerdown", hideTip);
window.addEventListener("resize", () => {
    if (!drag) drawTimeline();
});
window.addEventListener("blur", hideTip);
const studioWindowConnection = new EventSource("/api/window");
window.addEventListener("beforeunload", (e) => {
    if (state.recording) {
        e.preventDefault();
        e.returnValue = "";
    }
});
draw();
refresh(true);
setInterval(() => {
    if (!pending && !document.hidden) refresh();
}, 900);
