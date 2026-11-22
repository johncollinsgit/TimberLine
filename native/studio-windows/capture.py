"""Windows capture devices. Every session gets a new archive directory."""
import re
import subprocess
import time
import uuid
from datetime import datetime
from pathlib import Path

from media import ffmpeg


def devices():
    result=subprocess.run([ffmpeg(),"-hide_banner","-list_devices","true","-f","dshow","-i","dummy"],
                          capture_output=True,text=True,encoding="utf-8",errors="replace")
    found={"audio":[],"video":[]}
    for line in result.stderr.splitlines():
        match=re.search(r'"([^"]+)"\s+\((audio|video)\)',line)
        if match and match[1] not in found[match[2]]:
            found[match[2]].append(match[1])
    return found


class Capture:
    def __init__(self, root):
        self.root=Path(root)
        self.active=[]
        self.directory=None

    def start(self, cameras, microphone):
        if self.active or not any(cameras) or len(set(cameras))!=len(cameras):
            raise ValueError("Choose one or two different cameras.")
        self.directory=self.root/"recordings"/(datetime.now().strftime("%Y%m%d-%H%M%S")+"-"+uuid.uuid4().hex[:6])
        self.directory.mkdir(parents=True)
        cmd_prefix=[ffmpeg(),"-hide_banner","-loglevel","warning","-f","dshow","-rtbufsize","512M"]
        tracks=[("camera",f"video={cam}",["-an","-c:v","libx264","-preset","ultrafast","-crf","17","-pix_fmt","yuv420p"]) for cam in cameras]
        if microphone:
            tracks.append(("voice",f"audio={microphone}",["-vn","-c:a","pcm_s24le"]))
        self.anchor=time.monotonic()
        try:
            for i,(kind,device,encoding) in enumerate(tracks):
                raw=self.directory/f"{kind}-{i+1}.mkv"
                log=raw.with_suffix(".log")
                with log.open("wb") as out:
                    process=subprocess.Popen([*cmd_prefix,"-i",device,*encoding,"-flush_packets","1","-cluster_time_limit","1000",str(raw)],
                                             stdin=subprocess.PIPE,stdout=subprocess.DEVNULL,stderr=out)
                self.active.append((kind,raw,process,time.monotonic()-self.anchor))
            time.sleep(0.9)
            errors=self.errors()
            if errors:
                raise ValueError(errors)
        except BaseException:
            self.stop()
            raise

    def errors(self):
        return "\n".join(f"{raw.name}: {raw.with_suffix('.log').read_text(encoding='utf-8',errors='replace')[-700:]}"
                         for _,raw,proc,_ in self.active if proc.poll() is not None)

    def stop(self):
        tracks,self.active=self.active,[]
        for _,_,proc,_ in tracks:
            if proc.poll() is None:
                try:
                    proc.stdin.write(b"q\n")
                    proc.stdin.flush()
                except (OSError,ValueError):
                    pass
        for _,_,proc,_ in tracks:
            try:
                proc.wait(timeout=12)
            except subprocess.TimeoutExpired:
                proc.terminate()
                proc.wait(timeout=5)
            if proc.stdin:
                proc.stdin.close()
        if not tracks:
            return []
        base=tracks[0][3]
        return [(kind,path,round(offset-base,3)) for kind,path,_,offset in tracks if path.exists() and path.stat().st_size]
