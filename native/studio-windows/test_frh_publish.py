"""Exercise Craft export to the actual RSS publisher over an offline subprocess pipe."""
import hashlib
import io
import json
import shlex
import subprocess
import sys
import tarfile
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch
from uuid import uuid4
from xml.etree import ElementTree as ET

import frh_publish as delivery
import media


class DeliveryTests(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.base = Path(temp.name)
        self.project = self.base / "show"
        self.p = media.new_project(self.project, "John and Sarah")
        self.p.update(visibility="public", description="Making a show together.")
        self.private = self.base / "private"
        self.private.mkdir()
        self.feed = self.base / "www" / "podcast" / "rss.xml"
        self.feed.parent.mkdir(parents=True)
        self.feed.write_text("<rss><channel><link>https://freerangehumanity.com/podcast</link><item><guid>archive</guid></item></channel></rss>")
        self.publisher = Path(__file__).resolve().parents[2] / "scripts/studio/publish_episode.py"
        known = self.base / ".ssh/frh-known-hosts"
        known.parent.mkdir()
        known.write_text("offline")
        self.ssh = patch.object(delivery, "ssh_command", side_effect=self.local_receiver)
        self.mock_command = self.ssh.start()
        self.addCleanup(self.ssh.stop)
        home = patch.object(Path, "home", return_value=self.base)
        home.start()
        self.addCleanup(home.stop)

    def local_receiver(self, known, revision, previous, digest):
        return [sys.executable, "-c", "import json,sys;from pathlib import Path;from frh_publish import receive;"
                "print(json.dumps(receive(sys.stdin.buffer,*map(Path,sys.argv[1:4]),*sys.argv[4:])))",
                str(self.publisher), str(self.feed), str(self.private), revision, previous or "", digest]

    def fixture_revision(self):
        revision = str(uuid4())
        folder = self.project / "exports" / revision
        folder.mkdir(parents=True)
        doc = dict(schema_version=1, episode_id=self.p["episode_id"], title="Test", description="Offline.",
                   approval="approved", visibility="public", assets=[], revision=revision)
        for path, kind, mime in [("e.mp3", "episode_audio", "audio/mpeg"), ("e.mp4", "episode_video", "video/mp4")]:
            raw = path.encode()
            (folder / path).write_bytes(raw)
            doc["assets"].append(dict(path=path, kind=kind, mime_type=mime, bytes=len(raw), sha256=hashlib.sha256(raw).hexdigest()))
        (folder / delivery.MANIFEST).write_text(json.dumps(doc))
        return folder, doc

    def test_edited_program_to_feed_with_idempotent_retries_and_intentional_correction(self):
        clip = self.base / "host.mp4"
        media.run(["-f", "lavfi", "-i", "testsrc2=size=160x90:rate=12", "-f", "lavfi", "-i",
                   "sine=frequency=330", "-t", "2", "-c:v", "libx264", "-c:a", "aac", str(clip)])
        host = media.import_source(self.project, self.p, clip)
        self.p["sequence"] = [{"camera":host["id"],"start":1,"end":1.75},{"camera":host["id"],"start":0,"end":0.5}]
        self.p["captions"] = [{"start":1,"end":1.5,"text":"John and Sarah"}]
        folder = media.render(self.project, self.p, wide=(160,90), fps=12)
        with self.assertRaisesRegex(ValueError, "Select Publish"):
            delivery.publish_revision(self.project, folder.name)
        with self.assertRaisesRegex(ValueError, "approved public"):
            delivery.publish_revision(self.project, folder.name, confirmed=True)
        self.mock_command.assert_not_called()
        media.approve(self.project, self.p, folder.name)
        (folder / "private-notes.txt").write_text("never transfer")
        prepared = delivery.prepare(self.project, folder.name)
        stream = io.BytesIO()
        delivery.archive_to(stream, prepared[0], prepared[1], prepared[3])
        stream.seek(0)
        with tarfile.open(fileobj=stream) as bundle:
            self.assertEqual([delivery.MANIFEST] + [row["path"] for row in prepared[3]], bundle.getnames())
        result = delivery.publish_revision(self.project, folder.name, confirmed=True)
        self.assertEqual(result["guid"], "urn:uuid:" + self.p["episode_id"])
        self.assertEqual(result["revision"], folder.name)
        self.assertEqual(result["feed_url"], delivery.FEED_URL)
        xml = self.feed.read_bytes()
        tree = ET.parse(self.feed)
        self.assertEqual([item.text for item in tree.findall(".//guid")], [result["guid"], "archive"])
        audio = tree.find(".//enclosure").get("url").split("/")[-1]
        self.assertEqual(media.digest(folder/"episode.mp3"), media.digest(self.feed.parent/"media"/audio))
        self.assertEqual(result, delivery.publish_revision(self.project, folder.name, confirmed=True))
        self.assertEqual(xml, self.feed.read_bytes())
        self.p["title"] = "Correction"
        new = media.render(self.project, self.p, wide=(160,90), fps=12)
        media.approve(self.project, self.p, new.name)
        with self.assertRaisesRegex(RuntimeError, "current revision"):
            delivery.publish_revision(self.project, new.name, confirmed=True)
        self.assertEqual(xml, self.feed.read_bytes())
        delivery.publish_revision(self.project, new.name, confirmed=True, previous_revision=folder.name)
        self.assertEqual(ET.parse(self.feed).findtext(".//item/title"), "Correction")
        self.assertEqual(list(self.private.glob("upload-*")), [])

    def test_invalid_or_changed_assets_blocked_before_connection(self):
        folder, doc = self.fixture_revision()
        for name in ["../e.mp3", r"C:\private.mp3", "/secret.mp3", "NUL.mp3", "e.mp4"]:
            with self.subTest(name=name), self.assertRaisesRegex(ValueError, "filename"):
                doc["assets"][0]["path"] = name
                (folder/delivery.MANIFEST).write_text(json.dumps(doc))
                delivery.publish_revision(self.project, folder.name, confirmed=True)
        doc["assets"][0]["path"] = "e.mp3"
        doc["visibility"] = "members"
        (folder/delivery.MANIFEST).write_text(json.dumps(doc))
        with self.assertRaisesRegex(ValueError, "approved public"):
            delivery.publish_revision(self.project, folder.name, confirmed=True)
        doc["visibility"] = "public"
        (folder/delivery.MANIFEST).write_text(json.dumps(doc))
        (folder/"e.mp3").write_bytes(b"xxxxx")
        with self.assertRaisesRegex(ValueError, "changed"):
            delivery.publish_revision(self.project, folder.name, confirmed=True)
        self.mock_command.assert_not_called()

    def test_receiver_rechecks_manifest_and_payload_in_private_staging(self):
        folder, doc = self.fixture_revision()
        raw = (folder/delivery.MANIFEST).read_bytes()
        digest = hashlib.sha256(raw).hexdigest()
        before = self.feed.read_bytes()
        for mode, message in [("digest","manifest changed"), ("bytes","verification"), ("extra","undeclared"), ("link","asset list")]:
            stream = io.BytesIO()
            with tarfile.open(mode="w", fileobj=stream) as bundle:
                for name in [delivery.MANIFEST, "e.mp3", "e.mp4"] + (["private.mp3"] if mode == "extra" else []):
                    data = raw if name == delivery.MANIFEST else name.encode()
                    if name == "e.mp3" and mode == "bytes":
                        data = b"x"*len(data)
                    entry = tarfile.TarInfo(name)
                    entry.size = len(data)
                    if name == "e.mp3" and mode == "link":
                        entry.type = tarfile.SYMTYPE
                        entry.linkname = "/private"
                        entry.size = 0
                    bundle.addfile(entry, io.BytesIO(data))
            stream.seek(0)
            with self.subTest(mode=mode), self.assertRaisesRegex(ValueError, message):
                delivery.receive(stream, self.publisher, self.feed, self.private, folder.name, "",
                                  digest if mode != "digest" else "0"*64)
            self.assertEqual(before, self.feed.read_bytes())
            self.assertEqual(list(self.private.iterdir()), [])


if __name__ == "__main__":
    unittest.main()
