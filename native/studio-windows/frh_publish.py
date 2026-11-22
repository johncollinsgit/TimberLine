"""Deliver one explicitly selected, approved Craft revision to the Free Range Humanity feed."""
import argparse
import base64
import hashlib
import inspect
import io
import json
import os
import re
import shlex
import socket
import stat
import subprocess
import sys
import tarfile
import tempfile
import zlib
from pathlib import Path
from uuid import UUID
from xml.etree import ElementTree as ET

MANIFEST = "studio-package.json"
FEED_URL = "https://freerangehumanity.com/podcast/rss.xml"
EPISODE_PAGE = "https://freerangehumanity.com/podcast"
MAX_MANIFEST = 2 * 1024 * 1024


def approved_assets(raw, revision):
    if len(raw) > MAX_MANIFEST:
        raise ValueError("The episode manifest exceeds 2 MiB")
    doc = json.loads(raw.decode("utf-8-sig"))
    if (doc.get("schema_version"), doc.get("visibility"), doc.get("approval")) != (1, "public", "approved"):
        raise ValueError("Only approved public episodes can be published")
    if str(UUID(doc["revision"])) != revision:
        raise ValueError("The approved revision does not match the selected export")
    episode = str(UUID(doc["episode_id"]))
    if not doc.get("title", "").strip() or not doc.get("description", "").strip():
        raise ValueError("An episode needs a title and description")
    assets = doc.get("assets")
    if not isinstance(assets, list) or not 2 <= len(assets) <= 64:
        raise ValueError("Invalid episode asset count")
    types = {"episode_audio": ("mp3", "audio/mpeg"), "episode_video": ("mp4", "video/mp4"),
             "captions": ("vtt", "text/vtt"), "trailer": ("mp4", "video/mp4"), "short": ("mp4", "video/mp4")}
    names = set()
    kinds = []
    for asset in assets:
        name = asset["path"]
        kind = asset["kind"]
        if kind not in types:
            raise ValueError("Unknown export asset type")
        extension, mime = types[kind]
        if (not isinstance(name, str) or not re.fullmatch(r"[A-Za-z0-9_-][A-Za-z0-9_.-]{0,127}", name)
                or name.split(".")[0].upper() in {"NUL", "CON", "PRN", "AUX", *["COM"+str(i) for i in range(10)], *["LPT"+str(i) for i in range(10)]}
                or name.lower() in names or not name.endswith("." + extension) or asset["mime_type"] != mime):
            raise ValueError("Invalid or duplicate export filename")
        if (not re.fullmatch(r"[a-f0-9]{64}", asset["sha256"]) or type(asset["bytes"]) is not int
                or asset["bytes"] <= 0):
            raise ValueError("Invalid export digest or length")
        names.add(name.lower())
        kinds.append(kind)
    if kinds.count("episode_audio") != 1 or kinds.count("episode_video") != 1:
        raise ValueError("One episode audio and video are required")
    return episode, assets


def verify_entry(path, folder, *, directory=False):
    info = path.lstat()
    if (path.resolve(strict=True).parent != folder or stat.S_ISLNK(info.st_mode)
            or getattr(info, "st_file_attributes", 0) & stat.FILE_ATTRIBUTE_REPARSE_POINT
            or not (stat.S_ISDIR(info.st_mode) if directory else stat.S_ISREG(info.st_mode))):
        raise ValueError("Expected a regular entry inside the selected export")


def prepare(root, selected):
    root = Path(root).resolve(strict=True)
    exports = root / "exports"
    verify_entry(exports, root, directory=True)
    folder = exports / selected
    verify_entry(folder, exports, directory=True)
    manifest = folder / MANIFEST
    verify_entry(manifest, folder)
    with manifest.open("rb") as stream:
        raw = stream.read(MAX_MANIFEST + 1)
    episode, assets = approved_assets(raw, selected)
    for asset in assets:
        path = folder / asset["path"]
        verify_entry(path, folder)
        with path.open("rb") as source:
            if os.fstat(source.fileno()).st_size != asset["bytes"] or hashlib.file_digest(source, "sha256").hexdigest() != asset["sha256"]:
                raise ValueError(f"Approved asset changed: {path.name}")
    return folder, raw, episode, assets


