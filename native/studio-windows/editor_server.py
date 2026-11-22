"""Loopback-only bridge between Everbranch Studio's editing window and the local media engine."""
from __future__ import annotations

import argparse
import array
import copy
import hmac
import json
import math
import mimetypes
import os
import re
import secrets
import subprocess
import sys
import tempfile
import threading
import time
import uuid
import webbrowser
from collections import deque
from http.cookies import SimpleCookie
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, urlsplit

import media
import preview
from capture import Capture
from rundown import parse_rundown

HERE = Path(__file__).resolve().parent
UI = HERE / "editor-ui"


def picker(action, root):
    result = subprocess.run([sys.executable, "-B", str(HERE / "editor_dialog.py"), action, str(root)], capture_output=True, text=True, encoding="utf-8")
    if result.returncode:
        raise ValueError(result.stderr.strip().splitlines()[-1] if result.stderr.strip() else "The picker closed unexpectedly.")
    return json.loads(result.stdout)


def cut_ranges(segments, ranges):
    """Subtract selected program intervals. Keep source coordinates and clip order."""
    if not ranges:
        raise ValueError("Select at least one gap.")
    result = []
    at = 0.0
    for clip in segments:
        end = at + clip["end"] - clip["start"]
        cursor = at
        for left, right in ranges:
            if right <= cursor or left >= end:
                continue
            if left - cursor >= .01:
                result.append({**clip, "start": clip["start"] + cursor - at, "end": clip["start"] + left - at})
            cursor = max(cursor, min(right, end))
        if end - cursor >= .01:
            result.append({**clip, "start": clip["start"] + cursor - at, "end": clip["end"]})
        at = end
    if not result:
        raise ValueError("Keep at least one part of the program.")
    return result


