"""Offline, non-destructive Everbranch Studio editing and delivery."""
from __future__ import annotations

import hashlib
import json
import math
import os
import re
import shutil
import subprocess
import tempfile
import threading
import uuid
from datetime import datetime, timezone
from pathlib import Path

VIDEO = {".mp4", ".mov", ".mkv", ".webm", ".m4v", ".avi"}
AUDIO = {".wav", ".flac", ".mp3", ".aac", ".m4a", ".ogg", ".webm", ".mkv"}
KINDS = {"camera", "voice", "music"}
FRAMES = {"wide", "left", "center", "right", "fit"}
LOCAL_FORMATS = "mov,mp4,m4a,3gp,3g2,mj2,matroska,webm,avi,mp3,aac,flac,wav,ogg"
LOCK = threading.Lock()


def library():
    if os.environ.get("EVERBRANCH_STUDIO_HOME"):
        return Path(os.environ["EVERBRANCH_STUDIO_HOME"]).expanduser().resolve()
    if os.name=="nt" and Path("G:/").is_dir():
        return Path("G:/Everbranch/Studio")
    return Path.home() / "Everbranch" / "Studio"


def ffmpeg():
    portable = sorted((library() / "tools").glob("ffmpeg*/bin/ffmpeg.exe"), reverse=True)
    for candidate in [os.environ.get("EVERBRANCH_FFMPEG"), *portable, shutil.which("ffmpeg")]:
        if candidate and Path(candidate).is_file():
            return str(Path(candidate).resolve())
    try:
        import imageio_ffmpeg
        return imageio_ffmpeg.get_ffmpeg_exe()
    except ImportError:
        raise ValueError("Install FFmpeg on PATH or run python -m pip install imageio-ffmpeg.")


def run(args, cwd=None):
    result = subprocess.run([ffmpeg(), "-hide_banner", "-nostdin", "-loglevel", "error", *args],
                            cwd=cwd, capture_output=True, text=True, encoding="utf-8", errors="replace")
    if result.returncode:
        raise ValueError(result.stderr[-2400:] or "FFmpeg could not finish.")


def metadata(path):
    result = subprocess.run([ffmpeg(), "-hide_banner", "-nostdin", "-format_whitelist", LOCAL_FORMATS, "-protocol_whitelist", "file,pipe", "-i", str(path)],
                            capture_output=True, text=True, encoding="utf-8", errors="replace")
    duration = re.search(r"Duration:\s*(\d+):(\d+):([\d.]+)", result.stderr)
    if not duration:
        raise ValueError("This file has no readable duration. Finish or recover the recording before importing.")
    return {"duration": int(duration[1])*3600 + int(duration[2])*60 + float(duration[3]),
            "video": bool(re.search(r"Stream #[^\n]+Video:", result.stderr)),
            "audio": bool(re.search(r"Stream #[^\n]+Audio:", result.stderr))}


def atomic_json(path, data):
    path.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.NamedTemporaryFile(mode="w", dir=path.parent, encoding="utf-8", delete=False) as out:
        json.dump(data, out, indent=2, ensure_ascii=False, allow_nan=False)
        out.write("\n")
        temp = Path(out.name)
    os.replace(temp, path)


def utc():
    return datetime.now(timezone.utc).isoformat()


def new_project(root, title):
    if not title.strip():
        raise ValueError("Name the episode.")
    root = Path(root)
    root.mkdir(parents=True, exist_ok=True)
    proj = {"schema_version": 1, "episode_id": str(uuid.uuid4()), "title": title.strip(), "description": "",
            "visibility": "private", "sources": [], "captions": [], "clips": [], "product_tags": [],
            "edit": {"start": 0, "end": None, "camera": "", "include_camera_audio": True,
                     "intro": "", "intro_seconds": 2.5, "music_licensed": False}}
    (root / "episode.json").touch(exist_ok=False)
    atomic_json(root / "episode.json", proj)
    return proj


def bounded(root, path):
    result = (Path(root) / path).resolve()
    if not result.is_relative_to(Path(root).resolve()) or result == Path(root).resolve():
        raise ValueError("Media path falls outside the project.")
    return result


