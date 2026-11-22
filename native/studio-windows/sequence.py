"""Render an ordered, non-destructive edit list on Studio's synchronized source timeline."""
import math
import re
import subprocess
import tempfile
from pathlib import Path

import media


def segments(project):
    """Expand a legacy camera-switch edit or validate the editor's ordered source ranges."""
    edit = project["edit"]
    active = {track["id"]: track for track in project["sources"]
              if track["kind"] == "camera" and track.get("enabled", True)}
    ordered = project.get("sequence", [])
    if not isinstance(ordered, list):
        raise ValueError("The sequence must be a list of camera cuts.")
    if not ordered:
        if edit.get("camera") not in active:
            raise ValueError("Select an enabled camera before editing the sequence.")
        ordered = [{"camera": camera["id"], "start": a, "end": b}
                   for camera, a, b in media.camera_segments(project, edit)]
    result = []
    for cut in ordered:
        if not isinstance(cut, dict) or not isinstance(cut.get("camera"), str) or cut["camera"] not in active:
            raise ValueError("A sequence cut uses a missing or disabled camera.")
        camera = active[cut["camera"]]
        offset = float(camera["offset"])
        limit = offset + float(camera["duration"])
        start = media.number(cut.get("start"), max(0, offset), limit, "sequence cut start")
        end = media.number(cut.get("end"), start + 0.001, limit + 0.005, "sequence cut end")
        end = min(end, limit)
        if end <= start:
            raise ValueError("A sequence cut must include some footage.")
        result.append({"camera": camera["id"], "start": start, "end": end})
    return result


def duration(cuts):
    return math.fsum(cut["end"] - cut["start"] for cut in cuts)


def intervals(cuts, rows, lead=0):
    """Map every retained occurrence, including repeated ranges, into the delivered program."""
    mapped = []
    cursor = lead
    for cut in cuts:
        for row in rows:
            start, end = max(row["start"], cut["start"]), min(row["end"], cut["end"])
            if end - start >= 0.001:
                mapped.append({**row, "start": round(cursor + start - cut["start"], 6),
                               "end": round(cursor + end - cut["start"], 6)})
        cursor += cut["end"] - cut["start"]
    return sorted(mapped, key=lambda row: row["start"])


def positions(cuts, value, lead=0):
    if value is None:
        return []
    hits = []
    cursor = lead
    for cut in cuts:
        if cut["start"] <= value < cut["end"]:
            hits.append(round(cursor + value - cut["start"], 6))
        cursor += cut["end"] - cut["start"]
    return hits


def microphone(project, cut):
    return cut["camera"] if project.get("sequence") else project["edit"]["camera"]


def _encode(args, graph, filename, work, length):
    media.run([*args, "-filter_complex_threads", "2", "-filter_complex", ";".join(graph),
               "-map", "[picture]", "-map", "[sound]", "-t", f"{length:.9f}",
               "-c:v", "libx264", "-preset", "veryfast", "-crf", "19", "-pix_fmt", "yuv420p",
               "-c:a", "pcm_s16le", "-ar", "48000", "-ac", "2", filename], work)


def _title(text, key, length, size, fps, work):
    w, h = size
    (work / f"{key}.txt").write_text(text.strip(), encoding="utf-8")
    graph = [f"color=c=0x17242c:s={w}x{h}:r={fps}:d={length},"
             f"drawtext=textfile={key}.txt:expansion=none:fontcolor=white:fontsize={max(12,h//18)}:"
             "x=(w-text_w)/2:y=(h-text_h)/2[picture]",
             f"anullsrc=r=48000:cl=stereo,atrim=duration={length}[sound]"]
    _encode([], graph, f"{key}.mkv", work, length)


