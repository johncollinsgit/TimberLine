import copy
import json
import shutil
import stat
import struct
import tempfile
import unittest
import zipfile
from pathlib import Path
from unittest.mock import patch

import media
import social_import as social


class SocialImportTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.folder=Path(self.tmp.name)
        self.root=self.folder/"episode"
        self.p=media.new_project(self.root,"Creator clips")

    def archive(self,name,files):
        path=self.folder/name
        with zipfile.ZipFile(path,"w") as z:
            for file,data in files:
                z.writestr(file,data)
        return path

    def sample(self,name,seconds=2,hz=440):
        p=self.folder/name
        media.run(["-f","lavfi","-i","color=c=blue:size=144x256:rate=12","-f","lavfi","-i",f"sine=f={hz}",
                   "-t",str(seconds),"-c:v","libx264","-c:a","aac",str(p)])
        return p

    def test_creator_archive_allowlists(self):
        symlink=zipfile.ZipInfo("media/reels/neighbor.mp4")
        symlink.create_system=3
        symlink.external_attr=(stat.S_IFLNK|0o777)<<16
        archive=self.archive("instagram-export.zip",[
            ("your_instagram_activity/media/reels/2026/Good.mp4",b"video"),
            ("your_instagram_activity/media/reels/2026/Good.en.vtt",b"captions"),
            ("your_instagram_activity/media/reels/2026/Good.fr.srt",b"captions"),
            ("media/reels/../outside.mp4",b"x"),("../media/reels/fake.mp4",b"x"),
            ("C:/media/reels/bad.mp4",b"x"),("media\\reels\\bad.mp4",b"x"),
            ("messages/inbox/demo/videos/secret.mp4",b"x"),("media/reels/.hidden.mp4",b"x"),
            ("media/reels/notes.json",b"private metadata"),("media/reels/photos.jpg",b"x"),
            ("profile_information/videos/selfie.mp4",b"x"),("your_instagram_activity/media/stories/old.mp4",b"x"),
            (symlink,b"private.mp4")])
        # ZipInfo normalizes separators on Windows while writing. Simulate a raw archive from another OS.
        archive.write_bytes(archive.read_bytes().replace(b"media/reels/bad.mp4",b"media\\reels\\bad.mp4"))
        items=social.scan([archive],"Instagram")
        self.assertEqual(["Good.mp4"],[v.video.name for v in items])
        self.assertEqual({"Good.en.vtt","Good.fr.srt"},{x.name for x in items[0].captions})
        self.assertEqual([],social.scan([archive],"YouTube Shorts"))
        fb=self.archive("facebook.zip",[("your_facebook_activity/posts/media/your_videos/pour.mp4",b"clip"),
                                        ("your_facebook_activity/messages/media/videos/private.mp4",b"x")])
        self.assertEqual(["pour.mp4"],[v.video.name for v in social.scan([fb],"Facebook")])

    def test_takeout_multiple_parts_and_ambiguous_captions(self):
        one=self.archive("takeout-20261120-001.zip",[("Takeout/YouTube and YouTube Music/videos/Trail.mp4",b"x"),
            ("Takeout/YouTube and YouTube Music/videos/duplicate/Pour.mp4",b"x"),
            ("Takeout/YouTube and YouTube Music/videos/Pour.mp4",b"z"),("Takeout/Google Photos/videos/myfamily.mp4",b"x")])
        two=self.archive("takeout-20261120-002.zip",[("Takeout/YouTube and YouTube Music/captions/Trail.en.vtt",b"x"),
            ("Takeout/YouTube and YouTube Music/captions/Pour.en.vtt",b"x")])
        items=social.scan([one,two],"YouTube Shorts")
        self.assertEqual(3,len(items))
        self.assertEqual(["Trail.en.vtt"],[c.name for c in items[0].captions])
        self.assertEqual([[],[]],[v.captions for v in items[1:]])

    def test_caps_and_safe_links(self):
        small=self.archive("a.zip",[("media/posts/f.mp4",b"1234")])
        with patch.object(social,"MAX_ENTRIES",0),self.assertRaisesRegex(ValueError,"directory exceeds"):
            social.scan([small],"Instagram")
        huge=self.folder/"huge-index.zip"
        # A tiny fixture declaring a central directory too large to load.
        huge.write_bytes(struct.pack("<4s4H2LH",b"PK\x05\x06",0,0,1,1,social.MAX_DIRECTORY+1,0,0))
        with self.assertRaisesRegex(ValueError,"directory exceeds"):
            social.scan([huge],"Instagram")
        with patch.object(social,"MAX_VIDEO",2):
            self.assertEqual([],social.scan([small],"Instagram"))
        v=social.scan([small],"Instagram")[0]
        with patch.object(social,"MAX_BATCH",1),self.assertRaisesRegex(ValueError,"8 GiB"):
            social.import_batch(self.root,self.p,[(v,None)],"Instagram")
        for url in ("file:///private","https://instagram.com.example.org/reel/a",
                    "https://x@instagram.com/reel/a","https://www.facebook.com/reel/a"):
            with self.assertRaises(ValueError):
                social.official_link("Instagram",url)
        self.assertEqual("https://www.youtube.com/watch?v=abc123",
                         social.official_link("YouTube Shorts","https://www.youtube.com/watch?v=abc123&utm_source=x&token=private"))

    def test_batch_rollback_and_dedupe(self):
        video=self.sample("sample.mp4")
        captions=video.with_suffix(".en.vtt")
        captions.write_text("WEBVTT\n\n00:00:00.100 --> 00:00:01.500\nIndependent clip.\n",encoding="utf-8")
        bad=self.folder/"bad.mp4"
        bad.write_bytes(b"THIS IS A TEXT DOCUMENT")
        choices=social.scan([video,bad],"Instagram")
        self.assertEqual([captions.name],[c.name for c in choices[0].captions])
        selected=[(choices[0],choices[0].captions[0]),(choices[1],None)]
        with self.assertRaisesRegex(ValueError,"supported video container"):
            social.import_batch(self.root,self.p,selected,"Instagram")
        self.assertFalse((self.root/"social").exists())
        self.assertEqual([],self.p["sources"])
        single=[selected[0]]
        result,path,count=social.import_batch(self.root,self.p,single,"Instagram","https://www.instagram.com/reel/abc/?igsh=unwanted")
        self.assertEqual(1,count)
        track=result["sources"][0]
        self.assertEqual(media.digest(video),media.digest(self.root/track["original_path"]))
        self.assertEqual(captions.read_bytes(),(self.root/track["caption_path"]).read_bytes())
        self.assertEqual("Independent clip.",result["captions"][0]["text"])
        self.assertEqual("https://www.instagram.com/reel/abc/",track["origin"]["creator_url"])
        again,folder,number=social.import_batch(self.root,result,single,"Instagram")
        self.assertEqual((None,0),(folder,number))
        self.assertEqual(result,again)
        self.assertEqual(1,len(list(path.parent.iterdir())))

    @unittest.skipUnless(__import__("os").name=="nt","Windows GUI")
    def test_editor_import_action(self):
        import os
        import time
        import tkinter as tk
        from studio import Studio
        from social_picker import SocialPicker
        window=tk.Tk()
        editor=Studio(window)
        self.addCleanup(editor.close)
        editor.attach(self.root,self.p)
        clip=self.sample("ui.mp4")
        clip.with_suffix(".vtt").write_text("WEBVTT\n\n00:00:00.100 --> 00:00:01.000\nGUI caption.\n")
        def ready(predicate):
            deadline=time.monotonic()+10
            while not predicate() and time.monotonic()<deadline:
                window.update()
                time.sleep(.02)
            self.assertTrue(predicate())
        picker=SocialPicker(editor)
        window.update()
        with patch("social_picker.filedialog.askopenfilenames",return_value=[str(clip)]):
            picker.choose()
        ready(lambda:not picker.busy)
        self.assertEqual(("0",),picker.tree.selection())
        self.assertEqual("ui.vtt",picker.caption.get())
        with patch.object(os,"startfile") as player:
            picker.preview()
            ready(lambda:not picker.busy)
            player.assert_called_once()
            self.assertTrue(media.metadata(player.call_args.args[0])["video"])
        picker.submit.invoke()
        ready(lambda:not editor.busy)
        self.assertEqual("GUI caption.",editor.p["captions"][0]["text"])
        self.assertEqual("source",editor.p["clips"][0]["timeline"])
        self.assertEqual(1,len(editor.tables["Cuts"].get_children()))
        self.assertEqual(editor.p,json.loads((self.root/"episode.json").read_text(encoding="utf-8")))

    def test_source_cut_keeps_own_sound_and_captions(self):
        master=self.sample("master.mp4",1,hz=200)
        video=self.sample("short.mp4",3,hz=700)
        self.p["description"]="Desktop import check"
        media.import_source(self.root,self.p,master)
        self.p["captions"]=[{"start":0,"end":.5,"text":"Master caption."}]
        original=copy.deepcopy(self.p)
        archive=self.archive("takeout.zip",[("Takeout/YouTube and YouTube Music/videos/Trial.mp4",video.read_bytes()),
                ("Takeout/YouTube and YouTube Music/captions/Trial.en.vtt",b"WEBVTT\n\n00:00:01.000 --> 00:00:02.500\nOwn caption.\n")])
        v=social.scan([archive],"YouTube Shorts")[0]
        result,folder,count=social.import_batch(self.root,self.p,[(v,v.captions[0])],"YouTube Shorts","https://www.youtube.com/shorts/abc123")
        self.assertEqual(original,self.p)
        self.assertEqual(original["captions"],result["captions"])
        output=media.render(self.root,result,wide=(256,144),fps=12)
        clip=json.loads((output/"studio-package.json").read_text())["clips"][0]
        self.assertEqual("https://www.youtube.com/shorts/abc123",clip["source_url"])
        self.assertEqual("Own caption.",media.parse_captions((output/clip["captions"]).read_text())[0]["text"])
        self.assertEqual("Master caption.",media.parse_captions((output/"episode.vtt").read_text())[0]["text"])
        self.assertAlmostEqual(3,media.metadata(output/clip["path"])["duration"],delta=.12)
        self.assertAlmostEqual(1,media.metadata(output/"episode.mp4")["duration"],delta=.12)
        # Count zero crossings in the middle second to distinguish the two generated tones.
        import array
        import subprocess
        audio=subprocess.check_output([media.ffmpeg(),"-loglevel","error","-ss","1","-i",str(output/clip["path"]),
            "-t","1","-vn","-ac","1","-ar","16000","-f","s16le","pipe:1"])
        samples=array.array("h",audio)
        crossings=sum(a<0<=b for a,b in zip(samples,samples[1:]))
        self.assertAlmostEqual(700,crossings,delta=8)


if __name__=="__main__":
    unittest.main(verbosity=2)