def number(value, low, high, label):
    try:
        value = float(value)
    except (TypeError, ValueError):
        raise ValueError(f"Enter a number for {label}.") from None
    if not math.isfinite(value) or not low <= value <= high:
        raise ValueError(f"{label} must be between {low:g} and {high:g}.")
    return value


def import_source(root, p, path, kind="camera", offset=0):
    path = Path(path)
    if kind not in KINDS or path.suffix.lower() not in (VIDEO if kind == "camera" else AUDIO):
        raise ValueError("Select a supported recording for this track.")
    offset = number(offset, -86400, 86400, "offset")
    key = uuid.uuid4().hex
    remuxed = None
    try:
        meta = metadata(path)
    except ValueError:
        if path.suffix.lower() not in {".webm", ".mkv", ".mp4", ".m4a"}:
            raise
        # Browsers and interrupted captures may omit a container's duration/index.
        remuxed = Path(root) / "recovered" / (key + ".mkv")
        remuxed.parent.mkdir(exist_ok=True)
        try:
            run(["-fflags", "+genpts", "-format_whitelist", LOCAL_FORMATS, "-protocol_whitelist", "file,pipe", "-i", str(path.resolve()),
                 "-map", "0:v?", "-map", "0:a?", "-c", "copy", str(remuxed)])
            meta = metadata(remuxed)
        except BaseException:
            remuxed.unlink(missing_ok=True)
            raise
    if not meta["video" if kind == "camera" else "audio"]:
        if remuxed:
            remuxed.unlink(missing_ok=True)
        raise ValueError("This recording does not have the selected type of track.")
    target = Path(root) / "originals" / (key + path.suffix.lower())
    target.parent.mkdir(exist_ok=True)
    try:
        with path.open("rb") as src, target.open("xb") as dst:
            shutil.copyfileobj(src, dst, 4*1024*1024)
    except BaseException:
        target.unlink(missing_ok=True)
        raise
    track = {"id": key, "label": path.stem, "path": (remuxed or target).relative_to(root).as_posix(),
             "original_path": target.relative_to(root).as_posix(), "kind": kind,
             "offset": number(offset, -86400, 86400, "offset"), "gain_db": 0, "enabled": True, **meta}
    p["sources"].append(track)
    if kind == "camera" and not p["edit"]["camera"]:
        p["edit"]["camera"] = key
        p["edit"]["end"] = meta["duration"]
    return track


def digest(path):
    with path.open("rb") as src:
        return hashlib.file_digest(src, "sha256").hexdigest()


def fingerprint(p):
    return hashlib.sha256(json.dumps(p, sort_keys=True, ensure_ascii=False, allow_nan=False).encode()).hexdigest()


def stamp(seconds):
    ms = round(seconds*1000)
    return f"{ms//3600000:02}:{ms//60000%60:02}:{ms//1000%60:02}.{ms%1000:03}"


def write_captions(path, captions, start, end, lead=0):
    lines = ["WEBVTT", ""]
    for c in sorted(captions, key=lambda cue: cue["start"]):
        a, b = max(c["start"], start), min(c["end"], end)
        if b-a < 0.001:
            continue
        text = " ".join(str(c["text"]).split()).replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")
        lines += [f"{stamp(lead+a-start)} --> {stamp(lead+b-start)}", text, ""]
    path.write_text("\n".join(lines)+"\n", encoding="utf-8")


def parse_captions(data):
    cues = []
    pattern = re.compile(r"(?:(\d+):)?(\d{2}):(\d{2})[.,](\d{3})")
    for block in re.split(r"\n\s*\n", data.replace("\r", "").strip()):
        lines = block.split("\n")
        n = next((i for i, line in enumerate(lines) if "-->" in line), None)
        if n is None:
            continue
        matches = pattern.findall(lines[n])
        if len(matches) != 2:
            raise ValueError("Invalid caption timestamp.")
        a, b = (int(h or 0)*3600+int(m)*60+int(s)+int(ms)/1000 for h,m,s,ms in matches)
        text = " ".join(lines[n+1:]).strip()
        if b > a and text:
            import html
            cues.append({"start": a, "end": b, "text": html.unescape(text)})
    return cues


