# Everbranch Studio for Windows

## Open the editor on John's PC

In File Explorer, open `G:\Everbranch\Studio` and double-click **start-studio.cmd**. The timeline opens in its own Edge window when Edge is installed. Choose **New episode** to start, or **Open episode** to select an existing `episode.json`. The project menu beside the Studio name lets you switch episodes. New projects are saved under `G:\Everbranch\Studio\projects`. Recordings, edits and exports stay in the episode folder.

## Set up another PC or update the installed editor

From the repository's `native\studio-windows` folder, run `.\install-local.ps1` in PowerShell. The installer copies the launcher, editor and publishing tools into the Studio library. Set `EVERBRANCH_STUDIO_HOME` to select a different library. Projects can live on another drive with enough room for the recordings and renders.

Install Python 3.11 or newer with Tkinter and FFmpeg with DirectShow, libx264, libass, drawtext and loudnorm. Put FFmpeg on PATH, set `EVERBRANCH_FFMPEG` to its executable, or place a portable distribution in the library at `tools/ffmpeg-*/bin/ffmpeg.exe`. The editor also recognizes Python's imageio-ffmpeg binary. Run **setup-captions.ps1** once for English transcription. It downloads a checksum-verified whisper.cpp build and model. Transcription and rendering then run locally.

## Record and edit an episode

1. Open **Episode settings** in the project menu to enter the title and description. Choose **public** for a Free Range Humanity release. Changes save locally.
2. Select **Record**, choose the Windows camera or capture card and microphone, and select **Stop recording** when finished. A second camera can record to a separate file. Clap once at the beginning to help align the tracks. To use existing footage, choose **Import media**. The adjacent **More import options** menu accepts voice tracks and music. In **Mix**, set each track's gain or mute. Use the recording inspector to adjust sync. The selected camera supplies the camera mic for each segment. Enabled separate voice tracks follow the synchronized recording time. Confirm music usage rights in **Studio menu → Advanced workspace** before rendering with music.
3. Select **Sequence** in the program monitor and press **Space** to play. Click the timeline ruler to position the playhead. Press **S** to split there, or choose the blade tool (**B**) and click the segment. Drag a segment edge to trim, or enter exact in and out times in the inspector.
4. **Right-click a video segment** to duplicate it, move it earlier or later, trim to the playhead or **Ripple delete** it. Ripple delete closes the gap in the sequence. **Right-click a recording or track** to inspect, rename or mute it. **Append to sequence** adds a video recording to the end. **Ctrl+Z** undoes an edit and **Ctrl+Y** redoes it. These edits leave the original recordings intact. On first playback, a ProRes MOV or an MKV recording may take a moment to open while Studio builds a local, seekable preview. Future playback reuses that preview, and the final render reads the original recording.
5. Open **Captions** to transcribe locally or import SRT/WebVTT. Select a cue to correct its text or timing. In **Social cuts**, choose **Suggest cuts** or **Add cut**, then review the source range, framing and captions for each short or trailer. For product suggestions, select **Product tags → Suggest from catalog** and open the maker's **Download catalog for Studio** JSON. In **Sponsor rundown**, import the episode's booking JSON. Use **Advanced workspace** for detailed tag and sponsorship placement.

### Remove pauses with Smart Split

Choose **Smart Split** above the timeline, then **Analyze gaps**. The defaults look for pauses of at least 0.8 seconds below −35 dB and keep 0.18 seconds at each edge. Analysis checks the selected camera's microphone and the active voice tracks so another speaker can keep a section in the program. Music is excluded from gap detection.

Use **Preview** to hear each proposed cut in context. Clear the boxes for pauses you want to keep and select **Remove selected**. Lengthen **Minimum pause** for fewer suggestions, or increase **Keep at each edge** if the edit clips a breath. **Ctrl+Z** restores the previous sequence.

## Render, review and publish to Free Range Humanity

