"""Sequence acceptance checks using small, real FFmpeg recordings."""
import copy
import json
import math
import subprocess
import tempfile
import unittest
from array import array
from pathlib import Path

import media
import sequence


class SequenceTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name) / "project"
        self.project = media.new_project(self.root, "Studio cut list")

    def source(self, name, color, audio, duration=4, offset=0):
        path = self.root / (name + ".mkv")
        media.run(["-f", "lavfi", "-i", f"color=c={color}:s=160x90:r=24", "-f", "lavfi", "-i", audio,
                   "-t", str(duration), "-c:v", "libx264", "-c:a", "pcm_s16le", str(path)])
        return media.import_source(self.root, self.project, path, "camera", offset)

    def probe(self, path, time, args):
        return subprocess.check_output([media.ffmpeg(), "-hide_banner", "-nostdin", "-loglevel", "error",
                                        "-ss", str(time), "-i", str(path), *args], stderr=subprocess.PIPE)

    def test_reordered_repeated_angles_and_trimmed_voice_export(self):
        red = self.source("red", "red", "sine=frequency=320:sample_rate=48000")
        blue = self.source("blue", "blue", "sine=frequency=700:sample_rate=48000", offset=1)
        p = self.project
        p["sequence"] = [{"camera": blue["id"], "start": 2, "end": 3},
                         {"camera": red["id"], "start": 0, "end": 1},
                         {"camera": blue["id"], "start": 2, "end": 3}]
        p["edit"].update(intro="Beginning", intro_seconds=0.5, outro="Credits", outro_seconds=0.5)
        p["captions"] = [{"start": 2.2, "end": 2.7, "text": "Blue angle"},
                         {"start": 0.2, "end": 0.6, "text": "Red angle"},
                         {"start": 1.5, "end": 1.8, "text": "Deleted"}]
        p["product_tags"] = [{"variant_id": "jar", "start": 2.0, "end": 2.3}]
        p["sponsor_rundown"] = [{"booking_id": 8, "placement": 2.2}, {"booking_id": 9, "placement": 1.5}]
        red["muted"] = True
        before = copy.deepcopy(p)
        exported = media.render(self.root, p, wide=(160, 90), fps=24)
        movie = exported / "episode.mp4"
        self.assertAlmostEqual(media.metadata(movie)["duration"], 4, delta=0.1)
        for time, channel in [(0.8, 2), (1.8, 0), (2.8, 2)]:
            pixel = self.probe(movie, time, ["-frames:v", "1", "-vf", "scale=1:1", "-f", "rawvideo", "-pix_fmt", "rgb24", "pipe:1"])
            self.assertGreater(pixel[channel], 180)
        def rms(time):
            samples = array("h", self.probe(movie, time, ["-map", "0:a:0", "-t", "0.16", "-ar", "16000",
                                                           "-ac", "1", "-f", "s16le", "pipe:1"]))
            return math.sqrt(sum(x*x for x in samples)/len(samples))
        self.assertGreater(rms(0.8), 500)
        self.assertLess(rms(1.8), 10)
        manifest = json.loads((exported / "studio-package.json").read_text())
        self.assertEqual([0.5, 2.5], [t["start"] for t in manifest["product_tags"]])
        self.assertEqual([0.7, 2.7], manifest["sponsor_rundown"][0]["program_positions"])
        self.assertIsNone(manifest["sponsor_rundown"][1]["program_position"])
        captions = media.parse_captions((exported / "episode.vtt").read_text())
        self.assertEqual([("Blue angle", 0.7), ("Red angle", 1.7), ("Blue angle", 2.7)],
                         [(row["text"], row["start"]) for row in captions])
        self.assertEqual(before, p)
        p["sequence"].reverse()
        p["sequence"].pop()
        with self.assertRaisesRegex(ValueError, "edit has changed"):
            media.approve(self.root, p, exported.name)

    def test_gap_analysis_respects_sequencing_and_second_speaker(self):
        # The host speaks from 1 to 1.5; an independent guest speaks from 2 to 2.5.
        host = self.source("host", "red", "aevalsrc='if(between(t,1,1.5),0.4*sin(2*PI*440*t),0)':s=48000")
        voice = self.root / "guest.wav"
        media.run(["-f", "lavfi", "-i", "aevalsrc='if(between(t,2,2.5),0.4*sin(2*PI*550*t),0)':s=48000",
                   "-t", "4", str(voice)])
        guest = media.import_source(self.root, self.project, voice, "voice")
        p = self.project
        p["sequence"] = [{"camera": host["id"], "start": 2, "end": 4},
                         {"camera": host["id"], "start": 0, "end": 2}]
        intake = copy.deepcopy(p)
        result = sequence.analyze_gaps(self.root, p, min_silence=0.3, padding=0.1)
        for gap, expected in zip(result, [(0.6, 2.9), (3.6, 4.0)], strict=True):
            self.assertAlmostEqual(gap["start"], expected[0], delta=0.025)
            self.assertAlmostEqual(gap["end"], expected[1], delta=0.025)
        self.assertEqual(intake, p)
        guest["muted"] = True
        self.assertAlmostEqual(sequence.analyze_gaps(self.root, p, min_silence=.3, padding=.1)[0]["start"], 0)
        guest["muted"] = False
        guest["gain_db"] = -60
        self.assertAlmostEqual(sequence.analyze_gaps(self.root, p, min_silence=.3, padding=.1)[0]["start"], 0)
        host["muted"] = True
        self.assertEqual([], sequence.analyze_gaps(self.root, p))

    def test_legacy_switches_and_invalid_ranges(self):
        p = self.project
        master = self.source("host", "red", "anullsrc=r=48000:cl=stereo")
        other = self.source("guest", "blue", "anullsrc=r=48000:cl=stereo", offset=1)
        p["edit"]["switches"] = [{"at": 2, "camera": other["id"]}]
        old = sequence.segments(p)
        self.assertEqual([(master["id"], 0, 2), (other["id"], 2, 4)], [(r["camera"], r["start"], r["end"]) for r in old])
        p["sequence"] = []
        self.assertEqual(sequence.segments(p), old)
        p["sequence"] = [{"camera": other["id"], "start": 0, "end": 2}]
        with self.assertRaisesRegex(ValueError, "sequence cut start"):
            sequence.segments(p)


if __name__ == "__main__":
    unittest.main()