def suggestions(p):
    """Use complete caption cues and punctuation for editable, explainable cuts."""
    first = p["edit"]["start"]
    last = p["edit"]["end"]
    if last is None:
        raise ValueError("Import a camera track first.")
    cues = [c for c in sorted(p["captions"], key=lambda cue: cue["start"]) if c["start"] >= first and c["end"] <= last]
    if not cues:
        raise ValueError("Import captions or transcribe the episode before suggesting clips.")
    candidates = []
    for i, cue in enumerate(cues):
        window = []
        for c in cues[i:]:
            if c["end"] - cue["start"] > 75:
                break
            window.append(c)
            if c["end"] - cue["start"] >= 22 and re.search(r"[.!?][\"’”']?$", c["text"]):
                break
        if not window:
            continue
        duration = window[-1]["end"] - cue["start"]
        score = 2*("?" in cue["text"]) + bool(re.search(r"\b(how|why|learned|started|because)\b", cue["text"], re.I))
        score += min(duration/22, 1) + bool(re.search(r"[.!?][\"’”']?$", window[-1]["text"]))
        candidates.append((score, cue["start"], window[-1]["end"], cue["text"]))
    used, selected = [], []
    for score, a, b, text in sorted(candidates, key=lambda item: (-item[0], item[1])):
        if any(a < y and b > x for x,y in used):
            continue
        kind = "trailer" if not selected else "short"
        selected.append({"kind": kind, "start": a, "end": b, "framing": "wide" if kind=="trailer" else ("fit" if len([s for s in p["sources"] if s["kind"]=="camera" and s["enabled"]])>1 else "center"),
                         "camera": p["edit"]["camera"], "label": text[:90], "burn_captions": True})
        used.append((a,b))
        if len(selected) == 4:
            break
    return selected


def suggest_tags(p,catalog):
    items=catalog if isinstance(catalog,list) else catalog.get("items",catalog.get("products",[]))
    if not isinstance(items,list):
        raise ValueError("Choose a maker catalog containing variant IDs and names.")
    def words(text):
        return " ".join(re.findall(r"[a-z0-9]+",str(text).casefold()))
    candidates=[]
    for item in items:
        if not isinstance(item,dict):
            continue
        key=item.get("variant_id") or item.get("key")
        name=item.get("label") or item.get("name") or ""
        variant=item.get("variant_label") or item.get("variant") or ""
        if not isinstance(key,str) or not name:
            continue
        aliases=item.get("aliases",[])
        aliases=aliases if isinstance(aliases,list) else []
        phrases={words(" ".join([str(name),str(variant)])),*(words(alias) for alias in aliases if isinstance(alias,str))}
        candidates.append((key," ".join(x for x in (str(name),str(variant)) if x),phrases))
    # A shared name alone cannot identify which of two variants appeared.
    owners={}
    for key,_,phrases in candidates:
        for phrase in phrases:
            owners.setdefault(phrase,set()).add(key)
    out=[]
    edit=p["edit"]
    for cue in p["captions"]:
        content=" "+words(cue["text"])+" "
        a,b=max(edit["start"],cue["start"]),min(edit["end"],cue["end"])
        if b<=a:
            continue
        for key,label,phrases in candidates:
            if any(phrase and len(owners[phrase])==1 and " "+phrase+" " in content for phrase in phrases):
                tag={"variant_id":key,"label":label,"start":a,"end":b}
                if not any(t["variant_id"]==key and t["start"]<b and t["end"]>a for t in [*out,*p.get("product_tags",[])]):
                    out.append(tag)
    return out