class EditingSession:
    def __init__(self):
        self.root = self.project = self.lockfile = self.capture = None
        self.history = deque(maxlen=40)
        self.redo = deque(maxlen=40)
        self.busy = False
        self.status = ""
        self.gaps = []
        self.mutex = threading.RLock()
        self.cache_locks = {}
        self.version = 0

    def attach(self, folder):
        folder = Path(folder).resolve()
        if self.capture and self.capture.active:
            raise ValueError("Finish the recording before opening another project.")
        if self.root == folder:
            return
        stream = (folder / ".studio-session").open("a+b")
        try:
            if os.name == "nt":
                import msvcrt
                stream.seek(0)
                msvcrt.locking(stream.fileno(), msvcrt.LK_NBLCK, 1)
            else:
                import fcntl
                fcntl.flock(stream, fcntl.LOCK_EX | fcntl.LOCK_NB)
            project = json.loads((folder / "episode.json").read_text("utf-8"))
            uuid.UUID(project["episode_id"])
            if project["schema_version"] != 1:
                raise ValueError("Unsupported episode version.")
            for track in project["sources"]:
                media.bounded(folder, track["path"])
        except BaseException:
            stream.close()
            raise
        if self.lockfile:
            self.lockfile.close()
        self.root, self.lockfile, self.project = folder, stream, project
        self.capture = Capture(folder)
        self.history.clear()
        self.redo.clear()
        self.gaps = []
        self.version += 1

    def snapshot(self):
        with self.mutex:
            project = copy.deepcopy(self.project)
            tracks = project["sources"] if project else []
            revisions = []
            if self.root:
                for file in sorted((self.root / "exports").glob("*/studio-package.json"), key=lambda p: p.stat().st_mtime, reverse=True)[:20]:
                    if file.parent.name.startswith("."):
                        continue
                    row = json.loads(file.read_text("utf-8"))
                    revisions.append({k: row.get(k) for k in ("revision","approval","created_at","visibility","title","assets")})
            def urls(endpoint, filtered):
                return {s["id"]: f"/api/{endpoint}/{s['id']}" for s in filtered}
            view = {"project": project, "version": self.version, "busy": self.busy, "status": self.status, "gaps": self.gaps,
                    "recording": bool(self.capture and self.capture.active), "library": str(media.library()), "revisions": revisions,
                    "media_urls": urls("media",tracks), "thumbnail_urls": urls("thumbnail",[s for s in tracks if s.get("video")]),
                    "waveform_urls": urls("waveform",[s for s in tracks if s.get("audio")]), "can_undo": bool(self.history), "can_redo": bool(self.redo)}
            return view

    def save(self, p):
        media.atomic_json(self.root / "episode.json", p)
        self.history.append(self.project)
        self.redo.clear()
        self.project = p
        self.gaps = []
        self.version += 1

    def command(self, args):
        action = args["action"]
        if self.busy:
            raise ValueError("Wait for the current operation to finish.")
        if "version" in args and args["version"] != self.version:
            raise ValueError("The project changed. Refresh before editing.")
        if action in {"new_project","open_project"}:
            if action == "new_project":
                title = str(args.get("title","")).strip()[:160]
                folder = media.library() / "projects" / str(uuid.uuid4())
                media.new_project(folder, title)
            else:
                file = picker(action, self.root or media.library())
                if not file:
                    return
                if Path(file).name != "episode.json":
                    raise ValueError("Choose episode.json.")
                folder = Path(file).parent
            self.attach(folder)
            media.atomic_json(media.library()/'.last-project.json', {'directory': str(folder)})
            self.status = "Project opened."
            return
        if not self.root:
            raise ValueError("Create or open a project first.")
        if action in {"undo","redo"}:
            source, dest = (self.history,self.redo) if action == "undo" else (self.redo,self.history)
            if source:
                previous = source[-1]
                media.atomic_json(self.root / "episode.json", previous)
                dest.append(self.project)
                self.project = source.pop()
                self.gaps = []
                self.version += 1
            self.status = "Edit history updated."
            return
        if action in {"render","transcribe","analyze_gaps","import_media","import_social","record","stop_recording","advanced"}:
            self.busy = True
            self.status = {"render":"Rendering episode…","analyze_gaps":"Listening for gaps…","transcribe":"Transcribing…",
                           "record":"Choose camera and microphone…","stop_recording":"Saving recordings…","advanced":"Advanced editor is open."}.get(action,"Importing media…")
            threading.Thread(target=self.job, args=(action,args), daemon=True).start()
            return
        p = copy.deepcopy(self.project)
        if action == "save_edit":
            for key in ("title","description","visibility"):
                if key in args:
                    p[key] = args[key]
            if not p["title"].strip() or p["visibility"] not in {"public","private","members"}:
                raise ValueError("Choose a title and visibility.")
            fields = {"start","end","camera","include_camera_audio","intro","intro_seconds","outro","outro_seconds","music_licensed","burn_captions","framing","switches"}
            p["edit"].update({k:v for k,v in args.get("edit",{}).items() if k in fields})
        elif action == "save_track":
            track = self.track(p,args["id"])
            for field in ("enabled","muted","label","gain_db","offset"):
                if field in args:
                    track[field] = args[field]
            media.number(track["gain_db"], -60, 24, "gain")
            media.number(track["offset"], -86400, 86400, "offset")
        elif action == "save_sequence":
            import sequence
            candidate = args["segments"]
            if not isinstance(candidate,list) or len(candidate)>2000:
                raise ValueError("Invalid edit sequence.")
            p["sequence"] = candidate
            if candidate:
                p["sequence"] = sequence.segments(p)
        elif action == "apply_gaps":
            import sequence
            allowed = {(r["start"],r["end"]) for r in self.gaps}
            selected = sorted({(r["start"],r["end"]) for r in args["ranges"]})
            if not set(selected) <= allowed:
                raise ValueError("Analyze the current cut again.")
            p["sequence"] = cut_ranges(sequence.segments(p), selected)
        elif action in {"save_clip","delete_clip"}:
            index = args.get("index")
            if index is not None and (type(index) is not int or not 0 <= index < len(p["clips"])):
                raise ValueError("Select an existing cut.")
            if action == "delete_clip":
                p["clips"].pop(index)
            else:
                clip = args["clip"]
                if clip.get("kind") not in {"short","trailer"} or clip.get("framing") not in media.FRAMES or float(clip["end"]) <= float(clip["start"]):
                    raise ValueError("Choose a valid cut and framing.")
                if index is None:
                    p["clips"].append(clip)
                else:
                    p["clips"][index] = clip
        elif action == "save_captions":
            cues = args["captions"]
            for cue in cues:
                start = media.number(cue["start"],0,86400,"caption start")
                media.number(cue["end"],start+.001,86400,"caption end")
                if not str(cue["text"]).strip():
                    raise ValueError("Add caption text.")
            if args.get("source_id"):
                self.track(p,args["source_id"])["source_captions"] = cues
            else:
                p["captions"] = cues
        elif action == "suggest_clips":
            for suggestion in media.suggestions(p):
                if not any(c["start"]==suggestion["start"] and c["end"]==suggestion["end"] for c in p["clips"]):
                    p["clips"].append(suggestion)
        elif action in {"import_rundown","catalog_tags","import_captions"}:
            selected = picker(action,self.root)
            if not selected:
                return
            content = Path(selected).read_text("utf-8-sig")
            if action == "import_rundown":
                p["sponsor_rundown"] = parse_rundown(p,json.loads(content))
            elif action == "catalog_tags":
                p["product_tags"].extend(media.suggest_tags(p,json.loads(content)))
            else:
                p["captions"] = media.parse_captions(content)
        elif action in {"approve","review","open_revision","publish_frh"}:
            folder = media.bounded(self.root/"exports",str(uuid.UUID(args["revision"])))
            if action == "approve":
                media.approve(self.root,p,args["revision"])
            elif action == 'publish_frh':
                if args.get('confirmed') is not True:
                    raise ValueError('Confirm publication of this revision.')
                self.busy = True
                self.status = 'Publishing the approved public episode…'
                threading.Thread(target=self.job,args=(action,args),daemon=True).start()
                return
            else:
                os.startfile(str(folder/"episode.mp4" if action=="review" else folder))
            self.status = "Revision approved." if action=="approve" else "Opened revision."
            return
        else:
            raise ValueError("Unknown editor action.")
        self.save(p)
        self.status = "Saved."

    @staticmethod
    def track(project, key):
        return next((s for s in project["sources"] if s["id"] == key), None) or missing_track()

    def job(self, action, args):
        try:
            p = copy.deepcopy(self.project)
            status = "Ready."
            if action == "analyze_gaps":
                from sequence import analyze_gaps
                gaps = analyze_gaps(self.root,p,**{k: args[k] for k in ("threshold_db","min_silence","padding") if k in args})
                with self.mutex:
                    self.gaps = gaps
                status = f"{len(gaps)} suggested gaps."
            elif action == "publish_frh":
                import frh_publish
                result = frh_publish.publish_revision(self.root,args["revision"],confirmed=True,previous_revision=args.get("previous_revision"))
                status = "Published to " + result["episode_url"]
            elif action == "render":
                media.render(self.root,p)
                status = "Render ready for review."
            elif action == "transcribe":
                base = media.library()/"captions"
                cli = next(base.rglob("whisper-cli.exe"), None)
                if not cli:
                    raise ValueError("Install offline captions with setup-captions.ps1.")
                p["captions"] = media.transcribe(self.root,p,base/"ggml-base.en.bin",cli)
                with self.mutex:
                    self.save(p)
                status = "Transcript ready."
            elif action in {"import_media","stop_recording","import_social"}:
                if action == "import_social":
                    picker(action,self.root)
                    p = json.loads((self.root/"episode.json").read_text("utf-8"))
                else:
                    if action == "import_media":
                        kind = args.get("kind","camera")
                        if kind not in media.KINDS: raise ValueError("Choose a media track type.")
                        files = [(kind,Path(f),0) for f in picker(kind,self.root)]
                    else:
                        files = self.capture.stop()
                    for kind,file,offset in files:
                        media.import_source(self.root,p,file,kind,offset)
                if p != self.project:
                    with self.mutex:
                        self.save(p)
                    status = "Media imported."
            elif action == "record":
                if self.capture.active: raise ValueError("Recording is already in progress.")
                selection = picker(action,self.root)
                if selection:
                    self.capture.start(*selection)
                    status = "Recording."
            elif action == "advanced":
                if self.capture.active: raise ValueError("Stop recording first.")
                self.lockfile.close()
                try:
                    subprocess.run([sys.executable,"-B",str(HERE/"studio.py"),"--project",str(self.root)], check=True)
                finally:
                    previous = self.root
                    self.root = None
                    self.attach(previous)
            self.status = status
        except Exception as error:
            self.status = "Could not finish: " + str(error)
        finally:
            self.busy = False


