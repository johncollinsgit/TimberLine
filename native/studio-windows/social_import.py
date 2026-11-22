"""Select creator-export videos locally, without unpacking personal account data."""
from __future__ import annotations

import copy
import os
import re
import shutil
import stat
import struct
import tempfile
import uuid
import zipfile
from collections import defaultdict
from contextlib import ExitStack
from dataclasses import dataclass, field
from pathlib import Path, PurePosixPath
from urllib.parse import parse_qs, urlsplit, urlunsplit

import media

PLATFORMS = ("Instagram", "Facebook", "YouTube Shorts")
CAPTIONS = {".srt", ".vtt"}
GIB = 1024 ** 3
MAX_VIDEO = 2 * GIB
MAX_CAPTION = 2 * 1024 ** 2
MAX_ARCHIVE = 16 * GIB
MAX_BATCH = 8 * GIB
MAX_ENTRIES = 25000
MAX_DIRECTORY = 12 * 1024 ** 2
MAX_VIDEOS = 1500
MAX_SELECTED = 100
META_ROOTS = {"media", "posts", "reels", "videos", "your_posts", "your_videos",
              "photos_and_videos", "photos_and_videos_of_you", "your_facebook_activity",
              "your_instagram_activity", "your_activity_across_facebook"}
PRIVATE = {"messages","message","inbox","chats","messenger","contacts","archive_of_messages",
           "profile","profile_information","account_information","ads","ads_information",
           "saved_items","your_saved_items","trash","liked_posts","tagged_posts","tagged_media",
           "photos_and_videos_of_you","__macosx","stories","story_archive"}
FOREIGN = {"google photos", "drive","gmail","mail","hangouts","google chat","my activity"}


@dataclass(frozen=True)
class Asset:
    file: Path
    member: str
    size: int
    crc: int | None = None
    @property
    def name(self):
        return PurePosixPath(self.member).name if self.member else self.file.name
    @property
    def key(self):
        return (self.file,self.member)


@dataclass
class Choice:
    video: Asset
    captions: list[Asset] = field(default_factory=list)


def official_link(platform, raw):
    """Keep a canonical creator page URL without tracking or authentication values."""
    if not raw.strip():
        return ""
    url=urlsplit(raw.strip())
    allowed={"Instagram":{"instagram.com","www.instagram.com"},
             "Facebook":{"facebook.com","www.facebook.com","m.facebook.com","fb.watch"},
             "YouTube Shorts":{"youtube.com","www.youtube.com","m.youtube.com","youtu.be"}}[platform]
    if (len(raw)>2048 or url.scheme!="https" or url.hostname not in allowed or url.username or url.password or
            url.port not in (443,None) or not re.fullmatch(r"/[A-Za-z0-9._/%@-]+/?",url.path) or
            any(ord(c)<32 or c=="\\" for c in raw)):
        raise ValueError(f"Enter an HTTPS creator link from {platform}.")
    query=""
    if url.hostname in {"facebook.com","www.facebook.com","m.facebook.com","youtube.com","www.youtube.com","m.youtube.com"}:
        video=parse_qs(url.query).get("v",[""])[0]
        if re.fullmatch(r"[\w-]{1,64}",video):
            query="v="+video
    return urlunsplit(("https",url.hostname,url.path,query,""))


def safe_parts(name):
    if len(name)>1000 or "\\" in name or ":" in name or any(ord(c)<32 for c in name):
        return ()
    bits=name.split("/")
    if any(not b or b.startswith(".") or b.endswith((" ",".")) or b in {".",".."} for b in bits):
        return ()
    return tuple(b.casefold() for b in bits)


def allowed_member(name,platform):
    parts=safe_parts(name)
    if not parts or len(parts)<2:
        return False
    folders=parts[:-1]
    if any(p in PRIVATE | FOREIGN or p.startswith(("message_","personal_information","private_")) for p in folders):
        return False
    if platform=="YouTube Shorts":
        for n,p in enumerate(folders):
            if p in {"youtube","youtube and youtube music","youtube and youtubemusic"} and folders[:n] in ((),("takeout",)):
                return n+1<len(folders) and folders[n+1] in {"videos","shorts","captions","subtitles"}
        return False
    # Allow creator media roots within Meta exports, including a single account wrapper.
    # Never treat arbitrary folders ending in /videos as feeds of creator content.
    for i in (0,1):
        if i>=len(folders):
            break
        if i==1 and not re.match(r"^(instagram|facebook)[-_]",folders[0]):
            continue
        branch=folders[i:]
        if branch[0] in {"your_facebook_activity","your_instagram_activity","your_activity_across_facebook"}:
            branch=branch[1:]
        if not branch or branch[0] not in META_ROOTS:
            continue
        if branch[0]=="media":
            return len(branch)>1 and branch[1] in {"posts","reels","videos","igtv","your_posts","your_videos"}
        return branch[0] in {"posts","reels","videos","your_posts","your_videos","photos_and_videos"}
    return False