def _part(root, project, camera, cut, length, size, fps, work, filename):
    start, end = cut["start"], cut["end"]
    w, h = size
    # Preserve the source interval's master mix with the chosen angle's camera microphone.
    args = ["-ss", f"{start-camera['offset']:.9f}", "-t", f"{end-start:.9f}",
            "-format_whitelist", media.LOCAL_FORMATS, "-protocol_whitelist", "file,pipe",
            "-i", str(media.bounded(root, camera["path"]))]
    graph = []
    mix = {**project, "edit": {**project["edit"], "camera": microphone(project, cut)}}
    media.audio_inputs(root, mix, start, end, args, graph, 1, normalize=False)
    graph += [f"[0:v:0]setpts=PTS-STARTPTS,scale={w}:{h}:force_original_aspect_ratio=decrease:"
              f"force_divisible_by=2,pad={w}:{h}:(ow-iw)/2:(oh-ih)/2,setsar=1,fps={fps},"
              f"tpad=stop_mode=clone:stop_duration={2/fps},trim=duration={length},format=yuv420p[picture]",
              f"[audio]apad,atrim=duration={length},asetpts=PTS-STARTPTS[sound]"]
    _encode(args, graph, filename, work, length)


def render_episode(root, project, cuts, output, size, fps):
    """Encode one angle at a time so long edits don't exceed OS input/argument limits."""
    edit = project["edit"]
    cameras = {track["id"]: track for track in project["sources"]}
    titles = {}
    for key in ("intro", "outro"):
        if (edit.get(key) or "").strip():
            seconds = media.number(edit.get(key + "_seconds", 2.5), 0.25, 30, key + " duration")
            titles[key] = max(1, round(seconds * fps)) / fps
    lead = titles.get("intro", 0)
    total = duration(cuts)
    media.write_captions(output / "episode.vtt", intervals(cuts, project["captions"], lead), 0, lead + total)
    with tempfile.TemporaryDirectory(dir=output, prefix=".sequence-") as temp:
        work = Path(temp)
        parts = []
        if lead:
            _title(edit["intro"], "intro", lead, size, fps, work)
            parts.append(("intro.mkv", lead))
        elapsed = 0
        for index, cut in enumerate(cuts):
            previous_frames = round(elapsed * fps)
            elapsed += cut["end"] - cut["start"]
            # Quantize cumulative boundaries, so many fractional cuts never accumulate frame drift.
            length = (round(elapsed * fps) - previous_frames) / fps
            if length <= 0:
                continue
            filename = f"cut-{index:06d}.mkv"
            _part(root, project, cameras[cut["camera"]], cut, length, size, fps, work, filename)
            parts.append((filename, length))
        if round(elapsed * fps) <= 0:
            raise ValueError("The sequence needs at least one video frame.")
        if "outro" in titles:
            _title(edit["outro"], "outro", titles["outro"], size, fps, work)
            parts.append(("outro.mkv", titles["outro"]))
        listing = "ffconcat version 1.0\n" + "".join(f"file {name}\nduration {length:.9f}\n" for name, length in parts)
        (work / "cuts.ffconcat").write_text(listing, encoding="ascii")
        captions = output / "episode.vtt"
        codec = ["-c:v", "copy"]
        if edit.get("burn_captions") and "-->" in captions.read_text(encoding="utf-8"):
            (work / "captions.vtt").write_bytes(captions.read_bytes())
            codec = ["-vf", "subtitles=captions.vtt", "-c:v", "libx264", "-preset", "veryfast", "-crf", "19"]
        # Normalize once across the entire program. Keep intermediate audio uncompressed.
        media.run(["-protocol_whitelist", "file,pipe", "-f", "concat", "-safe", "1", "-i", "cuts.ffconcat",
                   "-map", "0:v:0", "-map", "0:a:0", *codec,
                   "-af", "loudnorm=I=-16:TP=-1.5:LRA=11,aresample=48000", "-c:a", "aac", "-b:a", "192k",
                   "-ar", "48000", "-ac", "2", "-movflags", "+faststart", str(output / "episode.mp4")], work)
    return lead