def missing_track():
    raise ValueError("Choose an available track.")


def visualization(folder, track, mode):
    src = media.bounded(folder,track["path"])
    cache = folder / "cache" / "editor"
    cache.mkdir(parents=True, exist_ok=True)
    dest = cache / (track["id"]+(".jpg" if mode=="thumbnail" else ".json"))
    if dest.exists():
        return dest
    if mode=="thumbnail":
        handle = tempfile.NamedTemporaryFile(dir=cache,suffix=".jpg",delete=False)
        tmp = Path(handle.name)
        handle.close()
        try:
            media.run(["-y","-ss",str(min(1,track["duration"]/3)),"-i",str(src),"-frames:v","1","-vf","scale=384:-2",str(tmp)])
            os.replace(tmp,dest)
        finally:
            tmp.unlink(missing_ok=True)
    else:
        peaks = [0.0]*480
        rate = 1200
        span = max(1, math.ceil(track["duration"] * rate / len(peaks)))
        with subprocess.Popen([media.ffmpeg(),"-v","error","-nostdin","-i",str(src),"-vn","-ac","1","-ar",str(rate),"-f","s16le","pipe:1"],stdout=subprocess.PIPE,stderr=subprocess.DEVNULL) as proc:
            for index in range(len(peaks)):
                raw = proc.stdout.read(span*2)
                if not raw: break
                samples = array.array("h",raw)
                peaks[index] = round(max(map(abs,samples),default=0)/32768,4)
            while proc.stdout.read(65536): pass
            if proc.wait(): raise ValueError("Could not read the audio waveform.")
        media.atomic_json(dest,{"peaks":peaks})
    return dest