def check_directory(path):
    """Bound central-directory memory before handing the stream to ZipFile, including ZIP64."""
    size=path.stat().st_size
    if size>MAX_ARCHIVE or size<22:
        raise ValueError("Choose a ZIP export part smaller than 16 GiB.")
    with path.open("rb") as src:
        src.seek(max(0,size-65557))
        tail=src.read()
        index=tail.rfind(b"PK\x05\x06")
        if index<0 or len(tail)-index<22:
            raise ValueError("Invalid ZIP directory.")
        _,disk,central_disk,on_disk,total,extent,offset,comment=struct.unpack_from("<4s4H2LH",tail,index)
        if index+22+comment!=len(tail):
            raise ValueError("Invalid ZIP directory length.")
        end=size-len(tail)+index
        if total==65535 or extent==0xffffffff or offset==0xffffffff:
            if end<20:
                raise ValueError("Invalid ZIP64 directory.")
            src.seek(end-20)
            sig,zip_disk,at,disks=struct.unpack("<4sLQL",src.read(20))
            if sig!=b"PK\x06\x07" or disks!=1 or zip_disk or at+56>end-20:
                raise ValueError("Select each complete ZIP export part separately.")
            src.seek(at)
            record=struct.unpack("<4sQ2H2L4Q",src.read(56))
            if record[0]!=b"PK\x06\x06":
                raise ValueError("Invalid ZIP64 directory.")
            _,_,_,_,disk,central_disk,on_disk,total,extent,offset=record
        if disk or central_disk or on_disk!=total or total>MAX_ENTRIES or extent>MAX_DIRECTORY or offset+extent>end:
            raise ValueError("ZIP directory exceeds Studio limits. Select a smaller export part.")


def eligible(info):
    mode=info.external_attr >> 16
    return (info.orig_filename==info.filename and not info.is_dir() and not info.flag_bits & 1 and
            stat.S_IFMT(mode) in {0,stat.S_IFREG} and info.compress_type in {zipfile.ZIP_STORED,zipfile.ZIP_DEFLATED} and
            info.file_size>0 and info.file_size<=max(1,info.compress_size)*250)


def caption_base(name):
    stem=PurePosixPath(name).stem.casefold()
    return re.sub(r"[._-](?:[a-z]{2,3}(?:[-_][a-z]{2,4})?(?:[._-](?:orig|auto))?)$","",stem)


def export_group(asset):
    if not asset.member:
        return ("downloads",asset.file.parent)
    batch=re.sub(r"(?i)(?:-part[-_]?\d+|-(?:00\d|0\d{2}))(?:-of-\d+)?$","",asset.file.stem)
    return ("export",asset.file.parent,batch.casefold())


def scan(files,platform):
    if platform not in PLATFORMS:
        raise ValueError("Choose a source platform.")
    inputs=list(dict.fromkeys(Path(p).resolve() for p in files))
    if len(inputs)>250 or sum(p.suffix.lower()==".zip" for p in inputs)>16:
        raise ValueError("Choose up to 250 files or 16 ZIP parts at once.")
    # A normal single video download can have an adjacent sidecar. Read only named candidates.
    additions=[]
    for path in inputs:
        if path.suffix.lower() in media.VIDEO:
            for ext in (".vtt",".srt",".en.vtt",".en.srt"):
                sidecar=path.with_suffix(ext)
                if sidecar.is_file():
                    additions.append(sidecar)
    inputs=list(dict.fromkeys([*inputs,*additions]))
    videos=[]
    captions=[]
    for path in inputs:
        if not path.is_file():
            raise ValueError("A selected download no longer exists.")
        if path.suffix.lower()!=".zip":
            ext=path.suffix.lower()
            size=path.stat().st_size
            if ext in media.VIDEO and (size>MAX_VIDEO or not size):
                raise ValueError(f"{path.name} exceeds the 2 GiB video limit or is empty.")
            if ext in media.VIDEO: videos.append(Asset(path,"",size))
            if ext in CAPTIONS and 0<size<=MAX_CAPTION: captions.append(Asset(path,"",size))
            continue
        check_directory(path)
        with zipfile.ZipFile(path) as z:
            seen=set()
            for info in z.infolist():
                ext=PurePosixPath(info.filename).suffix.lower()
                if ext not in media.VIDEO|CAPTIONS or not allowed_member(info.filename,platform) or not eligible(info):
                    continue
                norm=info.filename.casefold()
                if norm in seen:
                    raise ValueError("An export contains duplicate media paths. Choose individual files instead.")
                seen.add(norm)
                if info.file_size>(MAX_VIDEO if ext in media.VIDEO else MAX_CAPTION):
                    continue
                item=Asset(path,info.filename,info.file_size,info.CRC)
                (videos if ext in media.VIDEO else captions).append(item)
                if len(videos)>MAX_VIDEOS or len(captions)>MAX_ENTRIES:
                    raise ValueError("Too many media files. Choose a smaller creator export.")
    # Match within the same export or direct-file directory. Same-directory
    # matches take precedence. An ambiguous caption never attaches to two clips.
    result=[Choice(v) for v in videos]
    indexes=defaultdict(list)
    for choice in result:
        a=choice.video
        group=export_group(a)
        indexes[group,PurePosixPath(a.name).stem.casefold()].append(choice)
    for cap in captions:
        group=export_group(cap)
        stem=PurePosixPath(cap.name).stem.casefold()
        base=caption_base(cap.name)
        matches=(indexes.get((group,stem)) or indexes.get((group,base)) or
                 (indexes.get((group,PurePosixPath(base).stem)) if PurePosixPath(base).suffix in media.VIDEO else []) or [])
        neighbors=[c for c in matches if PurePosixPath(c.video.member).parent==PurePosixPath(cap.member).parent]
        matched=neighbors or matches
        if len(matched)==1:
            matched[0].captions.append(cap)
    return result


