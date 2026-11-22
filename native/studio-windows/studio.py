"""Local Windows episode editor. Run with python studio.py."""
import copy
import json
import os
import queue
import subprocess
import threading
import tkinter as tk
from datetime import datetime
from pathlib import Path
from tkinter import filedialog, messagebox, simpledialog, ttk
from uuid import UUID

import media
from capture import Capture, devices
from rundown import parse_rundown
from social_picker import SocialPicker


def form(parent,title,fields):
    """A modal, labeled form. A third tuple item supplies a choice list."""
    dialog=tk.Toplevel(parent)
    dialog.title(title)
    dialog.transient(parent)
    dialog.resizable(True,False)
    pane=ttk.Frame(dialog,padding=20)
    pane.pack(fill="both",expand=True)
    inputs={}
    for i,field in enumerate(fields):
        label,value,*choices=field
        ttk.Label(pane,text=label).grid(row=i,column=0,sticky="nw",pady=5,padx=(0,15))
        if choices:
            widget=ttk.Combobox(pane,values=choices[0],state="readonly",width=44)
            widget.set(value)
        elif label in {"Description","Caption","Sponsor brief"}:
            widget=tk.Text(pane,height=4,width=45,wrap="word")
            widget.insert("1.0",str(value))
        else:
            widget=ttk.Entry(pane,width=48)
            widget.insert(0,str(value))
        widget.grid(row=i,column=1,sticky="ew",pady=4)
        inputs[label]=widget
    pane.columnconfigure(1,weight=1)
    values={}
    def submit():
        for key,widget in inputs.items():
            values[key]=(widget.get("1.0","end-1c") if isinstance(widget,tk.Text) else widget.get()).strip()
        dialog.destroy()
    buttons=ttk.Frame(pane)
    buttons.grid(row=len(fields),column=0,columnspan=2,sticky="e",pady=(12,0))
    ttk.Button(buttons,text="Cancel",command=dialog.destroy).pack(side="left",padx=5)
    ttk.Button(buttons,text="Save",command=submit).pack(side="left")
    dialog.grab_set()
    next(iter(inputs.values())).focus_set()
    parent.wait_window(dialog)
    return values