class EditorWindowServer(ThreadingHTTPServer):
    """Release the project after the last editor window closes and outstanding jobs finish."""

    def __init__(self, editor):
        super().__init__(("127.0.0.1", 0), Handler)
        self.address = f"127.0.0.1:{self.server_port}"
        self.editor = editor
        self.key = secrets.token_urlsafe(40)
        self.session_id = secrets.token_urlsafe(40)
        self.cookie_name = f"studio_session_{self.server_port}"
        self.window_lock = threading.Lock()
        self.window_count = 0
        self.quit_after = time.monotonic() + 120
        self.stopping = threading.Event()

    def watch_windows(self):
        while not self.stopping.wait(0.5):
            with self.window_lock:
                if self.window_count or time.monotonic() < self.quit_after:
                    continue
            with self.editor.mutex:
                if self.editor.busy:
                    continue
                if self.editor.capture and self.editor.capture.active:
                    self.editor.command({"action": "stop_recording"})
                    continue
            self.shutdown()
            return

    def server_close(self):
        self.stopping.set()
        super().server_close()


class Handler(BaseHTTPRequestHandler):
    protocol_version="HTTP/1.1"

    def log_message(self, *args):
        pass  # Launch URLs contain a one-time credential.

    def reply(self, code, body=b"", content="application/json", extras=None):
        if code >= 400:
            self.close_connection = True
        self.send_response(code)
        if self.close_connection:
            self.send_header('Connection', 'close')
        self.send_header("Content-Type",content)
        self.send_header("Content-Length",str(len(body)))
        self.send_header("Cache-Control","no-store")
        self.send_header("Referrer-Policy","no-referrer")
        self.send_header("X-Content-Type-Options","nosniff")
        self.send_header("Content-Security-Policy","default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; media-src 'self' blob:; object-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'")
        for key,value in (extras or {}).items():
            self.send_header(key,value)
        self.end_headers()
        if body and self.command != "HEAD":
            self.wfile.write(body)

    def json_reply(self, code, data):
        self.reply(code,json.dumps(data,ensure_ascii=True,allow_nan=False).encode())

    def allowed(self):
        if self.headers.get("Host") != self.server.address:
            self.json_reply(403,{"error":"Local Studio only."})
            return False
        cookie = SimpleCookie(self.headers.get("Cookie",""))
        entry = cookie.get(self.server.cookie_name)
        if entry and hmac.compare_digest(entry.value, self.server.session_id):
            return True
        self.json_reply(403,{"error":"Open Studio using its launcher."})
        return False

    def do_GET(self):
        parts = urlsplit(self.path)
        if parts.path == "/launch" and self.headers.get("Host")==self.server.address:
            token = parse_qs(parts.query).get("key",[""])[0]
            with self.server.editor.mutex:
                if token and self.server.key and hmac.compare_digest(token,self.server.key):
                    self.server.key = None
                    self.reply(303,extras={"Location":"/","Set-Cookie":self.server.cookie_name+"="+self.server.session_id+"; Path=/; HttpOnly; SameSite=Strict"})
                    return
        if not self.allowed(): return
        try:
            if parts.path == "/api/window" and self.command == "GET":
                self.window_events()
            elif parts.path == "/api/state":
                self.json_reply(200,self.server.editor.snapshot())
            elif match := re.fullmatch(r"/api/(media|thumbnail|waveform)/([a-fA-F0-9-]{16,40})",parts.path):
                mode,key = match.groups()
                editor = self.server.editor
                with editor.mutex:
                    source = copy.deepcopy(editor.track(editor.project,key))
                    root = editor.root
                    lock = editor.cache_locks.setdefault((root,key),threading.Lock())
                if mode=="media":
                    with lock:
                        file = preview.playable(root, source)
                else:
                    with lock:
                        file = visualization(root,source,mode)
                self.serve_file(file)
            else:
                name = parts.path.removeprefix("/") or "index.html"
                file = (UI/name).resolve()
                if not file.is_relative_to(UI.resolve()) or file.suffix not in {".html",".js",".css",".svg",".woff2",".png",".jpg",".webp",".ico"} or not file.is_file():
                    self.json_reply(404,{"error":"Not found."})
                    return
                self.reply(200,file.read_bytes(),mimetypes.guess_type(str(file))[0] or "application/octet-stream")
        except (BrokenPipeError,ConnectionResetError,ConnectionAbortedError):
            return
        except (ValueError,TypeError,KeyError,OSError) as err:
            self.json_reply(422,{"error":str(err)})

    def window_events(self):
        # The browser drops this connection on close, reload, or crash. Reloads get a grace period.
        self.send_response(200)
        self.send_header("Content-Type", "text/event-stream")
        self.send_header("Cache-Control", "no-store")
        self.send_header("Connection", "close")
        self.end_headers()
        self.close_connection = True
        with self.server.window_lock:
            self.server.window_count += 1
        try:
            while not self.server.stopping.is_set():
                self.wfile.write(b": window open\n\n")
                self.wfile.flush()
                self.server.stopping.wait(1)
        finally:
            with self.server.window_lock:
                self.server.window_count -= 1
                self.server.quit_after = time.monotonic() + 5

    def serve_file(self, file):
        size = file.stat().st_size
        start, end = 0, size-1
        byterange = self.headers.get("Range")
        if byterange:
            m = re.fullmatch(r"bytes=(\d*)-(\d*)",byterange)
            if not m or not any(m.groups()):
                self.reply(416,extras={"Content-Range":"bytes */"+str(size)})
                return
            if m[1]:
                start = int(m[1])
                end = min(end,int(m[2])) if m[2] else end
            else:
                start = max(0,size-int(m[2]))
            if start> end or start >= size:
                self.reply(416,extras={"Content-Range":"bytes */"+str(size)})
                return
        self.send_response(206 if byterange else 200)
        self.send_header("Content-Type",mimetypes.guess_type(file.name)[0] or "application/octet-stream")
        self.send_header("Accept-Ranges","bytes")
        self.send_header("Cache-Control","private, max-age=3600")
        if byterange: self.send_header("Content-Range",f"bytes {start}-{end}/{size}")
        n = end-start+1
        self.send_header("Content-Length",str(n))
        self.end_headers()
        if self.command=="HEAD": return
        with file.open("rb") as stream:
            stream.seek(start)
            while n:
                part = stream.read(min(n,256*1024))
                if not part: break
                self.wfile.write(part)
                n -= len(part)

    do_HEAD=do_GET

    def do_POST(self):
        if not self.allowed(): return
        if self.path!="/api/command" or self.headers.get("X-Studio-Request")!="1" or self.headers.get("Origin")!="http://"+self.server.address:
            self.json_reply(403,{"error":"Use the local editor window."})
            return
        try:
            size = int(self.headers.get("Content-Length","0"))
            if not 0<size<=2*1024*1024 or self.headers.get_content_type()!="application/json":
                self.json_reply(413,{"error":"Unsupported request."})
                return
            payload = json.loads(self.rfile.read(size))
            with self.server.editor.mutex:
                self.server.editor.command(payload)
                self.json_reply(200,self.server.editor.snapshot())
        except (ValueError,TypeError,KeyError,IndexError,OSError) as err:
            self.json_reply(422,{"error":str(err)})