def verify(p):
    cameras = {s["id"]: s for s in p["sources"] if s["kind"]=="camera" and s.get("enabled", True)}
    master = cameras.get(p["edit"]["camera"])
    if not master:
        raise ValueError("Select an enabled camera or video recording.")
    if p["visibility"] not in {"private","public","members"} or not p["title"].strip():
        raise ValueError("Choose a visibility and episode title.")
    if any(s["kind"]=="music" and s.get("enabled",True) for s in p["sources"]) and not p["edit"]["music_licensed"]:
        raise ValueError("Confirm that you have permission to use the music before rendering.")
    for s in p["sources"]:
        number(s["gain_db"], -60, 24, "track gain")
        number(s["offset"], -86400, 86400, "track offset")
    for c in p["captions"]:
        a = number(c["start"],0,86400,"caption start")
        number(c["end"],a+0.001,86400,"caption end")
    for i, cut in enumerate([p["edit"], *p["clips"]]):
        camera = cameras.get(cut.get("camera") or master["id"])
        if not camera:
            raise ValueError("Choose a camera for each cut.")
        standalone = cut.get("timeline")=="source"
        offset = 0 if standalone else camera["offset"]
        a = number(cut["start"],max(0,offset),offset+camera["duration"],"cut start")
        b = number(cut["end"],a+0.01,offset+camera["duration"]+0.005,"cut end")
        outside_master = not standalone and (a<p["edit"]["start"] or b>p["edit"]["end"])
        if i and (outside_master or cut["kind"] not in {"trailer","short"} or cut["framing"] not in FRAMES):
            raise ValueError("Choose an in-range clip and a supported format.")
    if p["edit"].get("intro","").strip():
        number(p["edit"]["intro_seconds"], 0.25, 30, "intro seconds")
    for tag in p.get("product_tags", []):
        if not isinstance(tag,dict) or not isinstance(tag.get("variant_id"),str) or not tag["variant_id"].strip():
            raise ValueError("Each suggested product tag needs a variant_id.")
        a = number(tag.get("start"),p["edit"]["start"],p["edit"]["end"],"product tag start")
        number(tag.get("end"),a+0.001,p["edit"]["end"],"product tag end")


def audio_inputs(root, p, a, b, args, graph, first_input):
    edit = p["edit"]
    tracks = [s for s in p["sources"] if s.get("enabled",True) and s["audio"] and
              (s["kind"] in {"voice","music"} or (s["id"]==edit["camera"] and edit["include_camera_audio"]))]
    feeds = []
    for track in tracks:
        start, end = max(a,track["offset"]), min(b,track["offset"]+track["duration"])
        if end <= start:
            continue
        idx = first_input+len(feeds)
        args += ["-ss", f"{start-track['offset']:.6f}", "-t", f"{end-start:.6f}", "-protocol_whitelist", "file,pipe",
                 "-i", str(bounded(root,track["path"]))]
        name = f"track{idx}"
        gain = track["gain_db"] + (-23 if track["kind"]=="music" else 0)
        graph.append(f"[{idx}:a:0]aresample=48000,aformat=channel_layouts=stereo,volume={gain}dB,adelay={round(1000*(start-a))}:all=1,apad=whole_dur={b-a}[{name}]")
        feeds.append(f"[{name}]")
    if feeds:
        graph.append("".join(feeds)+f"amix=inputs={len(feeds)}:normalize=0,atrim=duration={b-a},loudnorm=I=-16:TP=-1.5:LRA=11,aresample=48000[audio]")
    else:
        graph.append(f"anullsrc=r=48000:cl=stereo,atrim=duration={b-a}[audio]")
    return bool(feeds)


def camera_segments(p,cut):
    sources={s["id"]:s for s in p["sources"] if s["kind"]=="camera" and s["enabled"]}
    current=sources[cut.get("camera") or p["edit"]["camera"]]
    start,end=cut["start"],cut["end"]
    changes=sorted(cut.get("switches",[]),key=lambda change:change["at"])
    segments=[]
    previous=start
    for change in changes:
        at=number(change["at"],0,86400,"camera switch time")
        if change["camera"] not in sources:
            raise ValueError("A camera switch references a disabled or missing camera.")
        if at>end:
            break
        if at>previous:
            segments.append((current,previous,at))
        current=sources[change["camera"]]
        previous=max(previous,at)
    if previous<end:
        segments.append((current,previous,end))
    for track,a,b in segments:
        if a<track["offset"] or b>track["offset"]+track["duration"]+0.005:
            raise ValueError(f"Camera {track['label']} does not cover its timeline segment.")
    return segments


