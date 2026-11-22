import hashlib
import json
import tempfile
import unittest
from pathlib import Path
from uuid import uuid4
from xml.etree import ElementTree as ET

from publish_episode import publish


class PodcastPublishTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        base = Path(self.tmp.name)
        self.package = base / "package"
        self.package.mkdir()
        self.state = base / "lock.sqlite"
        self.feed = base / "www" / "rss.xml"
        self.feed.parent.mkdir()
        self.feed.write_text("<rss><channel><link>https://example.test/podcast</link><item><guid>archive</guid></item></channel></rss>")
        self.manifest = {"schema_version": 1, "approval": "approved", "visibility": "public", "title": "Two makers",
                         "description": "John & Sarah", "episode_id": str(uuid4()), "revision": str(uuid4()), "assets": []}
        for path, kind, mime in [("ep.mp3","episode_audio","audio/mpeg"), ("ep.mp4","episode_video","video/mp4")]:
            raw = path.encode()
            (self.package / path).write_bytes(raw)
            self.manifest["assets"].append({"kind": kind,"path": path,"mime_type": mime,"sha256":hashlib.sha256(raw).hexdigest(),"bytes":len(raw)})

    def send(self, previous=None):
        (self.package / "studio-package.json").write_text(json.dumps(self.manifest))
        return publish(self.package, self.feed, self.state, previous)

    def test_retry_and_revision_preserve_archive(self):
        self.send()
        snapshot = self.feed.read_bytes()
        self.send()
        self.assertEqual(snapshot, self.feed.read_bytes())
        old = self.manifest["revision"]
        self.manifest.update(revision=str(uuid4()), title="Revised")
        with self.assertRaises(ValueError):
            self.send()
        self.send(old)
        self.assertEqual([item.text for item in ET.parse(self.feed).findall(".//guid")], ["urn:uuid:" + self.manifest["episode_id"], "archive"])

    def test_clip_captions_are_linked_to_the_correct_video(self):
        for path, kind, mime, owner in [("trailer.mp4", "trailer", "video/mp4", None), ("trailer.vtt", "captions", "text/vtt", "trailer.mp4"), ("episode.vtt", "captions", "text/vtt", "ep.mp4")]:
            data = path.encode()
            (self.package / path).write_bytes(data)
            self.manifest["assets"].append(dict(path=path, kind=kind, mime_type=mime, sha256=hashlib.sha256(data).hexdigest(), bytes=len(data), video=owner))
        self.send()
        assets = [tag.attrib for tag in ET.parse(self.feed).findall(".//{*}asset")]
        self.assertEqual({a["for"] for a in assets if a["kind"] == "captions"}, {a["url"] for a in assets if a["kind"] in {"episode_video", "trailer"}})

    def test_private_corrupt_and_traversal_assets_never_published(self):
        before = self.feed.read_bytes()
        for change in [{"approval":"draft"}, {"visibility":"members"}]:
            original = self.manifest.copy()
            self.manifest.update(change)
            with self.assertRaises(ValueError):
                self.send()
            self.manifest = original
        asset = self.manifest["assets"][1]
        for change in [{"sha256":"0"*64}, {"path":"../www/rss.xml"}]:
            asset.update(change)
            with self.assertRaises(ValueError):
                self.send()
        self.assertEqual(before, self.feed.read_bytes())
        self.assertEqual(list((self.feed.parent / "media").iterdir()), [])

if __name__ == "__main__":
    unittest.main()
