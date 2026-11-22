"""Native file/device pickers for the local editing window."""
import json
import sys
from pathlib import Path
import tkinter as tk
from tkinter import filedialog
from types import SimpleNamespace

import media
from capture import devices
from social_picker import SocialPicker
from studio import form


def choose(action, root):
    app = tk.Tk()
    app.withdraw()
    app.attributes("-topmost", True)
    try:
        if action == "open_project":
            return filedialog.askopenfilename(parent=app, initialdir=media.library() / "projects", title="Open episode", filetypes=[("Studio episode", "episode.json")])
        if action == "import_social":
            project = json.loads((root / "episode.json").read_text("utf-8"))
            def save(change):
                change()
                media.atomic_json(root / "episode.json", project)
            owner = SimpleNamespace(app=app, p=project, root=root, save=save, busy=False, status=tk.StringVar(app))
            panel = SocialPicker(owner)
            panel.window.transient(None)
            panel.window.deiconify()
            panel.window.lift()
            app.wait_window(panel.window)
            return True
        if action == "record":
            available = devices()
            if not available["video"]:
                raise ValueError("Connect a Windows camera or capture card to record.")
            names = available["audio"]
            app.deiconify()
            app.geometry("1x1")
            result = form(app, "Select recording devices", [("Camera", available["video"][0], available["video"]),
                          ("Second camera", "(none)", ["(none)", *available["video"]]),
                          ("Microphone", names[0] if names else "(none)", ["(none)", *names])])
            if not result:
                return None
            return [[name for name in [result["Camera"], result["Second camera"]] if name != "(none)"],
                    result["Microphone"] if result["Microphone"] != "(none)" else None]
        if action in {"camera", "voice", "music"}:
            extensions = media.VIDEO if action == "camera" else media.AUDIO
            return list(filedialog.askopenfilenames(parent=app, title="Import " + action, filetypes=[("Media files", " ".join("*" + ext for ext in sorted(extensions)))]))
        if action in {"import_rundown", "catalog_tags", "import_captions"}:
            filetypes = [("Captions", "*.vtt *.srt")] if action == "import_captions" else [("JSON", "*.json")]
            return filedialog.askopenfilename(parent=app, filetypes=filetypes)
        raise ValueError("Unknown picker")
    finally:
        app.destroy()


if __name__ == "__main__":
    print(json.dumps(choose(sys.argv[1], Path(sys.argv[2]))))