def _merged(spans):
    merged = []
    for start, end in sorted(spans):
        if end <= start:
            continue
        if merged and start <= merged[-1][1] + 0.001:
            merged[-1] = (merged[-1][0], max(merged[-1][1], end))
        else:
            merged.append((start, end))
    return merged


def _activity(root, track, start, end, threshold):
    """Read each microphone separately. Two speakers' waveforms must never cancel each other."""
    args = [media.ffmpeg(), "-hide_banner", "-nostdin", "-nostats", "-loglevel", "info",
            "-ss", f"{start-track['offset']:.9f}", "-t", f"{end-start:.9f}",
            "-format_whitelist", media.LOCAL_FORMATS, "-protocol_whitelist", "file,pipe",
            "-i", str(media.bounded(root, track["path"])), "-map", "0:a:0", "-vn",
            "-af", f"asetpts=PTS-STARTPTS,volume={track['gain_db']}dB,silencedetect=noise={threshold}dB:d=0.01",
            "-f", "null", "-"]
    result = subprocess.run(args, capture_output=True, text=True, encoding="utf-8", errors="replace")
    if result.returncode:
        raise ValueError(result.stderr[-2000:] or "FFmpeg could not analyze this audio track.")
    active, cursor = [], start
    for kind, seconds in re.findall(r"silence_(start|end):\s*([-+\deE.]+)", result.stderr):
        stamp = min(end, max(start, start + float(seconds)))
        if kind == "start":
            if stamp > cursor:
                active.append((cursor, stamp))
            cursor = end
        else:
            cursor = stamp
    if cursor < end:
        active.append((cursor, end))
    return active


def analyze_gaps(root, p, threshold_db=-35, min_silence=0.8, padding=0.18):
    """Propose pre-title program ranges without altering the project or its recordings."""
    threshold = media.number(threshold_db, -100, -1, "silence threshold")
    minimum = media.number(min_silence, 0.05, 120, "minimum silence")
    handles = media.number(padding, 0, 10, "speech padding")
    cuts = segments(p)
    total = duration(cuts)
    activities = []
    for track in p["sources"]:
        if not track.get("enabled", True) or track.get("muted", False) or not track.get("audio"):
            continue
        if track["kind"] == "music" or (track["kind"] == "camera" and not p["edit"]["include_camera_audio"]):
            continue
        edit_windows = []
        cursor = 0
        for cut in cuts:
            if track["kind"] == "voice" or track["id"] == microphone(p, cut):
                a = max(cut["start"], track["offset"])
                b = min(cut["end"], track["offset"] + track["duration"])
                if b > a:
                    edit_windows.append((a, b, cursor - cut["start"]))
            cursor += cut["end"] - cut["start"]
        # Read retained source ranges once even if several edit decisions use the same material.
        active = []
        for a, b in _merged((a, b) for a, b, _ in edit_windows):
            active.extend(_activity(root, track, a, b, threshold))
        for a, b, offset in edit_windows:
            activities.extend((max(a, left) + offset, min(b, right) + offset)
                              for left, right in active if left < b and right > a)
    speech = _merged(activities)
    if not speech:
        # No detected speech may mean an episode is intentionally silent or the mic was muted.
        return []
    gaps = []
    cursor = 0.0
    for start, end in [*speech, (total, total)]:
        if start - cursor >= minimum:
            left = cursor + handles if cursor > 0 else 0.0
            right = start - handles if start < total else total
            if right - left >= 0.01:
                gaps.append({"start": round(left, 6), "end": round(right, 6)})
        cursor = max(cursor, end)
    # Even a very short utterance with zero handles must leave a usable program.
    spare = math.fsum(gap["end"] - gap["start"] for gap in gaps) - max(0, total - min(total, 0.25))
    while spare > 0.000001 and gaps:
        gap = gaps[-1]
        give_back = min(spare, gap["end"] - gap["start"])
        gap["end"] = round(gap["end"] - give_back, 6)
        spare -= give_back
        if gap["end"] - gap["start"] < 0.01:
            gaps.pop()
    return gaps