class Studio:
    def __init__(self,app):
        self.app=app
        self.root=None
        self.p=None
        self.lock=None
        self.capture=None
        self.undo=[]
        self.busy=False
        self.jobs=queue.Queue()
        self.latest=None
        app.title("Everbranch Studio")
        app.geometry("1020x690")
        app.minsize(780,530)
        self.header=ttk.Frame(app,padding=(18,16,18,6))
        self.header.pack(fill="x")
        self.heading=ttk.Label(self.header,text="Everbranch Studio",font=("Segoe UI",19,"bold"))
        self.heading.pack(side="left")
        for text,fn in [("Open episode",self.open),("New episode",self.new),("Import social clip",self.social)]:
            ttk.Button(self.header,text=text,command=lambda f=fn:self.guarded(f,project=False)).pack(side="right",padx=4)
        self.nav=ttk.Notebook(app)
        self.nav.pack(fill="both",expand=True,padx=18,pady=12)
        self.tables={}
        self.tabs={}
        self.add_tab("Recordings",[("Track","kind",85),("Recording","label",240),("Sync (s)","offset",80),
                                  ("Gain (dB)","gain_db",75),("Length (s)","duration",90),("Use","enabled",60)],
                     [("Record",self.record),("Stop recording",self.stop),("Import video",lambda:self.import_file("camera")),
                      ("Import voice",lambda:self.import_file("voice")),("Import music",lambda:self.import_file("music")),
                      ("Edit track",self.track),("Play",self.play_track),("Suggest mix",self.mix)])
        self.add_tab("Camera cuts",[("Switch at (s)","at",130),("Camera","label",350)],
                     [("Add switch",lambda:self.angle(True)),("Edit switch",self.angle),("Remove",self.remove_angle)])
        self.add_tab("Transcript",[("Start (s)","start",100),("End (s)","end",100),("Caption","text",600)],
                     [("Transcribe locally",self.transcribe),("Import captions",self.import_captions),
                      ("Add cue",lambda:self.cue(True)),("Edit cue",self.cue),("Cut from cue",self.cut_from_cue),("Remove",lambda:self.remove("captions"))])
        self.add_tab("Cuts",[("Format","kind",80),("In (s)","start",70),("Out (s)","end",70),("Frame","framing",90),("Opening / label","label",400)],
                     [("Suggest cuts",self.suggest),("Add cut",lambda:self.cut(True)),("Edit cut",self.cut),
                      ("Preview cut",self.preview),("Remove",lambda:self.remove("clips"))])
        self.add_tab("Products",[("Variant ID","variant_id",260),("Name","label",240),("In (s)","start",90),("Out (s)","end",90)],
                     [("Suggest tags from catalog",self.catalog),("Add tag",lambda:self.tag(True)),("Edit tag",self.tag),("Remove",lambda:self.remove("product_tags"))])
        self.add_tab("Sponsors",[("Sponsor","sponsor",160),("Deliverable","kind",100),("Promised (s)","seconds",90),
                                 ("Placement (s)","placement",90),("Brief","brief",350)],
                     [("Import rundown",self.rundown),("Place deliverable",self.place),("Make social cut",self.sponsor_cut)])
        delivery=ttk.Frame(self.nav,padding=14)
        self.nav.add(delivery,text="Delivery")
        self.delivery=tk.StringVar(value="Open or create an episode to start.")
        ttk.Label(delivery,textvariable=self.delivery,wraplength=740,justify="left").pack(anchor="w",pady=8)
        for label,fn in [("Episode settings & master trim",self.settings),("Render revision",self.render),
                         ("Review episode video",self.review),("Open revision folder",self.revision_folder),
                         ("Approve reviewed revision",self.approve)]:
            ttk.Button(delivery,text=label,command=lambda f=fn:self.guarded(f)).pack(anchor="w",pady=4)
        footer=ttk.Frame(app,padding=(18,0,18,12))
        footer.pack(fill="x")
        self.status=tk.StringVar(value="Create an episode or open an existing project folder.")
        ttk.Label(footer,textvariable=self.status,wraplength=740).pack(side="left")
        ttk.Button(footer,text="Undo edit",command=lambda:self.guarded(self.revert)).pack(side="right")
        app.protocol("WM_DELETE_WINDOW",self.close)
        app.after(100,self.poll)

    def guarded(self,fn,project=True):
        if self.busy or (self.capture and self.capture.active and fn!=self.stop):
            self.status.set("Finish the current operation first.")
            return
        if project and self.p is None:
            self.status.set("Open or create an episode first.")
            return
        try:
            fn()
        except Exception as e:
            messagebox.showerror("Everbranch Studio",str(e),parent=self.app)

    def add_tab(self,name,cols,actions):
        tab=ttk.Frame(self.nav,padding=10)
        self.nav.add(tab,text=name)
        bar=ttk.Frame(tab)
        bar.pack(fill="x",pady=(0,8))
        for idx,(label,fn) in enumerate(actions):
            ttk.Button(bar,text=label,command=lambda f=fn:self.guarded(f)).grid(row=idx//5,column=idx%5,padx=(0,4),pady=2,sticky="ew")
        table=ttk.Treeview(tab,columns=[key for _,key,_ in cols],show="headings",selectmode="browse")
        for title,key,width in cols:
            table.heading(key,text=title)
            table.column(key,width=width,minwidth=40,stretch=True)
        scroll=ttk.Scrollbar(tab,orient="vertical",command=table.yview)
        table.configure(yscrollcommand=scroll.set)
        scroll.pack(side="right",fill="y")
        table.pack(fill="both",expand=True)
        self.tables[name]=table

    def save(self,proc):
        previous=copy.deepcopy(self.p)
        try:
            proc()
            media.atomic_json(self.root/"episode.json",self.p)
        except BaseException:
            self.p=previous
            raise
        self.undo=(self.undo+[previous])[-20:]
        self.refresh()

    def refresh(self):
        self.heading.config(text=self.p["title"])
        for name,key in [("Recordings","sources"),("Transcript","captions"),("Cuts","clips"),("Products","product_tags"),("Sponsors","sponsor_rundown")]:
            tree=self.tables[name]
            tree.delete(*tree.get_children())
            for i,item in enumerate(self.p.get(key,[])):
                tree.insert("","end",iid=str(i),values=[item.get(k,"") if item.get(k) is not None else "" for k in tree["columns"]])
        tree=self.tables["Camera cuts"]
        tree.delete(*tree.get_children())
        cameras={s["id"]:s["label"] for s in self.p["sources"]}
        for i,change in enumerate(self.p["edit"].get("switches",[])):
            tree.insert("","end",iid=str(i),values=(change["at"],cameras.get(change["camera"],"Missing camera")))
        revisions=list((self.root/"exports").glob("*/studio-package.json"))
        self.latest=max(revisions,key=lambda path:path.parent.stat().st_mtime_ns).parent if revisions else None
        status="No rendered revision yet."
        if self.latest:
            manifest=json.loads((self.latest/"studio-package.json").read_text(encoding="utf-8"))
            status=f"Latest revision {self.latest.name}\n{manifest['approval'].capitalize()} • {len(manifest['assets'])} files"
            if manifest["edit_fingerprint"]!=media.fingerprint(self.p):
                status+=" • edits since render"
        self.delivery.set(f"Episode ID  {self.p['episode_id']}\nVisibility  {self.p['visibility']}\n\n{status}")

    def attach(self,path,p):
        import msvcrt
        path=Path(path).resolve()
        if self.root==path:
            return
        lock=(path/".studio-session").open("a+b")
        lock.seek(0)
        try:
            msvcrt.locking(lock.fileno(),msvcrt.LK_NBLCK,1)
        except OSError:
            lock.close()
            raise ValueError("Another Studio window is editing this episode.") from None
        if self.lock:
            self.lock.close()
        self.root,self.p,self.lock=path,p,lock
        self.capture=Capture(path)
        self.undo=[]
        self.refresh()
        self.status.set(str(path))

    def new(self):
        info=form(self.app,"Create episode",[("Title",""),("Existing web episode UUID (optional)","")])
        if not info:
            return
        episode_id=str(UUID(info["Existing web episode UUID (optional)"])) if info["Existing web episode UUID (optional)"] else None
        projects=media.library() / "projects"
        projects.mkdir(parents=True,exist_ok=True)
        directory=filedialog.askdirectory(initialdir=str(projects),title="Choose the parent folder for this episode")
        if directory and info["Title"]:
            folder=Path(directory)/(datetime.now().strftime("%Y%m%d-%H%M%S")+"-"+(episode_id or __import__("uuid").uuid4().hex)[:8])
            p=media.new_project(folder,info["Title"])
            if episode_id:
                p["episode_id"]=episode_id
                media.atomic_json(folder/"episode.json",p)
            self.attach(folder,p)

    def open(self):
        file=filedialog.askopenfilename(title="Open episode.json",filetypes=[("Episode","episode.json")])
        if file:
            path=Path(file)
            p=json.loads(path.read_text(encoding="utf-8-sig"))
            if p.get("schema_version")!=1:
                raise ValueError("Unsupported project format.")
            self.attach(path.parent,p)

    def revert(self):
        if self.undo:
            self.p=self.undo.pop()
            media.atomic_json(self.root/"episode.json",self.p)
            self.refresh()
            self.status.set("Previous edit restored.")

    def selected(self,tab):
        selection=self.tables[tab].selection()
        if not selection:
            raise ValueError("Select a row first.")
        return int(selection[0])

    def remove(self,key):
        name={"captions":"Transcript","clips":"Cuts","product_tags":"Products"}[key]
        i=self.selected(name)
        self.save(lambda:self.p[key].pop(i))

    def social(self):
        if self.p is None:
            self.new()
        if self.p is not None:
            SocialPicker(self)

    def import_file(self,kind):
        extensions=media.VIDEO if kind=="camera" else media.AUDIO
        files=filedialog.askopenfilenames(title=f"Import {kind} recordings",filetypes=[("Recordings"," ".join("*"+s for s in sorted(extensions)))])
        if files:
            self.background("Copying original recordings…",lambda:self.imported([(kind,Path(f),0) for f in files]),self.accept_import)

    def imported(self,items):
        p=copy.deepcopy(self.p)
        for kind,path,off in items:
            if kind=="camera" and not p["edit"]["camera"]:
                off=0
            media.import_source(self.root,p,path,kind,off)
        return p

    def accept_import(self,p):
        self.save(lambda:self.p.update(p))
        self.status.set("Recordings imported. Adjust sync offsets and voice levels under Edit track.")

    def track(self):
        s=self.p["sources"][self.selected("Recordings")]
        f=form(self.app,"Track settings",[("Name",s["label"]),("Timeline offset (s)",s["offset"]),("Gain (dB)",s["gain_db"]),("Enabled","Yes" if s["enabled"] else "No",["Yes","No"])])
        if f:
            self.save(lambda:s.update(label=f["Name"],offset=media.number(f["Timeline offset (s)"],-86400,86400,"offset"),
                                      gain_db=media.number(f["Gain (dB)"],-60,24,"gain"),enabled=f["Enabled"]=="Yes"))

    def mix(self):
        tracks=[s for s in self.p["sources"] if s["enabled"] and s["audio"]]
        if not tracks:
            raise ValueError("Import audio before setting levels.")
        # Suggest editable starting levels for source types; the master is loudness-normalized.
        f=form(self.app,"Suggested mix",[("Voice gain (dB)",0),("Music bed below voice (dB)",23),
                ("Include primary camera microphone","No" if any(s["kind"]=="voice" for s in tracks) else "Yes",["Yes","No"])])
        if f:
            def update():
                voice=media.number(f["Voice gain (dB)"],-60,24,"voice gain")
                bed=media.number(f["Music bed below voice (dB)"],0,60,"music bed")
                for s in tracks:
                    if s["kind"]=="voice":
                        s["gain_db"]=voice
                    elif s["kind"]=="music":
                        s["gain_db"]=23-bed
                self.p["edit"]["include_camera_audio"]=f["Include primary camera microphone"]=="Yes"
            self.save(update)

    def play_track(self):
        s=self.p["sources"][self.selected("Recordings")]
        os.startfile(media.bounded(self.root,s["path"]))

    def record(self):
        d=devices()
        if not d["video"]:
            raise ValueError("No Windows video device detected. Connect a webcam or capture card, or import a recording.")
        empty="(none)"
        f=form(self.app,"Record local devices",[("Camera",d["video"][0],d["video"]),("Second camera",empty,[empty,*d["video"]]),
                                                ("Microphone",d["audio"][0] if d["audio"] else empty,[empty,*d["audio"]])])
        if f:
            cameras=[cam for cam in (f["Camera"],f["Second camera"]) if cam!=empty]
            self.capture.start(cameras,f["Microphone"] if f["Microphone"]!=empty else None)
            self.status.set("Recording selected devices • Press Stop recording to save.")

    def stop(self):
        if self.capture.active:
            tracks=self.capture.stop()
            if tracks:
                self.background("Saving captured tracks…",lambda:self.imported(tracks),self.accept_import)
            else:
                self.status.set("Capture stopped. See the session logs in recordings.")

    def import_captions(self):
        file=filedialog.askopenfilename(filetypes=[("Timed captions","*.vtt *.srt")])
        if file:
            cues=media.parse_captions(Path(file).read_text(encoding="utf-8-sig"))
            if not cues:
                raise ValueError("No timestamped cues found.")
            if not self.p["captions"] or messagebox.askyesno("Replace captions?","Replace this episode’s editable transcript?"):
                self.save(lambda:self.p.update(captions=cues))

    def transcribe(self):
        if self.p["captions"] and not messagebox.askyesno("Replace captions?","Transcribe the voice mix and replace the current cues?"):
            return
        install=media.library()/"captions"
        cli=next(install.rglob("whisper-cli.exe"),None) if install.exists() else None
        model=install/"ggml-base.en.bin"
        if not cli or not model.exists():
            raise ValueError("Run setup-captions.ps1 in the Studio folder to install offline English transcription.")
        self.background("Transcribing the local voice mix…",lambda:media.transcribe(self.root,self.p,model,cli),
                        lambda cues:self.save(lambda:self.p.update(captions=cues)))

    def cue(self,new=False):
        cues=self.p["captions"]
        prev={} if new else cues[self.selected("Transcript")]
        last=cues[-1]["end"] if cues else 0
        f=form(self.app,"Caption",[("Start (s)",prev.get("start",last)),("End (s)",prev.get("end",last+3)),("Caption",prev.get("text",""))])
        if f:
            a=media.number(f["Start (s)"],0,86400,"start")
            b=media.number(f["End (s)"],a+0.001,86400,"end")
            if not f["Caption"]:
                raise ValueError("Enter a caption.")
            def update():
                prev.update(start=a,end=b,text=f["Caption"])
                if new:
                    cues.append(prev)
                cues.sort(key=lambda c:c["start"])
            self.save(update)

    def cut_from_cue(self):
        cue=self.p["captions"][self.selected("Transcript")]
        self.cut(True,{"label":cue["text"][:90],"start":cue["start"],"end":cue["end"]})

    def cameras(self):
        names={f"{s['label']} · {s['id'][:6]}":s["id"] for s in self.p["sources"] if s["kind"]=="camera" and s["enabled"]}
        if not names:
            raise ValueError("Import or record a camera first.")
        return names

    def angle(self,new=False):
        changes=self.p["edit"].get("switches",[])
        cameras=self.cameras()
        prev={} if new else changes[self.selected("Camera cuts")]
        selected=next((k for k,v in cameras.items() if v==prev.get("camera")),next(iter(cameras)))
        f=form(self.app,"Switch camera on the master timeline",[("Switch at (s)",prev.get("at",self.p["edit"]["start"])),
                                                              ("Camera",selected,list(cameras))])
        if f:
            def update():
                at=media.number(f["Switch at (s)"],self.p["edit"]["start"],self.p["edit"]["end"]-.01,"switch time")
                if any(other is not prev and other["at"]==at for other in changes):
                    raise ValueError("A camera switch already exists at this time.")
                prev.update(at=at,camera=cameras[f["Camera"]])
                if new:
                    changes.append(prev)
                self.p["edit"]["switches"]=sorted(changes,key=lambda c:c["at"])
            self.save(update)

    def remove_angle(self):
        idx=self.selected("Camera cuts")
        self.save(lambda:self.p["edit"]["switches"].pop(idx))

    def settings(self):
        edit=self.p["edit"]
        cameras=self.cameras()
        selected=next((k for k,v in cameras.items() if v==edit["camera"]),next(iter(cameras)))
        f=form(self.app,"Episode settings",[("Title",self.p["title"]),("Description",self.p["description"]),("Visibility",self.p["visibility"],["private","members","public"]),
            ("Master camera",selected,list(cameras)),("Use master camera microphone","Yes" if edit["include_camera_audio"] else "No",["Yes","No"]),
            ("Master in (s)",edit["start"]),("Master out (s)",edit["end"]),("Opening title (optional)",edit["intro"]),("Opening duration (s)",edit["intro_seconds"]),
            ("Music cleared for publication","Yes" if edit["music_licensed"] else "No",["Yes","No"])])
        if f:
            def update():
                a=media.number(f["Master in (s)"],0,86400,"master start")
                b=media.number(f["Master out (s)"],a+0.01,86400,"master end")
                if not f["Title"]:
                    raise ValueError("Enter an episode title.")
                self.p.update(title=f["Title"],description=f["Description"],visibility=f["Visibility"])
                edit.update(camera=cameras[f["Master camera"]],include_camera_audio=f["Use master camera microphone"]=="Yes",start=a,end=b,
                            intro=f["Opening title (optional)"],intro_seconds=media.number(f["Opening duration (s)"],0.25,30,"opening duration"),
                            music_licensed=f["Music cleared for publication"]=="Yes")
            self.save(update)

    def suggest(self):
        suggested=media.suggestions(self.p)
        if suggested:
            if self.p["clips"] and not messagebox.askyesno("Add suggestions?",f"Append {len(suggested)} suggestions to your existing cuts?"):
                return
            self.save(lambda:self.p["clips"].extend(suggested))
            self.status.set(f"Added {len(suggested)} editable cuts using complete caption cues.")

    def cut(self,new=False,seed=None):
        cuts=self.p["clips"]
        prev=(seed or {}) if new else cuts[self.selected("Cuts")]
        cameras=self.cameras()
        selected=next((k for k,v in cameras.items() if v==prev.get("camera",self.p["edit"]["camera"])),next(iter(cameras)))
        a=self.p["edit"]["start"]
        b=self.p["edit"]["end"]
        if prev.get("timeline")=="source":
            source=next(s for s in self.p["sources"] if s["id"]==prev["camera"])
            a,b=0,source["duration"]
            cameras={selected:source["id"]}
        f=form(self.app,"Edit cut",[("Label",prev.get("label","")),("Format",prev.get("kind","short"),["short","trailer"]),
            ("Start (s)",prev.get("start",a)),("End (s)",prev.get("end",min(b,a+30))),("Camera",selected,list(cameras)),
            ("Framing",prev.get("framing","center"),["wide","left","center","right","fit"]),
            ("Burn captions","Yes" if prev.get("burn_captions",True) else "No",["Yes","No"])])
        if f:
            def update():
                start=media.number(f["Start (s)"],a,b,"start")
                end=media.number(f["End (s)"],start+0.01,b,"end")
                prev.update(label=f["Label"],kind=f["Format"],start=start,end=end,camera=cameras[f["Camera"]],
                            framing=f["Framing"],burn_captions=f["Burn captions"]=="Yes")
                if new:
                    cuts.append(prev)
            self.save(update)

    def preview(self):
        cut=self.p["clips"][self.selected("Cuts")]
        def create():
            import tempfile
            folder=self.root/"previews"
            folder.mkdir(exist_ok=True)
            path=Path(tempfile.mkdtemp(dir=folder))
            media.verify(self.p)
            media.render_cut(self.root,self.p,cut,path,"preview",(640,360),24)
            return path/"preview.mp4"
        self.background("Rendering clip preview…",create,os.startfile)

    def catalog(self):
        if not self.p["captions"]:
            raise ValueError("Import or transcribe captions first.")
        filename=filedialog.askopenfilename(title="Select the maker's catalog JSON",filetypes=[("Catalog","*.json")])
        if not filename:
            return
        suggestions=media.suggest_tags(self.p,json.loads(Path(filename).read_text(encoding="utf-8-sig")))
        if not suggestions:
            self.status.set("No new unambiguous variant names matched the transcript.")
            return
        if messagebox.askyesno("Add suggested tags?",f"Add {len(suggestions)} timed variant suggestions for review in Products?"):
            self.save(lambda:self.p["product_tags"].extend(suggestions))
            self.status.set("Product suggestions added. Review each variant and its start and end times.")

    def tag(self,new=False):
        tags=self.p["product_tags"]
        prev={} if new else tags[self.selected("Products")]
        a=self.p["edit"]["start"]
        b=self.p["edit"]["end"]
        if b is None:
            raise ValueError("Import a camera first.")
        f=form(self.app,"Suggested product tag",[("Variant ID",prev.get("variant_id","")),("Display name",prev.get("label","")),
               ("Start (s)",prev.get("start",a)),("End (s)",prev.get("end",b))])
        if f:
            start=media.number(f["Start (s)"],a,b,"start")
            end=media.number(f["End (s)"],start+0.001,b,"end")
            if not f["Variant ID"]:
                raise ValueError("Enter the variant ID from the authorized maker catalog.")
            def update():
                prev.update(variant_id=f["Variant ID"],label=f["Display name"],start=start,end=end)
                if new:
                    tags.append(prev)
            self.save(update)

    def rundown(self):
        filename=filedialog.askopenfilename(title="Import Everbranch sponsor rundown",filetypes=[("Sponsor rundown","*.json")])
        if filename:
            values=parse_rundown(self.p,json.loads(Path(filename).read_text(encoding="utf-8-sig")))
            self.save(lambda:self.p.update(sponsor_rundown=values))

    def place(self):
        row=self.p["sponsor_rundown"][self.selected("Sponsors")]
        f=form(self.app,row["sponsor"],[("Placement on source timeline (s)",row["placement"] if row["placement"] is not None else self.p["edit"]["start"]),
                                      ("Sponsor brief",row.get("editor_notes",row["brief"]))])
        if f:
            if self.p["edit"]["end"] is None:
                raise ValueError("Import the episode recording first.")
            a=media.number(f["Placement on source timeline (s)"],self.p["edit"]["start"],
                           self.p["edit"]["end"]-(row["seconds"] or 0),"placement")
            self.save(lambda:row.update(placement=a,editor_notes=f["Sponsor brief"]))

    def sponsor_cut(self):
        row=self.p["sponsor_rundown"][self.selected("Sponsors")]
        if row["kind"]!="social_clip" or row["placement"] is None:
            raise ValueError("Place the social-clip deliverable on the timeline first.")
        self.cut(True,{"label":row["sponsor"],"start":row["placement"],
                       "end":min(self.p["edit"]["end"],row["placement"]+(row["seconds"] or 30)),"booking_id":row["booking_id"]})

    def render(self):
        self.background("Rendering episode, audio and social cuts…",lambda:media.render(self.root,self.p),
                        lambda folder:self.status.set(f"Draft rendered in {folder}"))

    def revision_folder(self):
        if not self.latest:
            raise ValueError("Render an episode first.")
        os.startfile(self.latest)

    def review(self):
        if not self.latest:
            raise ValueError("Render an episode first.")
        os.startfile(self.latest/"episode.mp4")

    def approve(self):
        if not self.latest:
            raise ValueError("Render and review a revision first.")
        if messagebox.askyesno("Approve revision?","Have you reviewed the exported episode, captions and social edits for publication?",parent=self.app):
            self.background("Checking revision files…",lambda:media.approve(self.root,self.p,self.latest.name),
                            lambda folder:self.status.set(f"Approved revision available in {folder}"))

    def background(self,label,fn,on_done):
        self.busy=True
        self.status.set(label)
        def worker():
            try:
                self.jobs.put((on_done,fn(),None))
            except Exception as exc:
                self.jobs.put((on_done,None,str(exc)))
        threading.Thread(target=worker,daemon=True).start()

    def poll(self):
        try:
            callback,value,error=self.jobs.get_nowait()
            self.busy=False
            if error is not None:
                messagebox.showerror("Everbranch Studio",error,parent=self.app)
                self.status.set("Operation stopped.")
            else:
                self.status.set("Ready.")
                callback(value)
                self.refresh()
        except queue.Empty:
            pass
        except Exception as e:
            messagebox.showerror("Everbranch Studio",str(e))
        if self.capture and self.capture.active:
            failure=self.capture.errors()
            if failure:
                self.stop()
                messagebox.showerror("Device disconnected",failure,parent=self.app)
        self.app.after(200,self.poll)

    def close(self):
        if self.busy:
            self.status.set("Wait for the current operation to finish before closing.")
        elif self.capture and self.capture.active:
            self.stop()
        else:
            if self.lock:
                self.lock.close()
            self.app.destroy()


if __name__=="__main__":
    window=tk.Tk()
    editor=Studio(window)
    if __import__("sys").argv[1:]==["--check-launch"]:
        window.update()
        assert len(editor.nav.tabs())==7 and window.winfo_ismapped()
        print("Everbranch Studio launched with seven editing panels. Library: "+str(media.library()))
        window.after(100,window.destroy)
    window.mainloop()
