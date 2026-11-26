"""Build and check a static, public podcast migration from an archived RSS feed."""

import argparse
from concurrent.futures import ThreadPoolExecutor
from copy import deepcopy
from datetime import timezone
from email.utils import parsedate_to_datetime
from hashlib import sha256
import json
from pathlib import Path
import re
import shutil
import sys
from tempfile import TemporaryDirectory
from urllib.error import URLError
from urllib.parse import urlsplit
from urllib.request import Request, urlopen
from xml.etree import ElementTree as ET


ITUNES = "{http://www.itunes.com/dtds/podcast-1.0.dtd}"
ATOM = "{http://www.w3.org/2005/Atom}"
ET.register_namespace("itunes", ITUNES[1:-1])
ET.register_namespace("atom", ATOM[1:-1])
PODCAST_FIELDS = (
    "author", "duration", "explicit", "title", "summary", "subtitle", "season",
    "episode", "episodeType", "block",
)
MEDIA_NAME = re.compile(r"[a-zA-Z0-9][a-zA-Z0-9._-]*\.mp3")


def public_url(value):
    parsed = urlsplit(value)
    if parsed.scheme != "https" or not parsed.hostname or parsed.username or parsed.password:
        raise ValueError(f"Expected a public HTTPS URL: {value}")
    if parsed.fragment:
        raise ValueError(f"Unexpected URL fragment: {value}")
    return value


def read_channel(source):
    if urlsplit(source).scheme in {"https", "http"}:
        with urlopen(public_url(source), timeout=25) as response:
            data = response.read(8 * 1024 * 1024 + 1)
    else:
        data = Path(source).read_bytes()
    if len(data) > 8 * 1024 * 1024:
        raise ValueError("RSS exceeds the 8 MiB input limit.")
    channel = ET.fromstring(data).find("channel")
    if channel is None:
        raise ValueError("Expected an RSS channel.")
    return channel


def episodes(channel):
    by_guid = {}
    for item in channel.findall("item"):
        guid = (item.findtext("guid") or "").strip()
        if not guid or guid in by_guid:
            raise ValueError("Every episode must have a unique, explicit GUID before migration.")
        if not (item.findtext("title") or "").strip():
            raise ValueError(f"No title for {guid}.")
        date = parsedate_to_datetime(item.findtext("pubDate") or "")
        if date.tzinfo is None:
            raise ValueError(f"No publication time zone for {guid}.")
        by_guid[guid] = item
    if not by_guid:
        raise ValueError("The source feed has no public episodes.")
    return by_guid


def media_links(base, old_episodes, archive):
    manifest = json.loads((archive / "episode-manifest.json").read_text(encoding="utf-8"))
    by_guid = {row["guid"]: row for row in manifest}
    if set(by_guid) != set(old_episodes) or len(by_guid) != len(manifest):
        raise ValueError("The media manifest must match the source GUIDs exactly.")
    reserved = set()
    for guid, item in old_episodes.items():
        row = by_guid[guid]
        name = row["file"]
        enclosure = item.find("enclosure")
        if (not MEDIA_NAME.fullmatch(name) or name.lower() in reserved or enclosure is None
                or enclosure.get("type") != "audio/mpeg"):
            raise ValueError(f"Expected one unique archived MP3 for {guid}.")
        reserved.add(name.lower())
        source = archive / name
        size = source.stat().st_size
        if size <= 0 or size != row["bytes"] or size != int(enclosure.get("length", 0)):
            raise ValueError(f"Archived media length differs from RSS for {guid}.")
        yield item, source, f"{base}/media/{name}", size


def copy_fields(source, destination, tags):
    for tag in tags:
        for value in source.findall(tag):
            destination.append(deepcopy(value))


def build(args):
    base = public_url(args.base_url).rstrip("/")
    if urlsplit(base).query:
        raise ValueError("The publish base must be a directory URL without a query.")
    website = public_url(args.website)
    cover = args.cover
    if cover.suffix.lower() not in {".jpg", ".jpeg", ".png"} or not cover.is_file():
        raise ValueError("Provide a podcast cover in JPEG or PNG format.")
    cover_version = sha256(cover.read_bytes()).hexdigest()[:12]
    artwork = f"art/cover-{cover_version}{cover.suffix.lower()}"
    source = read_channel(args.source)
    if any(link.get("rel") == "next" for link in source.findall(ATOM + "link")):
        print("Use a source snapshot containing all public episodes. This tool does not follow paginated feeds.", file=sys.stderr)
    audio = list(media_links(base, episodes(source), args.archive))

    rss = ET.Element("rss", version="2.0")
    channel = ET.SubElement(rss, "channel")
    ET.SubElement(channel, ATOM + "link", href=f"{base}/rss.xml", rel="self", type="application/rss+xml")
    for tag, value in {
        "title": args.title, "link": website, "description": args.description,
        "language": source.findtext("language") or "en", ITUNES + "author": args.author,
        ITUNES + "explicit": source.findtext(ITUNES + "explicit") or "false",
    }.items():
        ET.SubElement(channel, tag).text = value
    copy_fields(source, channel, ("copyright", ITUNES + "category", ITUNES + "type"))
    if source.find(ITUNES + "category") is None:
        ET.SubElement(channel, ITUNES + "category", text=args.category)
    ET.SubElement(channel, ITUNES + "image", href=f"{base}/{artwork}")
    image = ET.SubElement(channel, "image")
    for tag, value in {"url": f"{base}/{artwork}", "title": args.title, "link": website}.items():
        ET.SubElement(image, tag).text = value

    for old_item, _, url, size in audio:
        item = ET.SubElement(channel, "item")
        copy_fields(old_item, item, ("guid", "title", "pubDate", "description", "link")
                    + tuple(ITUNES + tag for tag in PODCAST_FIELDS))
        ET.SubElement(item, "enclosure", url=url, length=str(size), type="audio/mpeg")

    if args.output.exists() or args.output.is_symlink():
        raise FileExistsError(f"Output already exists: {args.output}")
    args.output.parent.mkdir(parents=True, exist_ok=True)
    with TemporaryDirectory(prefix=".podcast-build-", dir=args.output.parent) as temporary:
        staging = Path(temporary) / "release"
        (staging / "art").mkdir(parents=True)
        (staging / "media").mkdir()
        shutil.copyfile(cover, staging / artwork)
        for _, source_file, _, _ in audio:
            shutil.copyfile(source_file, staging / "media" / source_file.name)
        ET.indent(rss, space="  ")
        ET.ElementTree(rss).write(staging / "rss.xml", encoding="utf-8", xml_declaration=True)
        staging.rename(args.output)
    print(f"Staged {len(audio)} episodes in {args.output}.")


