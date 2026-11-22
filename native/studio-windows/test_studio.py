"""Offline acceptance tests using generated camera and voice tracks."""
import copy
import json
import tempfile
import unittest
from pathlib import Path

import media
from rundown import parse_rundown


class StudioTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root=Path(self.tmp.name)/"project"
        self.p=media.new_project(self.root,"Maker stories")

    def test_package(self):
        src=Path(self.tmp.name)/"camera.mp4"
        media.run(["-f","lavfi","-i","testsrc2=size=320x180:rate=15","-f","lavfi","-i","sine=frequency=480:sample_rate=48000",
                   "-t","5","-c:v","libx264","-c:a","aac",str(src)])
        original_hash=media.digest(src)
        cam=media.import_source(self.root,self.p,src)
        alternate=media.import_source(self.root,self.p,src)
        voice=Path(self.tmp.name)/"voice.wav"
        media.run(["-f","lavfi","-i","sine=frequency=220:sample_rate=48000","-t","3",str(voice)])
        media.import_source(self.root,self.p,voice,"voice",1)
        self.p["edit"].update(start=0.5,end=4.5,intro="Meet the maker",intro_seconds=1,
                              switches=[{"at":2,"camera":alternate["id"]}])
        self.p["captions"]=media.parse_captions("WEBVTT\n\n00:00:00.700 --> 00:00:02.000\nHow do you start?\n\n00:00:02.000 --> 00:00:04.000\nMake one thing well.\n")
        self.assertGreater(len(media.suggestions(self.p)),0)
        self.p["clips"]=[{"kind":"short","start":1,"end":3.5,"framing":"left","camera":alternate["id"],"burn_captions":True},
                         {"kind":"trailer","start":0.7,"end":4,"framing":"wide","camera":cam["id"],"burn_captions":False}]
        self.p["product_tags"]=[{"variant_id":"8oz","start":1,"end":2}]
        self.p["sponsor_rundown"]=parse_rundown(self.p,{"schema_version":1,"episode_id":self.p["episode_id"],
            "deliverables":[{"booking_id":9,"kind":"host_read","sponsor":"Fixture","brief":"Introduce the maker","seconds":2}]})
        self.p["sponsor_rundown"][0]["placement"]=1
        output=media.render(self.root,self.p,wide=(320,180),fps=15)
        manifest=json.loads((output/"studio-package.json").read_text())
        self.assertEqual("draft",manifest["approval"])
        self.assertEqual(7,len(manifest["assets"]))
        for asset in manifest["assets"]:
            self.assertEqual(asset["sha256"],media.digest(output/asset["path"]))
            self.assertGreater(asset["bytes"],0)
        self.assertAlmostEqual(5,media.metadata(output/"episode.mp4")["duration"],delta=.1)
        self.assertAlmostEqual(2.5,media.metadata(output/"short-01.mp4")["duration"],delta=.12)
        self.assertTrue(media.metadata(output/"episode.mp3")["audio"])
        self.assertEqual(1.2,media.parse_captions((output/"episode.vtt").read_text())[0]["start"])
        self.assertEqual(0,media.parse_captions((output/"short-01.vtt").read_text())[0]["start"])
        self.assertEqual(1.5,manifest["product_tags"][0]["start"])
        self.assertEqual(0,manifest["clips"][0]["product_tags"][0]["start"])
        self.assertEqual(9,manifest["sponsor_rundown"][0]["booking_id"])
        self.assertEqual(original_hash,media.digest(src))
        self.assertEqual(original_hash,media.digest(self.root/cam["original_path"]))
        media.approve(self.root,self.p,output.name)
        self.assertEqual("approved",json.loads((output/"studio-package.json").read_text())["approval"])
        changed=copy.deepcopy(self.p)
        changed["title"]="Changed"
        with self.assertRaisesRegex(ValueError,"edit has changed"):
            media.approve(self.root,changed,output.name)
        (output/"episode.mp3").write_bytes(b"changed")
        with self.assertRaisesRegex(ValueError,"asset changed"):
            media.approve(self.root,self.p,output.name)

    def test_unfinalized_guest(self):
        browser=Path(self.tmp.name)/"guest.webm"
        media.run(["-f","lavfi","-i","testsrc2=size=160x90:rate=10","-t","2","-c:v","libvpx","-f","webm","-live","1",str(browser)])
        with self.assertRaisesRegex(ValueError,"duration"):
            media.metadata(browser)
        track=media.import_source(self.root,self.p,browser)
        self.assertNotEqual(track["path"],track["original_path"])
        self.assertAlmostEqual(2,track["duration"],delta=.2)
        self.assertEqual(media.digest(browser),media.digest(self.root/track["original_path"]))

    def test_boundaries(self):
        with self.assertRaises(ValueError):
            media.bounded(self.root,"../outside")
        with self.assertRaises(ValueError):
            media.number(float("nan"),0,2,"trim")
        with self.assertRaises(ValueError):
            parse_rundown(self.p,{"schema_version":1,"episode_id":"00000000-0000-0000-0000-000000000000","deliverables":[]})
        rows={"schema_version":1,"episode_id":self.p["episode_id"],
              "deliverables":[{"booking_id":9,"kind":"social_clip","sponsor":"Sample","brief":"Demo","seconds":20}]}
        self.p["sponsor_rundown"]=parse_rundown(self.p,rows)
        self.p["sponsor_rundown"][0]["placement"]=12
        self.assertEqual(12,parse_rundown(self.p,rows)[0]["placement"])


if __name__=="__main__":
    unittest.main(verbosity=2)