1. Choose **Deliver → Render revision**. **Export** opens the same workspace. Wait for the draft revision to appear. **Review** opens its master MP4. **Files** opens the folder containing the episode MP3, captions and any trailer or shorts.
2. Check playback, the audio mix, captions and clips. Select **Approve** on that revision. Approval verifies the current edit and the exported file hashes. If you change the edit, render and review a fresh revision.
3. To release an approved public episode, choose **Publish to FRH** on its revision. Check the displayed episode title and revision UUID. Leave the previous-revision field empty for a new episode. To replace an existing episode, enter the UUID of the currently published revision, then select **Publish episode**. Wait for the published episode URL in the status bar. If the transfer is interrupted, retry the same revision.
4. Check the episode page and the audio enclosure in the existing feed. Distribution to connected video and social accounts is managed separately in Everbranch. [Free Range Humanity podcast](https://freerangehumanity.com/podcast) · [Public RSS feed](https://freerangehumanity.com/podcast/rss.xml) · [Distribution setup and operator commands](../../scripts/studio/DISTRIBUTION.md)

## Import Instagram, Facebook or YouTube Shorts

Choose **Social clip** in the media library, select its platform, then choose saved videos, SRT/WebVTT captions or creator-export ZIP parts. Select one clip for an eight-second preview, a caption-language choice or an optional original-post link. Ctrl-click or Shift-click for a batch. Studio matches captions by filename, copies selected originals into the project and adds each as an editable short with its own picture and sound. An empty episode uses the first imported clip as its master. Existing episodes keep their master and transcript. A repeated import skips identical media.

For Instagram and Facebook, request your own posts, Reels or videos in [Meta Accounts Center](https://accountscenter.facebook.com/info_and_permissions/dyi/). For YouTube Shorts, choose **Content → Download** in [YouTube Studio](https://support.google.com/youtube/answer/56100), or choose the YouTube videos category and ZIP format in [Google Takeout](https://takeout.google.com/). When an archive uses an unfamiliar folder name, select the downloaded video and sidecar directly. A post link records the source. A creator download supplies the media.

The picker reads recognized creator media folders and timed sidecars. It skips messages, photos, JSON, watch history and other account data. Batch limits are 100 selected clips, 2 GiB per video and 8 GiB selected per import. Import additional batches for larger libraries. Limits for a single ZIP part are 16 GiB and 25,000 entries.

## Remote guest recording

Serve **public/studio-guest/** on the Everbranch HTTPS host. Share **/studio-guest/** with a guest, or run it on localhost for same-PC testing. Each person can record their own camera/microphone, or audio alone. The browser durably saves each chunk as it arrives. Open the same URL in the same browser profile after interruption, choose **Prepare download**, then **Save recording**. Keep copies until the host has imported and verified the media. Clearing site data removes local chunks. IndexedDB capacity depends on the device. Storage exhaustion stops the recorder and preserves committed chunks.

The optional peer-to-peer call exchanges an invitation and answer through a private message. For restrictive networks use a host-provided STUN/TURN configuration or your usual calling app. Microphone and camera are enabled by user action. Guests deliver finished files using the team's existing file-sharing method. Studio can rebuild an index for interrupted playable WebM/MKV/fragmented MP4 containers while keeping the original file.

## Export and checks

Each render writes **exports/<revision UUID>/studio-package.json**. It contains the stable episode UUID, revision, description, visibility, approval, file sizes, SHA-256 hashes, product tags, sponsorship placements and clip paths. Master product tag times are relative to the delivered video including the intro. Clip tags and captions are relative to their clip. Sponsor placements include source time, final program time and the rendered file paths for linked social cuts. Sidecar captions use WebVTT. Source files and editor undo history are excluded from the delivery folder.

From the repository's `native/studio-windows` directory, run `py -3 -B -m unittest discover -v`. The suite renders real media, checks sequence edits and Smart Split, and exercises approval, feed publication and revision corrections with an offline receiver. To check an installation, run `.\start-studio.cmd --check-launch` in its PowerShell directory. Run **node test_guest.cjs** with the repository’s Playwright dependency available and Chrome installed to exercise the call and guest recording in two isolated browser contexts with fake devices.

The desktop uses FFmpeg and whisper.cpp. See [FFmpeg](https://ffmpeg.org/), [whisper.cpp](https://github.com/ggml-org/whisper.cpp), and the [caption model](https://huggingface.co/ggerganov/whisper.cpp).