def archive_to(stream, folder, raw, assets):
    def add(bundle, filename, source, size):
        header = tarfile.TarInfo(filename)
        header.mode = 0o600
        header.size = size
        bundle.addfile(header, source)

    with tarfile.open(fileobj=stream, mode="w|", bufsize=256*1024) as bundle:
        add(bundle, MANIFEST, io.BytesIO(raw), len(raw))
        for asset in assets:
            path = folder / asset["path"]
            verify_entry(path, folder)
            with path.open("rb") as source:
                add(bundle, path.name, source, asset["bytes"])


def receive(stream, publisher, feed, private, revision, previous, manifest_digest):
    # The sender never names a server path. Only the current canonical RSS is eligible.
    link = ET.parse(feed).findtext("./channel/link", "").rstrip("/")
    if link != EPISODE_PAGE:
        raise ValueError("This feed does not belong to the FRH podcast")
    with tempfile.TemporaryDirectory(dir=private, prefix="upload-") as incoming:
        package = Path(incoming)
        with tarfile.open(fileobj=stream, mode="r|", bufsize=256*1024) as bundle:
            first = bundle.next()
            if first is None or not first.isreg() or first.name != MANIFEST or not 0 < first.size <= MAX_MANIFEST:
                raise ValueError("The archive must begin with the approved episode manifest")
            raw = bundle.extractfile(first).read()
            if hashlib.sha256(raw).hexdigest() != manifest_digest:
                raise ValueError("The selected manifest changed in transit")
            episode, assets = approved_assets(raw, revision)
            (package / MANIFEST).write_bytes(raw)
            for asset in assets:
                member = bundle.next()
                if (member is None or not member.isreg() or member.name != asset["path"]
                        or member.size != asset["bytes"]):
                    raise ValueError("The archive differs from its approved asset list")
                digest = hashlib.sha256()
                source = bundle.extractfile(member)
                with (package / member.name).open("xb") as target:
                    for chunk in iter(lambda: source.read(1024*1024), b""):
                        digest.update(chunk)
                        target.write(chunk)
                if digest.hexdigest() != asset["sha256"]:
                    raise ValueError("The uploaded asset failed verification")
            if bundle.next() is not None:
                raise ValueError("The archive has undeclared content")
        command = [sys.executable, "-I", "-B", str(publisher), str(package), str(feed),
                   "--state", str(private / "publish.sqlite")]
        if previous:
            command.extend(["--previous-revision", str(UUID(previous))])
        result = subprocess.run(command, cwd=publisher.parent.parent, capture_output=True, text=True)
        if result.returncode:
            raise ValueError((result.stderr.strip().splitlines() or ["Publication failed"])[-1])
        guid = "urn:uuid:" + episode
        if result.stdout.strip() != guid:
            raise ValueError("The server did not acknowledge the selected episode")
        return {"guid": guid, "revision": revision, "feed_url": FEED_URL, "episode_url": EPISODE_PAGE + "#episode-" + episode}


def deployed_receiver():
    if socket.gethostname().split(".")[0] != "frh-web-1":
        raise ValueError("Unexpected FRH deployment host")
    os.umask(0o077)
    cwd = subprocess.check_output(["systemctl", "show", "frh-public", "-p", "WorkingDirectory", "--value"], text=True)
    release = Path(cwd.strip()).resolve(strict=True)
    if release.parent != Path("/opt/frh/releases"):
        raise ValueError("Unrecognized active FRH application release")
    publisher = (release / "src/publish_episode.py").resolve(strict=True)
    if publisher.parent != release / "src":
        raise ValueError("Unrecognized feed publisher location")
    # Caddy serves the podcast separately from the application release.
    config = Path("/etc/caddy/Caddyfile").read_text()
    roots = re.findall(r"(?m)^\s*handle\s+@podcastFiles\s*\{\s*root\s+\*\s+(/srv/frh-podcast/releases/[\w.-]+)\s*$", config)
    if len(roots) != 1:
        raise ValueError("Could not identify Caddy's current podcast root")
    store = Path("/srv/frh-podcast")
    feed = (Path(roots[0]) / "podcast/rss.xml").resolve(strict=True)
    if not feed.is_relative_to(store / "releases"):
        raise ValueError("Unexpected podcast feed location")
    private = store / ".studio"
    private.mkdir(mode=0o700, exist_ok=True)
    if private.is_symlink() or private.stat().st_uid != 0 or private.stat().st_mode & 0o077:
        raise ValueError("Publication staging must be private")
    if private.stat().st_dev != feed.stat().st_dev:
        raise ValueError("The private lock and feed require the same filesystem")
    print(json.dumps(receive(sys.stdin.buffer, publisher, feed, private, *sys.argv[1:])))