def check_media(url, expected_length, expected_mime):
    url = public_url(url)
    request = Request(url, method="HEAD")
    with urlopen(request, timeout=20) as response:
        if response.headers.get_content_type() != expected_mime:
            raise ValueError(f"Unexpected media type for {url}.")
        if expected_length and response.headers.get("Content-Length") != expected_length:
            raise ValueError(f"Unexpected media length for {url}.")
    if expected_length:
        request = Request(url, headers={"Range": "bytes=0-1"})
        with urlopen(request, timeout=20) as response:
            if (response.status != 206 or response.headers.get("Content-Range") != f"bytes 0-1/{expected_length}"
                    or len(response.read(3)) != 2):
                raise ValueError(f"Byte-range playback check failed for {url}.")


def verify(args):
    source = episodes(read_channel(args.source))
    target = read_channel(args.candidate)
    replacement = episodes(target)
    if source.keys() != replacement.keys():
        raise ValueError("The replacement feed must contain exactly the archived GUIDs before redirect.")
    media = []
    for guid, old in source.items():
        new = replacement[guid]
        if old.find("guid").attrib != new.find("guid").attrib:
            raise ValueError(f"GUID attributes changed for {guid}.")
        for field in ("title", ITUNES + "season", ITUNES + "episode", ITUNES + "duration"):
            if old.findtext(field) != new.findtext(field):
                raise ValueError(f"Episode {field} changed for {guid}.")
        original_date = parsedate_to_datetime(old.findtext("pubDate")).astimezone(timezone.utc)
        new_date = parsedate_to_datetime(new.findtext("pubDate")).astimezone(timezone.utc)
        if original_date != new_date:
            raise ValueError(f"Publication date changed for {guid}.")
        enclosure = new.find("enclosure")
        previous = old.find("enclosure")
        if enclosure is None or previous is None or enclosure.get("type") != "audio/mpeg":
            raise ValueError(f"Expected an MP3 enclosure for {guid}.")
        if enclosure.get("length") != previous.get("length") or int(enclosure.get("length", "0")) <= 0:
            raise ValueError(f"Enclosure length changed for {guid}.")
        url = public_url(enclosure.get("url", ""))
        media.append((url, enclosure.get("length"), "audio/mpeg"))
    if len({row[0] for row in media}) != len(media):
        raise ValueError("The replacement feed reuses an enclosure URL across episodes.")
    cover = target.find(ITUNES + "image")
    if cover is None or not cover.get("href"):
        raise ValueError("The replacement feed needs podcast artwork.")
    cover_url = public_url(cover.get("href"))
    cover_type = "image/png" if urlsplit(cover_url).path.lower().endswith(".png") else "image/jpeg"
    if args.check_http:
        with ThreadPoolExecutor(max_workers=5) as workers:
            list(workers.map(lambda row: check_media(*row), [*media, (cover_url, None, cover_type)]))
    print(f"Verified {len(media)} original GUIDs, dates, titles and enclosure lengths"
          + (" and public audio/artwork delivery." if args.check_http else "."))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="command", required=True)
    build_parser = commands.add_parser("build")
    build_parser.add_argument("--source", required=True, help="Saved public RSS XML.")
    build_parser.add_argument("--archive", required=True, type=Path, help="MP3 files and episode-manifest.json.")
    build_parser.add_argument("--output", required=True, type=Path, help="New, empty publish directory.")
    build_parser.add_argument("--base-url", required=True, help="Public directory prefix for the feed and media.")
    build_parser.add_argument("--website", required=True, help="Public show website.")
    build_parser.add_argument("--cover", required=True, type=Path)
    build_parser.add_argument("--title", required=True)
    build_parser.add_argument("--author", required=True)
    build_parser.add_argument("--description", required=True)
    build_parser.add_argument("--category", default="Business", help="Fallback if the source has no category.")
    build_parser.set_defaults(handler=build)
    verify_parser = commands.add_parser("verify")
    verify_parser.add_argument("--source", required=True)
    verify_parser.add_argument("--candidate", required=True, help="Staged file or public RSS URL.")
    verify_parser.add_argument("--check-http", action="store_true", help="Check public artwork and audio HEAD and range support.")
    verify_parser.set_defaults(handler=verify)
    args = parser.parse_args()
    try:
        args.handler(args)
    except (ValueError, TypeError, KeyError, OSError, URLError, ET.ParseError) as error:
        parser.exit(1, f"{error}\n")


if __name__ == "__main__":
    main()