def make_server(editor=None):
    return EditorWindowServer(editor or EditingSession())


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument("--project",type=Path)
    parser.add_argument("--check-launch",action="store_true")
    args=parser.parse_args()
    server=make_server()
    if args.project:
        server.editor.attach(args.project)
    elif not args.check_launch:
        try:
            last = json.loads((media.library()/'.last-project.json').read_text('utf-8'))
            # A window that just closed may still be flushing its recording or closing its stream.
            for attempt in range(9):
                try:
                    server.editor.attach(last['directory'])
                    break
                except PermissionError:
                    if attempt == 8:
                        server.editor.status = "This episode is open in another Studio window. Switch to that window or open another episode."
                        break
                    time.sleep(1)
        except FileNotFoundError:
            pass
        except (OSError, ValueError, KeyError) as error:
            server.editor.status = f"Could not reopen the last episode: {error}"
    if args.check_launch:
        if not (UI/"index.html").is_file(): raise RuntimeError("Editor UI missing")
        server.server_close()
        print("Everbranch Studio timeline editor ready on " + str(media.library()))
        return
    url=f"http://{server.address}/launch?key={server.key}"
    edge=next((p for key in ("ProgramFiles(x86)","ProgramFiles","LOCALAPPDATA") if (base:=os.environ.get(key)) and (p:=Path(base)/"Microsoft/Edge/Application/msedge.exe").is_file()),None)
    if edge:
        profile=media.library()/"window-profile"
        subprocess.Popen([str(edge),"--app="+url,"--user-data-dir="+str(profile),"--no-first-run","--disable-features=msEdgeFirstRunExperience","--window-size=1500,940"])
    else:
        webbrowser.open(url)
    threading.Thread(target=server.watch_windows, daemon=True).start()
    try:
        server.serve_forever()
    finally:
        if server.editor.capture and server.editor.capture.active:
            server.editor.capture.stop()
        if server.editor.lockfile: server.editor.lockfile.close()
        server.server_close()


if __name__=="__main__":
    main()
