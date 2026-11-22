import copy
import http.client
import json
import os
import shutil
import tempfile
import threading
import unittest
from pathlib import Path
from unittest.mock import patch

import editor_server as editor
import media


class LocalWindowTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.home = Path(self.tmp.name)
        self.env = patch.dict(os.environ, {"EVERBRANCH_STUDIO_HOME":str(self.home)})
        self.env.start()
        self.addCleanup(self.env.stop)
        self.server = editor.make_server()
        self.thread = threading.Thread(target=self.server.serve_forever,daemon=True)
        self.thread.start()
        self.cookie = ""
        self.addCleanup(self.stop)
        self.call("GET","/api/state",expect=403)
        response = self.call("GET","/launch?key="+self.server.key,expect=303)[0]
        self.cookie = response.getheader("Set-Cookie").split(";")[0]

    def stop(self):
        self.server.shutdown()
        self.server.server_close()
        if self.server.editor.lockfile:
            self.server.editor.lockfile.close()

    def call(self,method,path,data=None,expect=200,headers=None):
        client = http.client.HTTPConnection("127.0.0.1",self.server.server_port,timeout=15)
        try:
            client.request(method,path,body=json.dumps(data).encode() if data is not None else None,headers={
                "X-Studio-Request":"1","Cookie":self.cookie,"Content-Type":"application/json",
                "Origin":"http://"+self.server.address, **(headers or {})})
            response=client.getresponse()
            content=response.read()
            self.assertEqual(response.status,expect,content[:300])
            return response,content
        finally:
            client.close()

    def test_authenticated_edits_clip_menu_and_smart_split_undo(self):
        self.call("POST","/api/command",{"action":"new_project","title":"Test cut"})
        self.call("POST","/api/command",{"action":"save_edit","title":"Wrong origin"},headers={"Origin":"https://example.test"},expect=403)
        session=self.server.editor
        project=copy.deepcopy(session.project)
        track={"id":"a"*32,"kind":"camera","label":"A","offset":0,"duration":10.0,"enabled":True,"audio":True,"video":True,"gain_db":0,"path":"originals/a.mp4"}
        (session.root/"originals").mkdir()
        movie = session.root / track["path"]
        media.run(["-f", "lavfi", "-i", "color=c=red:s=64x64:r=1:d=10", "-c:v", "libx264", "-pix_fmt", "yuv420p", str(movie)])
        project["sources"]=[track]
        project["edit"].update(camera=track["id"],end=10.0)
        session.save(project)
        seq=[{"camera":track["id"],"start":0,"end":4},{"camera":track["id"],"start":4,"end":10}]
        self.call("POST","/api/command",{"action":"save_sequence","segments":seq})
        with patch("sequence.analyze_gaps",return_value=[{"start":1,"end":2}]):
            self.call("POST","/api/command",{"action":"analyze_gaps"})
            import time
            for _ in range(100):
                if not session.busy: break
                time.sleep(.01)
        self.call("POST","/api/command",{"action":"apply_gaps","ranges":[{"start":1,"end":2}]})
        self.assertEqual(sum(c["end"]-c["start"] for c in session.project["sequence"]),9)
        self.call("POST","/api/command",{"action":"undo"})
        self.assertEqual(session.project["sequence"],seq)
        _,body=self.call("GET","/api/media/"+track["id"],expect=206,headers={"Range":"bytes=0-6"})
        self.assertEqual(body, movie.read_bytes()[:7])
        self.call("GET","/api/media/"+track["id"],expect=416,headers={"Range":f"bytes={movie.stat().st_size}-"})
        self.assertEqual(json.loads((session.root/"episode.json").read_text())["sequence"],seq)

    def test_closing_the_last_window_waits_for_pending_work(self):
        import time
        connection = http.client.HTTPConnection("127.0.0.1", self.server.server_port, timeout=5)
        connection.request("GET", "/api/window", headers={"Cookie": self.cookie})
        stream = connection.getresponse()
        self.assertEqual(stream.getheader("Content-Type"), "text/event-stream")
        self.assertEqual(stream.readline(), b": window open\n")
        self.assertEqual(self.server.window_count, 1)
        self.server.editor.busy = True
        watcher = threading.Thread(target=self.server.watch_windows, daemon=True)
        watcher.start()
        stream.close()
        connection.close()
        deadline = time.monotonic() + 5
        while self.server.window_count and time.monotonic() < deadline:
            time.sleep(.05)
        self.assertEqual(self.server.window_count, 0)
        self.server.quit_after = time.monotonic() - 1
        time.sleep(.55)
        self.assertTrue(self.thread.is_alive())
        self.server.editor.busy = False
        self.thread.join(3)
        self.assertFalse(self.thread.is_alive())

    def test_cut_two_gaps_across_reordered_sources(self):
        pieces=editor.cut_ranges([{"camera":"b","start":81,"end":85},{"camera":"a","start":2,"end":8}],[(1,2),(3,6)])
        self.assertEqual(pieces,[{"camera":"b","start":81,"end":82},{"camera":"b","start":83,"end":84},{"camera":"a","start":4,"end":8}])

if __name__=="__main__":
    unittest.main()