def render_cut(root, p, cut, work, name, wide, fps):
    a,b = cut["start"],cut["end"]
    if cut.get("timeline")=="source":
        source=next(s for s in p["sources"] if s["id"]==cut["camera"])
        cues=p["captions"] if source["id"]==p["edit"]["camera"] and source["offset"]==0 else source.get("source_captions",[])
        p={**p,"sources":[{**source,"offset":0}],"captions":cues,
           "edit":{**p["edit"],"camera":source["id"],"include_camera_audio":True,"intro":""}}
    segments = camera_segments(p,cut)
    frame = cut.get("framing","wide")
    w,h = wide if frame=="wide" else wide[::-1]
    lead = p["edit"]["intro_seconds"] if name=="episode" and p["edit"].get("intro","").strip() else 0
    caption = work / (name+".vtt")
    write_captions(caption,p["captions"],a,b,lead)
    args,graph=[],[]
    for cam,begin,finish in segments:
        args += ["-ss",f"{begin-cam['offset']:.6f}","-t",f"{finish-begin:.6f}",
                 "-protocol_whitelist","file,pipe","-i",str(bounded(root,cam["path"]))]
    audio_inputs(root,p,a,b,args,graph,len(segments))
    if frame in {"wide","fit"}:
        shape = f"scale={w}:{h}:force_original_aspect_ratio=decrease:force_divisible_by=2,pad={w}:{h}:(ow-iw)/2:(oh-ih)/2"
    else:
        xpos={"left":"0","right":"iw-ow","center":"(iw-ow)/2"}[frame]
        shape = f"scale={w}:{h}:force_original_aspect_ratio=increase:force_divisible_by=2,crop={w}:{h}:{xpos}:(ih-oh)/2"
    views=[]
    for idx,_ in enumerate(segments):
        graph.append(f"[{idx}:v:0]setpts=PTS-STARTPTS,{shape},setsar=1,fps={fps},format=yuv420p[angle{idx}]")
        views.append(f"[angle{idx}]")
    graph.append("".join(views)+f"concat=n={len(views)}:v=1:a=0[footage]")
    vid,aud="footage","audio"
    if lead:
        (work/"intro.txt").write_text(p["edit"]["intro"].strip(),encoding="utf-8")
        graph += [f"color=c=0x17242c:s={w}x{h}:r={fps}:d={lead},drawtext=textfile=intro.txt:expansion=none:fontcolor=white:fontsize={max(12,h//18)}:x=(w-text_w)/2:y=(h-text_h)/2[title]",
                  f"anullsrc=r=48000:cl=stereo,atrim=duration={lead}[quiet]",
                  "[title][quiet][footage][audio]concat=n=2:v=1:a=1[program][mix]"]
        vid,aud="program","mix"
    if cut.get("burn_captions",False) and "-->" in caption.read_text(encoding="utf-8"):
        graph.append(f"[{vid}]subtitles={caption.name}:force_style='FontName=Segoe UI,FontSize=17,Outline=2,MarginV=20'[captioned]")
        vid="captioned"
    run([*args,"-filter_complex_threads","2","-filter_complex",";".join(graph),"-map",f"[{vid}]","-map",f"[{aud}]",
         "-t",f"{b-a+lead:.6f}","-c:v","libx264","-preset","veryfast","-crf","19","-pix_fmt","yuv420p","-c:a","aac",
         "-b:a","192k","-ar","48000","-ac","2","-movflags","+faststart",name+".mp4"], work)
    return lead


