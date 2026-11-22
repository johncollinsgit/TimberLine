"""Real editing-preview formats, timeline seeking and cache behavior."""
import concurrent.futures
import os
import subprocess
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

import media
import preview


class PlaybackFileTests(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.root = Path(temp.name)
        self.src = self.root / "originals"
        self.src.mkdir()

    def recording(self, name, video_args=None, audio_args=None):
        path = self.src / name
        args = []
        if video_args:
            args += ["-f", "lavfi", "-i", "testsrc2=size=160x90:rate=12"]
        if audio_args:
            args += ["-f", "lavfi", "-i", "sine=frequency=440:sample_rate=48000"]
        media.run([*args, "-t", "1.25", *(video_args or []), *(audio_args or []), str(path)])
        return ({"path":path.relative_to(self.root).as_posix(),"kind":"camera" if video_args else "voice"}, path)

    def test_standard_media_stays_direct(self):
        for row in [("editable.mp4", ["-c:v","libx264","-pix_fmt","yuv420p"], ["-c:a","aac"]),
                    ("voice.wav", None, ["-c:a","pcm_s16le"]), ("voice.mp3", None, ["-c:a","libmp3lame"]),
                    ("guest.webm", ["-c:v","libvpx"], ["-c:a","libopus"])]:
            track, path = self.recording(*row)
            self.assertEqual(preview.playable(self.root,track), path)
        self.assertFalse((self.root/"cache").exists())

    def test_prores_and_mkv_become_full_seekable_h264_aac_previews(self):
        for name, video in [("camera.mov",["-c:v","prores_ks","-profile:v","1","-pix_fmt","yuv422p10le"]),
                            ("capture.mkv",["-c:v","libx264","-pix_fmt","yuv420p"])]:
            track, path = self.recording(name, video, ["-c:a","pcm_s16le"])
            source_hash = media.digest(path)
            with concurrent.futures.ThreadPoolExecutor(2) as workers:
                paths = list(workers.map(lambda _: preview.playable(self.root, track), range(2)))
            movie = paths[0]
            self.assertEqual(paths[1], movie)
            self.assertTrue(movie.is_relative_to(self.root/"cache"))
            self.assertEqual(movie.suffix, ".mp4")
            content = movie.read_bytes()
            self.assertLess(content.index(b"moov"), content.index(b"mdat"))
            streams = preview.codec_info(str(movie), (movie.stat().st_size, movie.stat().st_mtime_ns, movie.stat().st_ino))
            self.assertIn("h264", streams[0])
            self.assertEqual(streams[1], "aac")
            self.assertAlmostEqual(1.25, streams[2], delta=.07)
            frame = subprocess.check_output([media.ffmpeg(), "-v", "error", "-ss", "1.00", "-i", str(movie), "-frames:v", "1",
                                             "-vf", "scale=2:2", "-f", "rawvideo", "-pix_fmt", "rgb24", "pipe:1"])
            self.assertEqual(len(frame), 12)
            self.assertEqual(source_hash, media.digest(path))
            with patch("preview.subprocess.run", side_effect=AssertionError("cached media must not start another process")):
                self.assertEqual(preview.playable(self.root,track), movie)
            stamp = path.stat().st_mtime_ns
            os.utime(path, ns=(stamp, stamp+2_000_000_000))
            self.assertNotEqual(preview.playable(self.root,track), movie)

    def test_audio_proxy_private_paths_and_disk_budget(self):
        track, path = self.recording("mix.mkv", None, ["-c:a","pcm_s16le"])
        with patch("preview.shutil.disk_usage", return_value=SimpleNamespace(free=10)), self.assertRaisesRegex(ValueError,"space"):
            preview.playable(self.root,track)
        self.assertEqual(list((self.root/"cache/editor/proxies").iterdir()), [])
        sound = preview.playable(self.root,track)
        self.assertEqual(sound.suffix, ".m4a")
        self.assertAlmostEqual(media.metadata(sound)["duration"],1.25,delta=.08)
        with self.assertRaisesRegex(ValueError,"outside"):
            preview.playable(self.root,{"path":"../example.mkv","kind":"voice"})


if __name__ == "__main__":
    unittest.main()
