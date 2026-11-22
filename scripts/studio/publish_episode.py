"""Publish an approved public Studio revision to an existing self-hosted RSS feed."""
import argparse
import hashlib
import json
import os
import shutil
import sqlite3
import tempfile
from contextlib import closing
from datetime import datetime, timezone
from email.utils import format_datetime
from pathlib import Path
from uuid import UUID
from xml.etree import ElementTree as ET

NS = "https://theeverbranch.com/ns/studio/1"
ITUNES = "http://www.itunes.com/dtds/podcast-1.0.dtd"
ET.register_namespace("studio", NS)
ET.register_namespace("itunes", ITUNES)
ET.register_namespace("atom", "http://www.w3.org/2005/Atom")
TYPES = {"episode_audio": ("mp3", "audio/mpeg"), "episode_video": ("mp4", "video/mp4"),
         "captions": ("vtt", "text/vtt"), "trailer": ("mp4", "video/mp4"), "short": ("mp4", "video/mp4")}


def install_file(source, folder, expected, size, suffix):
    """Hash the bytes actually staged, then make the verified file available by digest."""
    if not isinstance(expected, str) or len(expected) != 64 or size <= 0:
        raise ValueError("Missing asset digest or size")
    name = f"{expected}.{suffix}"
    with tempfile.NamedTemporaryFile(dir=folder, delete=False, prefix=".staging-") as target:
        temp = Path(target.name)
        try:
            with source.open("rb") as original:
                shutil.copyfileobj(original, target, length=1024 * 1024)
        except BaseException:
            target.close()
            temp.unlink(missing_ok=True)
            raise
    try:
        with temp.open("rb") as saved:
            actual = hashlib.file_digest(saved, "sha256").hexdigest()
        if actual != expected or temp.stat().st_size != size:
            raise ValueError(f"Asset changed: {source.name}")
        temp.chmod(0o644)
        os.replace(temp, folder / name)
    finally:
        temp.unlink(missing_ok=True)
    return name


def publish(package, feed, state, previous=None):
    package, feed = Path(package).resolve(strict=True), Path(feed).resolve(strict=True)
    manifest = json.loads((package / "studio-package.json").read_text(encoding="utf-8-sig"))
    if (manifest.get("schema_version"), manifest.get("approval"), manifest.get("visibility")) != (1, "approved", "public"):
        raise ValueError("Public publishing requires an approved public revision")
    episode, revision = (str(UUID(manifest[k])) for k in ("episode_id", "revision"))
    title, description = (manifest[k].strip() for k in ("title", "description"))
    if not title or not description:
        raise ValueError("Episode needs a title and description")
    asset_rows = manifest["assets"]
    if not isinstance(asset_rows, list) or len(asset_rows) > 64:
        raise ValueError("Invalid asset manifest")
    # Serialize publishers independently of the active feed inode, which is replaced on commit.
    state = Path(state).resolve()
    if state.is_relative_to(feed.parent):
        raise ValueError("Keep publication state outside the public feed directory")
    with closing(sqlite3.connect(state, timeout=60)) as lock, lock:
        lock.execute("BEGIN IMMEDIATE")
        tree = ET.parse(feed)
        channel = tree.find("channel")
        link = channel.findtext("link", "").rstrip("/")
        if not link.startswith("https://") or "?" in link or "#" in link:
            raise ValueError("RSS channel needs its canonical HTTPS episode page")
        guid = "urn:uuid:" + episode
        matches = [item for item in channel.findall("item") if item.findtext("guid") == guid]
        if len(matches) > 1:
            raise ValueError("Duplicate episode GUID in existing feed")
        old = matches[0] if matches else None
        old_rev = old.findtext(f"{{{NS}}}revision") if old is not None else None
        if old_rev != revision and old_rev != previous:
            raise ValueError("Episode changed. Supply its current revision to replace it.")
        item = ET.Element("item")
        ET.SubElement(item, "guid", isPermaLink="false").text = guid
        for tag, value in [("title", title), ("description", description), ("link", link + "#episode-" + episode),
                           ("pubDate", old.findtext("pubDate") if old is not None else format_datetime(datetime.now(timezone.utc))),
                           (f"{{{NS}}}revision", revision)]:
            ET.SubElement(item, tag).text = value
        seen = set()
        prepared = []
        media = feed.parent / "media"
        media.mkdir(exist_ok=True)
        for asset in asset_rows:
            kind = asset["kind"]
            if kind not in TYPES or (kind != "short" and kind in seen):
                raise ValueError(f"Unsupported or duplicate asset: {kind}")
            if asset["mime_type"] != TYPES[kind][1]:
                raise ValueError(f"Incorrect media type for {kind}")
            source = (package / asset["path"]).resolve(strict=True)
            if not source.is_relative_to(package) or not source.is_file() or Path(asset["path"]).is_absolute():
                raise ValueError("Asset outside revision directory")
            seen.add(kind)
            prepared.append((kind, source, asset))
        if not {"episode_audio", "episode_video"} <= seen:
            raise ValueError("Episode audio and video are required")
        # Stage outside the served directory. A rejected package exposes no assets.
        with tempfile.TemporaryDirectory(dir=state.parent) as staging:
            staged = Path(staging)
            for kind, source, asset in prepared:
                suffix, mime = TYPES[kind]
                name = install_file(source, staged, asset["sha256"], asset["bytes"], suffix)
                url = f"{link}/media/{name}"
                if kind == "episode_audio":
                    ET.SubElement(item, "enclosure", url=url, length=str(asset["bytes"]), type=mime)
                else:
                    ET.SubElement(item, f"{{{NS}}}asset", kind=kind, url=url, type=mime)
            if old_rev == revision:
                if ET.tostring(old).strip() != ET.tostring(item).strip():
                    raise ValueError("An immutable revision cannot change")
                return guid
            if old is not None:
                channel.remove(old)
            first = channel.find("item")
            channel.insert(list(channel).index(first) if first is not None else len(channel), item)
            for source in staged.iterdir():
                # Both locations must live on the same volume so publication is atomic.
                os.replace(source, media / source.name)
            with tempfile.NamedTemporaryFile(dir=feed.parent, delete=False, prefix=".rss-") as target:
                tmp = Path(target.name)
                try:
                    tree.write(target, encoding="utf-8", xml_declaration=True)
                    target.flush()
                    os.fsync(target.fileno())
                finally:
                    target.close()
            try:
                tmp.chmod(0o644)
                os.replace(tmp, feed)
            finally:
                tmp.unlink(missing_ok=True)
    return guid


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("package", type=Path)
    parser.add_argument("feed", type=Path)
    parser.add_argument("--state", required=True, type=Path, help="Private SQLite lock on the same volume as the feed")
    parser.add_argument("--previous-revision", help="Current revision UUID for an intentional correction")
    args = parser.parse_args()
    print(publish(args.package, args.feed, args.state, args.previous_revision))