def copy_asset(asset,target,limit):
    if not 0<asset.size<=limit:
        raise ValueError("File size exceeds the import limit.")
    target=Path(target)
    with ExitStack() as stack:
        if asset.member:
            check_directory(asset.file)
            archive=stack.enter_context(zipfile.ZipFile(asset.file))
            entry=archive.getinfo(asset.member)
            if not eligible(entry) or entry.file_size!=asset.size or entry.CRC!=asset.crc:
                raise ValueError("This export changed. Choose the download again.")
            src=stack.enter_context(archive.open(entry))
        else:
            src=stack.enter_context(asset.file.open("rb"))
        output=stack.enter_context(target.open("xb"))
        copied=0
        while chunk:=src.read(1024*1024):
            copied+=len(chunk)
            if copied>asset.size:
                raise ValueError("This download changed size during import.")
            output.write(chunk)
        if copied!=asset.size:
            raise ValueError("The download was incomplete.")


def check_container(file):
    """Choose a local container before probing. A playlist renamed .mp4 is not a video."""
    with file.open("rb") as src:
        header=src.read(16)
    if not (header.startswith(b"\x1a\x45\xdf\xa3") or header[4:8] in {b"ftyp",b"moov",b"wide",b"mdat",b"free"}
            or (header[:4]==b"RIFF" and header[8:12]==b"AVI ")):
        raise ValueError("This file is not a supported video container.")


def import_batch(root,p,selected,platform,origin=""):
    if not selected or len(selected)>MAX_SELECTED:
        raise ValueError("Choose between 1 and 100 clips.")
    if len({v.video.key for v,_ in selected})!=len(selected):
        raise ValueError("The same clip was selected twice.")
    if sum(v.video.size+(c.size if c else 0) for v,c in selected)>MAX_BATCH:
        raise ValueError("Import at most 8 GiB per batch.")
    link=official_link(platform,origin)
    if link and len(selected)!=1:
        raise ValueError("Apply a creator link to one clip at a time.")
    root=Path(root)
    cache=root/"cache"
    cache.mkdir(exist_ok=True)
    result=copy.deepcopy(p)
    known={s.get("source_sha256") for s in result["sources"]}
    base=Path("social")/uuid.uuid4().hex
    added=0
    with tempfile.TemporaryDirectory(dir=cache) as tmp:
        tmp=Path(tmp)
        stage=tmp/"stage"
        stage.mkdir()
        for n,(choice,cap) in enumerate(selected):
            v=choice.video
            if cap and cap not in choice.captions:
                raise ValueError("This caption does not belong to the selected video.")
            local=tmp/(f"clip-{n}"+PurePosixPath(v.name).suffix.lower())
            copy_asset(v,local,MAX_VIDEO)
            checksum=media.digest(local)
            if checksum in known:
                local.unlink()
                continue
            check_container(local)
            previous_master=result["edit"]["camera"]
            track=media.import_source(stage,result,local,"camera")
            local.unlink()
            for field_name in ("original_path","path"):
                track[field_name]=(base/track[field_name]).as_posix()
            track.update(label=PurePosixPath(v.name).stem,source_sha256=checksum,
                         origin={"platform":platform,"filename":v.name,"creator_url":link},source_captions=[])
            if cap:
                caption=stage/"originals"/(track["id"]+PurePosixPath(cap.name).suffix.lower())
                copy_asset(cap,caption,MAX_CAPTION)
                data=caption.read_bytes()
                text=data.decode("utf-16" if data.startswith((b"\xff\xfe",b"\xfe\xff")) else "utf-8-sig")
                cues=media.parse_captions(text)
                if not cues:
                    raise ValueError(f"No timed captions found in {cap.name}.")
                track["source_captions"]=[{**c,"end":min(c["end"],track["duration"])} for c in cues if c["start"]<track["duration"]]
                track["caption_path"]=(base/caption.relative_to(stage)).as_posix()
            if not previous_master:
                result["captions"]=copy.deepcopy(track["source_captions"])
            result["clips"].append({"kind":"short","timeline":"source","camera":track["id"],"framing":"fit",
                                    "label":track["label"],"start":0,"end":track["duration"],"burn_captions":False})
            known.add(checksum)
            added+=1
        if added:
            (root/base).parent.mkdir(exist_ok=True)
            stage.rename(root/base)
            return result,root/base,added
        return result,None,0
