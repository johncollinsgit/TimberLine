"""Desktop picker for an individual creator download or selected videos in a data export."""
import os
import queue
import shutil
import tempfile
import threading
import tkinter as tk
import uuid
import webbrowser
from pathlib import Path
from tkinter import filedialog, messagebox, ttk

import media
import social_import as socials


class SocialPicker:
    def __init__(self, editor):
        self.editor=editor
        self.window=tk.Toplevel(editor.app)
        self.window.title("Import social clips")
        self.window.transient(editor.app)
        self.window.geometry("820x580")
        self.window.minsize(650,500)
        self.window.grab_set()
        self.busy=False
        self.items=[]
        self.selection={}
        self.caption_names={}
        self.files=[]
        self.result=queue.Queue()
        frame=ttk.Frame(self.window,padding=16)
        frame.pack(fill="both",expand=True)
        self.platform=tk.StringVar(value="Instagram")
        bar=ttk.Frame(frame)
        bar.pack(fill="x")
        ttk.Label(bar,text="Source").pack(side="left")
        source=ttk.Combobox(bar,values=socials.PLATFORMS,state="readonly",textvariable=self.platform,width=16)
        source.pack(side="left",padx=6)
        source.bind("<<ComboboxSelected>>",lambda _:self.changed())
        self.source=source
        ttk.Button(bar,text="Choose files or ZIPs…",command=self.choose).pack(side="left",padx=4)
        self.help_link=ttk.Button(bar,text="Open creator export",command=self.help)
        self.help_link.pack(side="right")
        self.guide=tk.StringVar()
        ttk.Label(frame,textvariable=self.guide,wraplength=730).pack(fill="x",pady=8)
        self.tree=ttk.Treeview(frame,columns=("name","size","captions"),show="headings",selectmode="extended",height=10)
        for col,title,size in [("name","Clip",400),("size","Size",70),("captions","Captions",160)]:
            self.tree.heading(col,text=title)
            self.tree.column(col,width=size)
        area=ttk.Frame(frame)
        area.pack(fill="both",expand=True)
        self.tree.pack(in_=area,side="left",fill="both",expand=True)
        scroll=ttk.Scrollbar(area,command=self.tree.yview)
        scroll.pack(side="right",fill="y")
        self.tree.configure(yscrollcommand=scroll.set)
        self.tree.bind("<<TreeviewSelect>>",lambda _:self.selected())
        tools=ttk.Frame(frame)
        tools.pack(fill="x",pady=6)
        ttk.Button(tools,text="Select all",command=lambda:self.tree.selection_set(self.tree.get_children())).pack(side="left")
        ttk.Button(tools,text="Preview clip",command=self.preview).pack(side="left",padx=6)
        ttk.Label(tools,text="Caption track").pack(side="left",padx=(8,3))
        self.caption=ttk.Combobox(tools,state="disabled",width=32)
        self.caption.pack(side="left")
        self.caption.bind("<<ComboboxSelected>>",lambda _:self.pick_caption())
        ttk.Label(frame,text="Original post or creator link (optional, one clip)").pack(anchor="w")
        self.origin=ttk.Entry(frame)
        self.origin.pack(fill="x",pady=(0,6))
        self.note=tk.StringVar(value="Choose one or more downloads. Hold Ctrl or Shift to select several clips.")
        ttk.Label(frame,textvariable=self.note,wraplength=720).pack(anchor="w",pady=4)
        buttons=ttk.Frame(frame)
        buttons.pack(fill="x")
        ttk.Button(buttons,text="Close",command=self.close).pack(side="right")
        self.submit=ttk.Button(buttons,text="Import selected clips",command=self.import_now,state="disabled")
        self.submit.pack(side="right",padx=5)
        self.refresh_guide()
        self.window.protocol("WM_DELETE_WINDOW",self.close)

    def refresh_guide(self):
        if self.platform.get()=="YouTube Shorts":
            self.guide.set("In YouTube Studio, open Content, find your Short and choose Download from its menu. For multiple uploads, select YouTube videos in Google Takeout and export as ZIP.")
            self.help_link.configure(text="YouTube download help")
        else:
            self.guide.set("In Meta Accounts Center, open Your information and permissions, then export your information. Choose your profile and its posts, reels or videos, and download to your device.")
            self.help_link.configure(text="Open Meta Accounts Center")

    def help(self):
        url=("https://support.google.com/youtube/answer/56100" if self.platform.get()=="YouTube Shorts"
             else "https://accountscenter.facebook.com/info_and_permissions/dyi/")
        webbrowser.open(url)

    def changed(self):
        self.refresh_guide()
        self.origin.delete(0,"end")
        if self.files and not self.busy:
            self.load(self.files)

    def choose(self):
        if self.busy: return
        files=filedialog.askopenfilenames(parent=self.window,title="Select videos or creator export ZIP files",
              filetypes=[("Videos, captions and ZIP exports","*.mp4 *.mov *.m4v *.mkv *.webm *.avi *.zip *.srt *.vtt"),
                         ("ZIP exports","*.zip"),("All files","*.*")])
        if files: self.load(files)

    def load(self,files):
        platform=self.platform.get()
        self.files=list(files)
        self.items=[]
        self.selection={}
        self.tree.delete(*self.tree.get_children())
        self.submit.configure(state="disabled")
        def show(items):
            self.items=items
            for i,item in enumerate(items):
                caps=sorted(item.captions,key=lambda c:(not c.name.lower().endswith(".en.vtt"),not c.name.lower().endswith(".vtt"),c.name))
                self.selection[i]=caps[0] if caps else None
                caption=self.selection[i]
                self.tree.insert("","end",iid=str(i),values=(item.video.member or item.video.name,
                                 f"{item.video.size/1048576:.1f} MB",caption.name if caption else "—"))
            if len(items)==1: self.tree.selection_set("0")
            self.note.set(f"{len(items)} clips found. Select clips to import." if items else
                          "No creator videos found for this source. Choose another platform or select a downloaded video directly.")
            self.selected()
        self.work("Reading creator downloads…",lambda:socials.scan(files,platform),show)

    def selected(self):
        indexes=[int(i) for i in self.tree.selection()]
        self.submit.configure(state="normal" if indexes and not self.busy else "disabled")
        self.submit.configure(text=f"Import {len(indexes)} selected" if indexes else "Import selected clips")
        if len(indexes)!=1:
            self.caption.set("")
            self.caption.configure(state="disabled")
            return
        i=indexes[0]
        captions=self.items[i].captions
        self.caption_names={"No sidecar":None}
        for c in captions:
            self.caption_names[c.member or c.name]=c
        self.caption.configure(values=list(self.caption_names),state="readonly" if captions else "disabled")
        chosen=self.selection[i]
        self.caption.set((chosen.member or chosen.name) if chosen else "No sidecar")

    def pick_caption(self):
        selected=self.tree.selection()
        if len(selected)==1:
            i=int(selected[0])
            cap=self.caption_names[self.caption.get()]
            self.selection[i]=cap
            self.tree.set(selected[0],"captions",cap.name if cap else "—")

    def preview(self):
        if self.busy: return
        selected=self.tree.selection()
        if len(selected)!=1:
            self.note.set("Select one clip to preview.")
            return
        asset=self.items[int(selected[0])].video
        def create():
            cache=self.editor.root/"cache"
            cache.mkdir(exist_ok=True)
            with tempfile.TemporaryDirectory(dir=cache) as temp:
                folder=Path(temp)
                local=folder/("media"+Path(asset.name).suffix.lower())
                socials.copy_asset(asset,local,socials.MAX_VIDEO)
                socials.check_container(local)
                media.run(["-format_whitelist",media.LOCAL_FORMATS,"-protocol_whitelist","file,pipe","-i",str(local),
                           "-t","8","-map","0:v:0","-map","0:a:0?","-vf","scale=640:640:force_original_aspect_ratio=decrease:force_divisible_by=2",
                           "-c:v","libx264","-preset","veryfast","-c:a","aac","-movflags","+faststart","preview.mp4"],folder)
                previews=self.editor.root/"previews"
                previews.mkdir(exist_ok=True)
                final=previews/("social-"+uuid.uuid4().hex+".mp4")
                (folder/"preview.mp4").rename(final)
                return final
        self.work("Preparing an eight-second preview…",create,lambda path:os.startfile(path))

    def import_now(self):
        if self.busy: return
        selected=[(self.items[int(i)],self.selection[int(i)]) for i in self.tree.selection()]
        platform=self.platform.get()
        link=self.origin.get()
        def accept(result):
            project,folder,added=result
            if added:
                try:
                    self.editor.save(lambda:self.editor.p.update(project))
                except Exception:
                    # File-only work was staged under one new, generated directory.
                    # Preserve media if the project save succeeded before a GUI error.
                    import json
                    saved=json.loads((self.editor.root/"episode.json").read_text(encoding="utf-8"))
                    incoming={s["id"] for s in project["sources"] if s.get("original_path","").startswith("social/"+folder.name+"/")}
                    if incoming.isdisjoint(s["id"] for s in saved["sources"]):
                        shutil.rmtree(folder)
                    raise
            self.editor.status.set(f"Imported {added} clip(s) from {platform}. {len(selected)-added} already in this project.")
            self.window.destroy()
        self.work("Copying selected originals and preparing editable cuts…",
                  lambda:socials.import_batch(self.editor.root,self.editor.p,selected,platform,link),accept)

    def work(self,note,run,done):
        self.busy=True
        self.editor.busy=True
        self.source.configure(state="disabled")
        self.submit.configure(state="disabled")
        self.note.set(note)
        def worker():
            try: self.result.put((run(),None))
            except Exception as error: self.result.put((None,str(error)))
        def check():
            try: value,error=self.result.get_nowait()
            except queue.Empty:
                self.window.after(100,check)
                return
            self.busy=False
            self.editor.busy=False
            self.source.configure(state="readonly")
            self.selected()
            if error:
                self.note.set(error)
                return
            self.note.set("Ready.")
            try: done(value)
            except Exception as exc: messagebox.showerror("Import social clips",str(exc),parent=self.window)
        threading.Thread(target=worker,daemon=True).start()
        self.window.after(100,check)

    def close(self):
        if self.busy:
            self.note.set("Finish the current import operation before closing.")
        else:
            self.window.destroy()