def render(root, p, wide=(1920,1080), fps=30):
    root=Path(root).resolve()
    verify(p)
    revision=str(uuid.uuid4())
    exports=root/"exports"
    exports.mkdir(exist_ok=True)
    temp=Path(tempfile.mkdtemp(prefix=".draft-",dir=exports))
    assets=[]
    def add(kind, name, mime, **extra):
        path=temp/name
        assets.append({"kind":kind,"path":name,"mime_type":mime,"bytes":path.stat().st_size,"sha256":digest(path),**extra})
    try:
        lead=render_cut(root,p,p["edit"],temp,"episode",wide,fps)
        add("episode_video","episode.mp4","video/mp4")
        add("captions","episode.vtt","text/vtt",video="episode.mp4")
        run(["-i","episode.mp4","-vn","-c:a","libmp3lame","-b:a","160k","episode.mp3"],temp)
        add("episode_audio","episode.mp3","audio/mpeg")
        clips=[]
        counts={"short":0,"trailer":0}
        for cut in p["clips"]:
            kind=cut["kind"]
            counts[kind]+=1
            name=f"{kind}-{counts[kind]:02}"
            render_cut(root,p,cut,temp,name,wide,fps)
            add(kind,name+".mp4","video/mp4")
            add("captions",name+".vtt","text/vtt",video=name+".mp4")
            clips.append({**cut,"path":name+".mp4","captions":name+".vtt"})
        tags=[{**tag,"start":tag["start"]-p["edit"]["start"]+lead,"end":tag["end"]-p["edit"]["start"]+lead}
              for tag in p.get("product_tags",[])]
        for clip in clips:
            if clip.get("timeline")=="source":
                source=next(s for s in p["sources"] if s["id"]==clip["camera"])
                origin=source.get("origin",{})
                clip["source_platform"]=origin.get("platform","")
                clip["source_url"]=origin.get("creator_url","")
                if source["id"]!=p["edit"]["camera"]:
                    clip["product_tags"]=[]
                    continue
            clip["product_tags"]=[{**tag,"start":max(tag["start"],clip["start"])-clip["start"],
                                  "end":min(tag["end"],clip["end"])-clip["start"]}
                                 for tag in p.get("product_tags",[]) if tag["start"]<clip["end"] and tag["end"]>clip["start"]]
        rundown=[]
        for item in p.get("sponsor_rundown",[]):
            placement=item.get("placement")
            active=placement is not None and p["edit"]["start"]<=placement<p["edit"]["end"]
            matching=[clip["path"] for clip in clips if clip.get("booking_id")==item["booking_id"]]
            rundown.append({**item,"program_position":placement-p["edit"]["start"]+lead if active else None,
                            "clip_paths":matching})
        manifest={k:p[k] for k in ("schema_version","episode_id","title","description","visibility")}
        manifest.update(revision=revision,approval="draft",created_at=utc(),edit_fingerprint=fingerprint(p),
                        assets=assets,clips=clips,product_tags=tags,sponsor_rundown=rundown)
        (temp/"intro.txt").unlink(missing_ok=True)
        atomic_json(temp/"studio-package.json",manifest)
        final=exports/revision
        temp.rename(final)
        return final
    except BaseException:
        shutil.rmtree(temp,ignore_errors=True)
        raise


def approve(root,p,revision):
    folder=bounded(Path(root)/"exports",str(uuid.UUID(revision)))
    manifest=json.loads((folder/"studio-package.json").read_text(encoding="utf-8"))
    if fingerprint(p)!=manifest["edit_fingerprint"]:
        raise ValueError("The edit has changed. Render and review a new revision.")
    for a in manifest["assets"]:
        file=bounded(folder,a["path"])
        if file.stat().st_size!=a["bytes"] or digest(file)!=a["sha256"]:
            raise ValueError("An exported asset changed. Render and review a new revision.")
    manifest.update(approval="approved",approved_at=utc())
    atomic_json(folder/"studio-package.json",manifest)
    return folder


def transcribe(root,p,model,cli):
    verify(p)
    if not Path(cli).is_file() or not Path(model).is_file():
        raise ValueError("Install the local caption tool using setup-captions.ps1, or import WebVTT/SRT.")
    scratch=Path(root)/"cache"
    scratch.mkdir(exist_ok=True)
    with tempfile.TemporaryDirectory(dir=scratch) as folder:
        tmp=Path(folder)
        args,graph=[],[]
        speech={**p,"sources":[s for s in p["sources"] if s["kind"]!="music"]}
        a,b=p["edit"]["start"],p["edit"]["end"]
        if not audio_inputs(root,speech,a,b,args,graph,0):
            raise ValueError("Enable a voice track or the primary camera microphone first.")
        run([*args,"-filter_complex",";".join(graph),"-map","[audio]","-ac","1","-ar","16000","speech.wav"],tmp)
        result=subprocess.run([str(cli),"-m",str(model),"-f","speech.wav","-l","en","-osrt","-of","words"],
                              cwd=tmp,capture_output=True,text=True,encoding="utf-8",errors="replace")
        if result.returncode:
            raise ValueError(result.stderr[-2000:])
        cues=parse_captions((tmp/"words.srt").read_text(encoding="utf-8-sig"))
        return [{**c,"start":c["start"]+a,"end":c["end"]+a} for c in cues]
