"""Run an isolated loopback session for the editor's browser acceptance test."""
import os
import sys
import tempfile
import threading
from pathlib import Path

import editor_server
import media


def make_fixture(folder, ffmpeg_path):
    """Generate two silent cameras and two distinct local voices with a common pause."""
    os.environ["EVERBRANCH_FFMPEG"] = ffmpeg_path
    os.environ["EVERBRANCH_STUDIO_HOME"] = str(folder / "library")
    project_dir = folder / "episode"
    project = media.new_project(project_dir, "The maker’s workshop")
    here = Path(__file__).resolve().parent
    art = here / "editor-ui" / "studio-welcome.jpg"
    shots = [
        ("Workshop wide", art),
        ("Workbench detail", here.parents[1] / "public/images/public-site/guide-studios.webp"),
    ]
    cameras = []
    for label, poster in shots:
        recording = folder / (label + ".mp4")
        media.run([
            "-loop", "1", "-framerate", "15", "-i", str(poster if poster.exists() else art),
            "-t", "15", "-vf", "scale=960:540:force_original_aspect_ratio=increase,crop=960:540",
            "-c:v", "libx264", "-preset", "ultrafast", "-pix_fmt", "yuv420p", "-movflags",
            "+faststart", str(recording),
        ])
        cameras.append(media.import_source(project_dir, project, recording, "camera"))
    for label, frequency in [("Host microphone", 240), ("Guest microphone", 590)]:
        recording = folder / (label + ".wav")
        envelope = "volume='if(between(t,8.6,13.6),0,0.35+0.6*abs(sin(3*t)))':eval=frame"
        media.run([
            "-f", "lavfi", "-i", f"sine=f={frequency}:d=15", "-af", envelope, "-ar", "48000",
            str(recording),
        ])
        media.import_source(project_dir, project, recording, "voice")
    first, second = cameras
    project.update(description="Private synthetic editing fixture.", visibility="private")
    project["edit"].update(start=0, end=15, include_camera_audio=False)
    project["sequence"] = [
        {"camera": first["id"], "start": 0, "end": 5.2},
        {"camera": second["id"], "start": 5.2, "end": 9.6},
        {"camera": first["id"], "start": 9.6, "end": 15},
    ]
    project["captions"] = [
        {"start": 0, "end": 2.4, "text": "Welcome to the maker’s studio."},
        {"start": 2.4, "end": 5.1, "text": "We are creating a sample episode."},
        {"start": 5.1, "end": 8.6, "text": "Today we will talk about making candles by hand."},
    ]
    project["clips"] = [{
        "kind": "short", "label": "Inside the workshop", "start": 1, "end": 5.1,
        "camera": first["id"], "framing": "center", "burn_captions": True,
    }]
    media.atomic_json(project_dir / "episode.json", project)
    return project_dir


def main():
    ffmpeg_path = media.ffmpeg()
    with tempfile.TemporaryDirectory(prefix="everbranch-ui-", dir=os.environ.get("STUDIO_TEST_TMP")) as temporary:
        folder = Path(temporary)
        empty = "--empty" in sys.argv
        if empty:
            os.environ["EVERBRANCH_STUDIO_HOME"] = str(folder / "library")
            editor_server.picker = lambda action, root: None
        else:
            project_dir = make_fixture(folder, ffmpeg_path)
        with editor_server.make_server() as server:
            if not empty:
                server.editor.attach(project_dir)
            print(f"http://{server.address}/launch?key={server.key}", flush=True)

            def wait_for_runner():
                sys.stdin.readline()
                server.shutdown()

            threading.Thread(target=wait_for_runner, daemon=True).start()
            try:
                server.serve_forever()
            finally:
                if server.editor.lockfile:
                    server.editor.lockfile.close()


if __name__ == "__main__":
    main()