def ssh_command(known_hosts, revision, previous, manifest_digest):
    imports = "import hashlib,json,os,re,socket,subprocess,sys,tarfile,tempfile\nfrom pathlib import Path\nfrom uuid import UUID\nfrom xml.etree import ElementTree as ET\n"
    constants = f"MANIFEST={MANIFEST!r}\nMAX_MANIFEST={MAX_MANIFEST!r}\nEPISODE_PAGE={EPISODE_PAGE!r}\nFEED_URL={FEED_URL!r}\n"
    code = imports + constants + "\n".join(inspect.getsource(fn) for fn in (approved_assets, receive, deployed_receiver))
    code += "\ntry:\n    deployed_receiver()\nexcept Exception as error:\n    sys.exit(str(error))\n"
    compressed = base64.b64encode(zlib.compress(code.encode())).decode("ascii")
    launcher = f"import base64,zlib;exec(zlib.decompress(base64.b64decode('{compressed}')))"
    remote = shlex.join(["python3", "-I", "-B", "-c", launcher, revision, previous or "", manifest_digest])
    return ["ssh", "-T", "-p", "22", "-o", "BatchMode=yes", "-o", "StrictHostKeyChecking=yes",
            "-o", "ConnectTimeout=12", "-o", "ServerAliveInterval=30", "-o", "ServerAliveCountMax=3",
            "-o", "IdentitiesOnly=yes", "-o", "ForwardAgent=no", "-o", "ClearAllForwardings=yes",
            "-o", "HostName=167.172.242.43", "-o", "User=root", "-o", "GlobalKnownHostsFile=none",
            "-o", 'UserKnownHostsFile="' + str(known_hosts) + '"', "everbranch-prod", remote]


def publish_revision(project_root, revision, *, confirmed=False, previous_revision=None):
    if confirmed is not True:
        raise ValueError("Select Publish to send an approved public revision to FRH")
    revision = str(UUID(revision))
    previous = str(UUID(previous_revision)) if previous_revision else None
    folder, raw, episode, assets = prepare(project_root, revision)
    known = Path.home() / ".ssh/frh-known-hosts"
    if not known.is_file():
        raise ValueError("The verified FRH server host key file is missing on this PC")
    command = ssh_command(known, revision, previous, hashlib.sha256(raw).hexdigest())
    # Use temporary files for small SSH diagnostics so a full OS pipe cannot stall a large upload.
    with tempfile.TemporaryFile() as out, tempfile.TemporaryFile() as err:
        with subprocess.Popen(command, stdin=subprocess.PIPE, stdout=out, stderr=err) as process:
            try:
                with process.stdin:
                    archive_to(process.stdin, folder, raw, assets)
            except BrokenPipeError:
                pass
            if process.wait() != 0:
                err.seek(0)
                detail = err.read(4096).decode(errors="replace").strip()
                raise RuntimeError(detail or "FRH did not acknowledge publication. Retrying the same revision is safe.")
        out.seek(0)
        result = json.loads(out.read(8192))
    if result.get("guid") != "urn:uuid:" + episode or result.get("revision") != revision or result.get("feed_url") != FEED_URL:
        raise RuntimeError("FRH did not confirm the selected revision")
    return result


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("project", type=Path)
    parser.add_argument("--publish-revision", required=True)
    parser.add_argument("--confirm-publish", action="store_true", help="Explicitly publish this approved public revision")
    parser.add_argument("--previous-revision", help="Explicitly approved current revision being replaced")
    args = parser.parse_args()
    try:
        print(json.dumps(publish_revision(args.project, args.publish_revision,
                                          confirmed=args.confirm_publish, previous_revision=args.previous_revision)))
    except (OSError, ValueError, RuntimeError, KeyError) as error:
        parser.exit(1, str(error) + "\n")
